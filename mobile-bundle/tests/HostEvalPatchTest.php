<?php

declare(strict_types=1);

namespace Native\Symfony\Mobile\Tests;

use Native\Symfony\Mobile\Runtime\MobileRuntimePatcher;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;

/**
 * The PHP the hosts compile into themselves, checked against the hosts' real sources.
 *
 * Retargeting the bootstrap paths is only half the boundary. In persistent mode — the
 * default, and the mode the whole mobile story is sold on — neither host executes a
 * file per request: `php_bridge.c` and `PHP.c` assemble the request preamble as a C
 * string literal and run it through `zend_eval_string`. Those literals name Laravel:
 * the boot check asks for `class_exists('Native\Mobile\Runtime')`, the dispatch calls
 * `Runtime::dispatch(\Illuminate\Http\Request::capture())`, console commands go through
 * `Runtime::artisan()` and teardown through `Runtime::shutdown()`.
 *
 * A Symfony application has none of those classes. The boot check therefore fails, the
 * host logs "bootstrap did NOT boot the runtime" and tears the interpreter down, and
 * our `dispatch.php` — which nothing in either host ever executes — sat unreached the
 * whole time. That is the gap this test exists to keep closed.
 *
 * It reads the hosts' own sources rather than a fixture, because a fixture of a C
 * string literal is a copy of the one thing that has to stay in step.
 */
final class HostEvalPatchTest extends TestCase
{
    /** Below this, the substitution is finding too little to be believed. */
    private const MINIMUM_SITES = 10;

    private string $project;
    private string $upstream;

    protected function setUp(): void
    {
        $this->upstream = \dirname(__DIR__, 2).'/upstream/np-mobile/resources';

        if (!is_dir($this->upstream)) {
            self::markTestSkipped('upstream/np-mobile is not checked out; clone it to check the host evaluations.');
        }

        $this->project = sys_get_temp_dir().'/np-mobile-host-'.bin2hex(random_bytes(4));
    }

    protected function tearDown(): void
    {
        (new Filesystem())->remove($this->project);
    }

    public function testTheAndroidHostStopsCallingLaravel(): void
    {
        $this->assertHostIsRetargeted(...$this->patchAndroid());
    }

    public function testTheIosHostStopsCallingLaravel(): void
    {
        $this->assertHostIsRetargeted(...$this->patchIos());
    }

    public function testPatchingTwiceChangesNothing(): void
    {
        [$path] = $this->patchAndroid();

        $once = (string) file_get_contents($path);

        $applied = (new MobileRuntimePatcher())->patchAndroid($this->project.'/android');

        self::assertSame($once, file_get_contents($path));
        self::assertNotSame([], array_filter($applied, static fn (string $line): bool => str_contains($line, 'already applied')));
    }

    /**
     * The rewritten PHP has to parse — and it has to still fit.
     *
     * Each preamble is built with snprintf into a fixed buffer, so a longer class name
     * is not free: overflow would truncate the literal mid-statement and every request
     * would die on a parse error inside an eval nobody can see.
     */
    public function testTheRewrittenPreamblesParseAndFitTheirBuffers(): void
    {
        foreach ([$this->patchAndroid(), $this->patchIos()] as [$path]) {
            $blocks = $this->evalBlocks((string) file_get_contents($path));

            self::assertGreaterThanOrEqual(3, \count($blocks), 'Found almost no eval blocks in '.basename($path));

            foreach ($blocks as [$buffer, $php]) {
                self::assertLessThan(
                    $buffer,
                    \strlen($php) + 1,
                    sprintf('An eval block in %s no longer fits its %d byte buffer.', basename($path), $buffer),
                );

                self::assertSame('', $this->lint($php), sprintf('An eval block in %s does not parse after the rewrite.', basename($path)));
            }
        }
    }

    public function testTheDispatchReachesTheSymfonyRuntime(): void
    {
        [$path] = $this->patchAndroid();

        $php = implode("\n", array_map(static fn (array $block): string => $block[1], $this->evalBlocks((string) file_get_contents($path))));

        self::assertStringContainsString(
            '$__response = \Native\Symfony\Mobile\Runtime\MobileRuntime::dispatch(',
            $php,
        );
        self::assertStringContainsString(
            '\Native\Symfony\Mobile\Runtime\ServerRequestFactory::fromServer($_SERVER)[0]',
            $php,
        );

        // Every symbol the rewritten preamble names has to exist on our side, or this
        // trades one missing class for another.
        self::assertTrue(method_exists(\Native\Symfony\Mobile\Runtime\MobileRuntime::class, 'dispatch'));
        self::assertTrue(method_exists(\Native\Symfony\Mobile\Runtime\MobileRuntime::class, 'artisan'));
        self::assertTrue(method_exists(\Native\Symfony\Mobile\Runtime\MobileRuntime::class, 'shutdown'));
        self::assertTrue(method_exists(\Native\Symfony\Mobile\Runtime\MobileRuntime::class, 'isBooted'));
    }

    // ── helpers ─────────────────────────────────────────────────────────────

