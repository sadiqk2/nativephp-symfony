<?php

declare(strict_types=1);

namespace Native\Symfony\Desktop\Tests;

use Native\Symfony\Desktop\Manifest\Manifest;
use Native\Symfony\Desktop\Manifest\ManifestSupport;
use Native\Symfony\Desktop\Manifest\ManifestSupportDetector;
use Native\Symfony\Desktop\Manifest\ManifestWriter;
use Native\Symfony\Desktop\Runtime\RuntimePatcher;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;

/**
 * Pins the emitted manifest against upstream patch 0001 by parsing the patch.
 *
 * Hardcoding the expected shape here would pin this test to itself: the emitter
 * and the expectation would drift together and stay green while the runtime that
 * has to read the file disagreed with both. So the schema is read out of
 * `upstream-patches/0001-manifest-declared-app-paths.patch` — the same text that
 * will be reviewed upstream — and the assertions are about agreement between the
 * two. If the patch changes shape, or is amended during review, these tests fail
 * and say which key moved.
 */
final class ManifestTest extends TestCase
{
    private const string PATCH = __DIR__.'/../../upstream-patches/0001-manifest-declared-app-paths.patch';

    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir().'/np-manifest-'.bin2hex(random_bytes(6));
    }

    protected function tearDown(): void
    {
        (new Filesystem())->remove($this->root);
    }

    // --- schema agreement with patch 0001 ---------------------------------------

    public function testEveryKeyItEmitsExistsInThePatchesAppManifestInterface(): void
    {
        $declared = array_keys((new Manifest())->toArray());

        self::assertSame(
            $this->sorted($this->interfaceProperties()),
            $this->sorted($declared),
            'The manifest and the AppManifest interface in patch 0001 no longer describe the same object.',
        );
    }

    /**
     * The runtime merges a declared manifest over Laravel's defaults one level deep,
     * so an omitted key inherits a Laravel value rather than meaning "none". Every
     * key Laravel declares must therefore be declared here too — including the ones
     * whose Symfony answer is "nothing".
     */
    public function testItOmitsNoKeyThatWouldThenInheritALaravelDefault(): void
    {
        $declared = (new Manifest())->toArray();
        $laravelKeys = $this->laravelDefaultKeys();

        // Guard against a vacuous pass: an empty parse would satisfy the loop below.
        self::assertNotEmpty($laravelKeys, 'Failed to parse laravelManifest out of patch 0001.');

        foreach ($laravelKeys as $key) {
            self::assertArrayHasKey(
                $key,
                $declared,
                sprintf('"%s" is absent, so the runtime will silently use Laravel\'s value for it.', $key),
            );
        }
    }

    public function testTheNestedObjectsHaveExactlyThePatchesKeys(): void
    {
        $declared = (new Manifest())->toArray();

        self::assertSame(
            $this->sorted($this->inlineObjectKeys('env')),
            $this->sorted(array_keys($declared['env'])),
        );

        self::assertSame(
            $this->sorted($this->inlineObjectKeys('lifecycle')),
            $this->sorted(array_keys($declared['lifecycle'])),
        );
    }

    public function testItEmitsSymfonysValuesRatherThanTheLaravelDefaultsInThePatch(): void
    {
        $declared = (new Manifest())->toArray();
        $laravel = $this->laravelScalarDefaults();

        self::assertNotSame($laravel['cli'], $declared['cli']);
        self::assertNotSame($laravel['router'], $declared['router']);
        self::assertNotSame($laravel['env.dev'], $declared['env']['dev']);
        self::assertNotSame($laravel['env.prod'], $declared['env']['prod']);
        self::assertNotSame($laravel['seedDir'], $declared['seedDir']);

        // docroot is the one value Symfony and Laravel genuinely agree on, so it is
        // asserted equal rather than merely different — a change there is upstream
        // renaming the concept, which we would want to hear about.
        self::assertSame($laravel['docroot'], $declared['docroot']);

        self::assertSame('bin/console', $declared['cli']);
        self::assertSame('public/nativephp-router.php', $declared['router']);
        // Symfony 8 throws on an unknown APP_ENV, so `dev`/`prod` are not a
        // preference — see ANALYSIS.md §6 item 8.
        self::assertSame(['dev' => 'dev', 'prod' => 'prod'], $declared['env']);
        self::assertSame(['var/cache', 'var/log'], $declared['writableDirs']);
        self::assertNull($declared['seedDir']);
        self::assertSame([], $declared['cacheEnv']);
    }

    /**
     * The reason `optimize`/`migrate` use `[]` for "none" while `schedule` uses
     * `null`: only `schedule` is guarded in the patch. Spreading a null into the
     * other two throws inside serveApp and kills the boot; an empty array passed to
     * the guarded one is truthy in JavaScript and would spawn a bare CLI every 60
     * seconds.
     *
     * If upstream guards all three, this test fails — and Manifest::toArray() can
     * then be simplified to emit null throughout.
     */
    public function testTheLifecycleEncodingStillMatchesHowThePatchConsumesIt(): void
    {
        $added = $this->addedLines();

        // All three must be read into a local and checked before being spread. An
        // earlier revision asserted `...lifecycle.optimize!` non-null, so declaring null
        // — which the type permitted — became `...null` and threw inside serveApp(),
        // before the PHP server started. If any of these guards disappears, emitting
        // null for that entry silently becomes a boot crash.
        foreach (['optimize', 'migrate', 'schedule'] as $entry) {
            self::assertStringContainsString("const {$entry} = getManifest().lifecycle.{$entry};", $added,
                "{$entry} is not read into a local, so it cannot be guarded before spreading.");
            self::assertStringNotContainsString("lifecycle.{$entry}!", $added,
                "{$entry} is spread with a non-null assertion again; emitting null for it would crash the runtime.");
        }

        self::assertStringContainsString('if (!schedule) {', $added, 'schedule is no longer guarded.');

        // With all three guarded, "none" is expressible as null uniformly.
        $lifecycle = (new Manifest(optimize: null, migrate: null, schedule: null))->toArray()['lifecycle'];

        self::assertNull($lifecycle['optimize']);
        self::assertNull($lifecycle['migrate']);
        self::assertNull($lifecycle['schedule']);
    }

    public function testTheRouterItDeclaresIsTheOneTheBundleInstalls(): void
    {
        // InstallCommand writes public/nativephp-router.php; a manifest pointing
        // anywhere else is a 404 on every route with nothing in the log.
        self::assertFileExists(__DIR__.'/../src/Resources/runtime/nativephp-router.php');
        self::assertSame('public/nativephp-router.php', (new Manifest())->toArray()['router']);
    }

    // --- encoding ---------------------------------------------------------------

    public function testItEncodesAnEmptyCacheEnvAsAnObject(): void
    {
        $json = (new Manifest())->toJson();

        self::assertStringContainsString('"cacheEnv": {}', $json);
        self::assertStringEndsWith("\n", $json);
        // Unescaped slashes, so the paths are readable in a committed file.
        self::assertStringContainsString('"router": "public/nativephp-router.php"', $json);

        $decoded = json_decode($json, true, flags: \JSON_THROW_ON_ERROR);
        self::assertSame((new Manifest())->toArray(), $decoded);
    }

    public function testItEncodesAPopulatedCacheEnvAsIs(): void
    {
        $json = (new Manifest(cacheEnv: ['APP_CONFIG_CACHE' => 'config.php']))->toJson();

        self::assertSame(
            ['APP_CONFIG_CACHE' => 'config.php'],
            json_decode($json, true, flags: \JSON_THROW_ON_ERROR)['cacheEnv'],
        );
    }

    // --- writer -----------------------------------------------------------------

    public function testItWritesTheManifestIntoTheProjectRoot(): void
    {
        (new Filesystem())->mkdir($this->root);
        $writer = new ManifestWriter($this->root, new Manifest());

        self::assertFalse($writer->exists());
        self::assertTrue($writer->write(), 'The first write creates the file.');
        self::assertSame($this->root.'/nativephp.json', $writer->path());
        self::assertSame((new Manifest())->toJson(), file_get_contents($writer->path()));
        self::assertTrue($writer->exists());
    }

    public function testItReportsAnUnchangedManifestAsUnchanged(): void
    {
        (new Filesystem())->mkdir($this->root);
        $writer = new ManifestWriter($this->root, new Manifest());
        $writer->write();

        self::assertFalse($writer->write(), 'A byte-identical rewrite must not be reported as a change.');
    }

    public function testItRewritesAStaleManifest(): void
    {
        (new Filesystem())->dumpFile($this->root.'/nativephp.json', '{"cli":"artisan"}');

        self::assertTrue(new ManifestWriter($this->root, new Manifest())->write());
        self::assertStringContainsString('bin/console', (string) file_get_contents($this->root.'/nativephp.json'));
    }

    // --- detection --------------------------------------------------------------

    public function testItDetectsAManifestAwareRuntime(): void
    {
        $this->scaffoldRuntime("const p = join(getAppPath(), 'nativephp.json');\nfunction getManifest() {}\n");

        self::assertSame(ManifestSupport::Supported, (new ManifestSupportDetector())->detect($this->root));
    }

    public function testItDetectsALaravelHardcodedRuntime(): void
    {
        $this->scaffoldRuntime("const command = ['artisan', 'native:config'];\n");

        self::assertSame(ManifestSupport::Absent, (new ManifestSupportDetector())->detect($this->root));
    }

    public function testAPartiallyManifestAwareRuntimeIsUnknownRatherThanGuessed(): void
    {
        $this->scaffoldRuntime("function getManifest() { return laravelManifest; }\n");

        self::assertSame(ManifestSupport::Unknown, (new ManifestSupportDetector())->detect($this->root));
    }

    public function testAnUnreadableRuntimeIsUnknown(): void
    {
        self::assertSame(ManifestSupport::Unknown, (new ManifestSupportDetector())->detect($this->root.'/nope'));
    }

    public function testItFallsBackToTheBuiltPluginWhenThereIsNoSource(): void
    {
        (new Filesystem())->dumpFile(
            $this->root.'/electron-plugin/dist/server/php.js',
            "function getManifest() { readFileSync(join(getAppPath(), 'nativephp.json')); }\n",
        );

        self::assertSame(ManifestSupport::Supported, (new ManifestSupportDetector())->detect($this->root));
    }

    public function testTheSourceWinsOverAStaleBuild(): void
    {
        // native:install rebuilds dist from src, so src decides what the runtime
        // will actually do.
        $this->scaffoldRuntime("const command = ['artisan', 'native:config'];\n");
        (new Filesystem())->dumpFile(
            $this->root.'/electron-plugin/dist/server/php.js',
            "getManifest(); // nativephp.json\n",
        );

        self::assertSame(ManifestSupport::Absent, (new ManifestSupportDetector())->detect($this->root));
    }

    // --- patching becomes conditional -------------------------------------------

    public function testThePatcherLeavesAManifestAwareRuntimeAlone(): void
    {
        $source = "const p = 'nativephp.json'; function getManifest() {}\n";
        $this->scaffoldRuntime($source);

        $applied = (new RuntimePatcher())->patch($this->root);

        self::assertSame($source, file_get_contents($this->root.'/electron-plugin/src/server/php.ts'));
        // Only the Laravel-isms are skipped. The bug fixes still apply: a
        // manifest-aware runtime is not a fixed one, and the two are orthogonal.
        self::assertStringContainsString('skipped the Laravel-ism patches', $applied[0]);
        self::assertStringContainsString('native:manifest', $applied[1]);
    }

    // --- patch parsing ----------------------------------------------------------

    /** Only the lines patch 0001 adds, with the diff markers stripped. */
    private function addedLines(): string
    {
        if (!is_file(self::PATCH)) {
            self::markTestSkipped('upstream-patches/0001 is not available next to this bundle.');
        }

        $added = [];

        foreach (explode("\n", (string) file_get_contents(self::PATCH)) as $line) {
            if (str_starts_with($line, '+') && !str_starts_with($line, '+++')) {
                $added[] = substr($line, 1);
            }
        }

        return implode("\n", $added);
    }

    /**
     * Property names of the patch's `AppManifest` interface, in declaration order.
     *
     * Only top-level properties: the nested `env`/`lifecycle` shapes are written
     * inline on one line, so anchoring at the start of a line skips them.
     *
     * @return list<string>
     */
    private function interfaceProperties(): array
    {
        $body = $this->block('/^interface AppManifest \{$(.*?)^\}$/ms');

        // Exactly four spaces: the nested lifecycle entries are indented further, and
        // counting them as top-level properties would demand keys the object has never
        // had.
        preg_match_all('/^ {4}(\w+)\??:/m', $body, $matches);

        return $matches[1];
    }

    /** @return list<string> */
    private function laravelDefaultKeys(): array
    {
        $body = $this->block('/^const laravelManifest: AppManifest = \{$(.*?)^\};$/ms');

        // Top-level only: nested entries are indented further than four spaces.
        preg_match_all('/^ {4}(\w+):/m', $body, $matches);

        return $matches[1];
    }

    /**
     * Keys of an inline object property in the interface, e.g. `env: { dev: …; prod: … }`.
     *
     * @return list<string>
     */
    private function inlineObjectKeys(string $property): array
    {
        if (!preg_match('/^\s+'.preg_quote($property, '/').'\??: \{([^}]*)\}/m', $this->addedLines(), $m)) {
            self::fail(sprintf('Patch 0001 no longer declares an inline "%s" object.', $property));
        }

        preg_match_all('/(\w+)\??:/', $m[1], $matches);

        return $matches[1];
    }

    /**
     * The Laravel scalar defaults the patch preserves, which are exactly the values
     * a Symfony app must override.
     *
     * @return array<string, string>
     */
    private function laravelScalarDefaults(): array
    {
        $body = $this->block('/^const laravelManifest: AppManifest = \{$(.*?)^\};$/ms');
        $found = [];

        foreach (['cli', 'router', 'docroot', 'seedDir'] as $key) {
            if (!preg_match('/^ {4}'.$key.": '([^']*)'/m", $body, $m)) {
                self::fail(sprintf('Patch 0001 no longer declares a default for "%s".', $key));
            }

            $found[$key] = $m[1];
        }

        if (!preg_match("/^ {4}env: \{ dev: '([^']*)', prod: '([^']*)' \}/m", $body, $m)) {
            self::fail('Patch 0001 no longer declares default env names.');
        }

        $found['env.dev'] = $m[1];
        $found['env.prod'] = $m[2];

        return $found;
    }

    private function block(string $pattern): string
    {
        if (!preg_match($pattern, $this->addedLines(), $matches)) {
            self::fail(sprintf('Patch 0001 no longer contains a block matching %s.', $pattern));
        }

        return $matches[1];
    }

    // --- helpers ----------------------------------------------------------------

    private function scaffoldRuntime(string $phpTs): void
    {
        (new Filesystem())->dumpFile($this->root.'/electron-plugin/src/server/php.ts', $phpTs);
    }

    /**
     * @param list<string> $values
     *
     * @return list<string>
     */
    private function sorted(array $values): array
    {
        sort($values);

        return $values;
    }
}
