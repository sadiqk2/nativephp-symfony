<?php

declare(strict_types=1);

namespace Native\Symfony\Mobile\Tests;

use PHPUnit\Framework\TestCase;

/**
 * Guards the bridge surface against upstream drift, the way the desktop bundle's
 * ContractCoverageTest guards the HTTP API: parse the methods the upstream PHP
 * actually calls, and assert this package wraps every one.
 *
 * Skips when the upstream sources are not checked out, so the pinned count below
 * is what catches an accidental deletion here.
 */
final class BridgeCoverageTest extends TestCase
{
    private const int EXPECTED_METHODS = 57;

    /**
     * Upstream methods with no wrapper here, named rather than left to a count that
     * happens to agree.
     *
     * Empty, and meant to stay that way. It held Dialog.Alert, Scanner.Scan and
     * PushNotification.RequestPermission: all three are called as
     * `nativephp_call(\n    'Method',` — the argument on its own line — so the parser
     * that required the quote immediately after the parenthesis never discovered them,
     * the count read 54, and a test called "every upstream bridge method is wrapped"
     * passed while three were not. The parser was widened first; the wrappers followed.
     */
    private const array UNWRAPPED = [];

    public function testTheOnlyUnwrappedBridgeMethodsAreTheOnesNamedHere(): void
    {
        $upstream = $this->upstreamMethods();

        if ([] === $upstream) {
            self::markTestSkipped('Upstream mobile sources not available.');
        }

        $source = $this->bundleSource();
        $missing = array_values(array_filter(
            $upstream,
            static fn (string $method): bool => !str_contains($source, "'".$method."'"),
        ));

        // Both directions: a method upstream adds and nothing here wraps fails, and so
        // does one that gets wrapped without being struck off this list.
        self::assertSame(self::UNWRAPPED, $missing, 'The set of unwrapped native methods has changed: '.implode(', ', $missing));
    }

    public function testTheMethodCountHasNotChanged(): void
    {
        $upstream = $this->upstreamMethods();

        if ([] === $upstream) {
            self::markTestSkipped('Upstream mobile sources not available.');
        }

        // A change is not necessarily a bug, but it must be a decision: read the new
        // methods and update MOBILE-ANALYSIS.md before bumping this.
        self::assertCount(self::EXPECTED_METHODS, $upstream);
    }

    /** @return list<string> */
    private function upstreamMethods(): array
    {
        $dir = __DIR__.'/../../upstream/np-mobile/src';

        if (!is_dir($dir)) {
            return [];
        }

        $methods = [];

        foreach ($this->phpFiles($dir) as $file) {
            // `\s*` is load-bearing: three upstream calls put the method name on its own
            // line, and without it they were invisible to this whole test.
            preg_match_all("/nativephp_call\\(\\s*'([A-Za-z.]+)'/", (string) file_get_contents($file), $matches);
            foreach ($matches[1] as $method) {
                $methods[$method] = true;
            }
        }

        $names = array_keys($methods);
        sort($names);

        return $names;
    }

    private function bundleSource(): string
    {
        $source = '';

        foreach ($this->phpFiles(__DIR__.'/../src') as $file) {
            $source .= file_get_contents($file);
        }

        return $source;
    }

    /** @return list<string> */
    private function phpFiles(string $dir): array
    {
        $files = [];
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS));

        /** @var \SplFileInfo $file */
        foreach ($it as $file) {
            if ($file->isFile() && 'php' === $file->getExtension()) {
                $files[] = $file->getPathname();
            }
        }

        return $files;
    }
}