    private function assertHostIsRetargeted(string $path, int $before): void
    {
        self::assertGreaterThanOrEqual(
            self::MINIMUM_SITES,
            $before,
            'The host source barely mentions the Laravel runtime, so this test is checking nothing.',
        );

        $patched = (string) file_get_contents($path);

        self::assertStringNotContainsString('\\\\Native\\\\Mobile\\\\Runtime', $patched);
        self::assertStringNotContainsString('Illuminate', $patched);
        self::assertSame(
            $before,
            substr_count($patched, 'Native\\\\Symfony\\\\Mobile\\\\Runtime\\\\MobileRuntime')
                + substr_count($patched, 'Native\\\\\\\\Symfony\\\\\\\\Mobile\\\\\\\\Runtime\\\\\\\\MobileRuntime')
                + substr_count($patched, 'ServerRequestFactory'),
            'Every Laravel reference should have become exactly one Symfony reference.',
        );
    }

    /** @return array{0: string, 1: int} The patched file, and how many sites it had */
    private function patchAndroid(): array
    {
        $fs = new Filesystem();
        $root = $this->project.'/android';

        $fs->copy(
            $this->upstream.'/androidstudio/app/src/main/cpp/php_bridge.c',
            $root.'/app/src/main/cpp/php_bridge.c',
        );
        $fs->copy(
            $this->upstream.'/androidstudio/app/src/main/java/com/nativephp/mobile/bridge/PHPBridge.kt',
            $root.'/app/src/main/java/com/nativephp/mobile/bridge/PHPBridge.kt',
        );

        $path = $root.'/app/src/main/cpp/php_bridge.c';
        $before = $this->laravelReferences((string) file_get_contents($path));

        (new MobileRuntimePatcher())->patchAndroid($root);

        return [$path, $before];
    }

    /** @return array{0: string, 1: int} */
    private function patchIos(): array
    {
        $fs = new Filesystem();
        $root = $this->project.'/ios';

        $fs->copy($this->upstream.'/xcode/Include/Bridge/PHP.c', $root.'/Include/Bridge/PHP.c');
        $fs->copy($this->upstream.'/xcode/NativePHP/NativePHPApp.swift', $root.'/NativePHP/NativePHPApp.swift');

        $path = $root.'/Include/Bridge/PHP.c';
        $before = $this->laravelReferences((string) file_get_contents($path));

        (new MobileRuntimePatcher())->patchIos($root);

        return [$path, $before];
    }

    /** How many times the source names a class only Laravel provides. */
    private function laravelReferences(string $source): int
    {
        return substr_count($source, '\\\\Native\\\\Mobile\\\\Runtime')
            + substr_count($source, "'Native\\\\\\\\Mobile\\\\\\\\Runtime'")
            + substr_count($source, '\\\\Illuminate\\\\Http\\\\Request::capture()');
    }

    /**
     * Reassemble each `snprintf(eval_code, …)` block back into the PHP it produces.
     *
     * @return list<array{0: int, 1: string}> Buffer size, and the PHP that goes in it
     */
    private function evalBlocks(string $source): array
    {
        $lines = explode("\n", $source);
        $blocks = [];
        $buffer = null;
        $collecting = false;
        $php = '';

        foreach ($lines as $line) {
            $trimmed = trim($line);

            if (preg_match('/char eval_code\[(\d+)\]/', $trimmed, $m)) {
                $buffer = (int) $m[1];

                continue;
            }

            if (str_starts_with($trimmed, 'snprintf(eval_code')) {
                $collecting = true;
                $php = '';

                continue;
            }

            if (!$collecting) {
                continue;
            }

            if (str_starts_with($trimmed, '"')) {
                $php .= $this->unescape(substr($trimmed, 1, strrpos($trimmed, '"') - 1));

                continue;
            }

            // Upstream leaves blank lines between fragments of the same literal;
            // treating one as the end of the block silently truncated the PHP being
            // checked, which is how this helper first "proved" a parse error that was
            // its own.
            if ('' === $trimmed) {
                continue;
            }

            $collecting = false;

            if (null !== $buffer && '' !== $php) {
                $blocks[] = [$buffer, $php];
            }
        }

        return $blocks;
    }

    /** C string escapes, as the compiler resolves them. */
    private function unescape(string $literal): string
    {
        $out = '';

        for ($i = 0; $i < \strlen($literal); ++$i) {
            if ('\\' !== $literal[$i]) {
                $out .= $literal[$i];

                continue;
            }

            $next = $literal[++$i] ?? '';

            $out .= match ($next) {
                'n' => "\n",
                'r' => "\r",
                't' => "\t",
                '0' => "\0",
                default => $next,
            };
        }

        return $out;
    }

    /** @return string The parse error, or '' when the snippet is valid PHP */
    private function lint(string $php): string
    {
        // The preambles are printf templates; the placeholders stand where a method,
        // a URI or a path goes, all of them inside PHP string literals.
        $php = str_replace(['%s', '%d'], ['placeholder', '0'], $php);

        $file = $this->project.'/lint-'.bin2hex(random_bytes(4)).'.php';
        (new Filesystem())->dumpFile($file, "<?php\n".$php);

        $descriptors = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $process = proc_open([\PHP_BINARY, '-l', $file], $descriptors, $pipes);

        self::assertIsResource($process);

        $out = (string) stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        return 0 === proc_close($process) ? '' : $out;
    }
}
