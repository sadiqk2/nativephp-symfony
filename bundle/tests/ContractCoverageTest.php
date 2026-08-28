<?php

declare(strict_types=1);

namespace Native\Symfony\Desktop\Tests;

use Native\Symfony\Desktop\EventBridge\EventFactory;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Guards the two halves of the wire contract against drift.
 *
 * The endpoint half parses the runtime's own express routers when they are
 * available, so a new upstream endpoint fails this test instead of being noticed
 * a release later. When the runtime sources are not checked out — CI, a consumer's
 * machine — those cases skip rather than fail, and the pinned counts below still
 * catch an accidental deletion on our side.
 */
final class ContractCoverageTest extends TestCase
{
    /**
     * Mount points from the runtime's api.ts.
     *
     * `testTheMountTableCoversEveryModuleTheRuntimeMounts` keeps this honest. Without it a
     * module upstream adds is simply not in the table, `parseRuntimeEndpoints` skips the
     * file, the pinned endpoint count still matches and every endpoint in it goes
     * uncovered while this test reports OK — which is the opposite of what it is for.
     */
    private const MOUNTS = [
        'alert' => 'alert', 'app' => 'app', 'autoUpdater' => 'auto-updater',
        'broadcasting' => 'broadcast', 'childProcess' => 'child-process',
        'clipboard' => 'clipboard', 'contextMenu' => 'context', 'debug' => 'debug',
        'dialog' => 'dialog', 'dock' => 'dock', 'globalShortcut' => 'global-shortcuts',
        'menu' => 'menu', 'menuBar' => 'menu-bar', 'notification' => 'notification',
        'powerMonitor' => 'power-monitor', 'process' => 'process',
        'progressBar' => 'progress-bar', 'screen' => 'screen', 'settings' => 'settings',
        'shell' => 'shell', 'system' => 'system', 'window' => 'window',
    ];

    private const EXPECTED_ENDPOINTS = 116;
    private const EXPECTED_EVENTS = 44;

    #[DataProvider('runtimeEndpoints')]
    public function testEveryRuntimeEndpointIsCalledSomewhere(string $method, string $path): void
    {
        self::assertTrue(
            $this->isReferenced($path),
            sprintf('No code calls %s %s — the bundle does not cover it.', $method, $path),
        );
    }

    public function testTheEndpointCountHasNotChanged(): void
    {
        $endpoints = self::parseRuntimeEndpoints();

        if ([] === $endpoints) {
            self::markTestSkipped('Runtime sources not available.');
        }

        // A change here is not necessarily a bug, but it must be a decision:
        // re-read the new endpoints and update CONTRACT.md before bumping this.
        self::assertCount(self::EXPECTED_ENDPOINTS, $endpoints);
    }

    public function testTheMountTableCoversEveryModuleTheRuntimeMounts(): void
    {
        $mounted = self::parseRuntimeMounts();

        if ([] === $mounted) {
            self::markTestSkipped('Runtime sources not available.');
        }

        self::assertSame(
            self::MOUNTS,
            $mounted,
            'api.ts mounts a different set of modules than MOUNTS lists, so some of them are being skipped silently.',
        );

        // PayloadKeyContractTest keeps its own copy and skips unknown modules the same way,
        // so the two tables have to stay the same table.
        self::assertSame(
            self::MOUNTS,
            (new \ReflectionClass(PayloadKeyContractTest::class))->getConstant('MOUNTS'),
            'The two contract tests no longer agree on the runtime\'s mount points.',
        );
    }

    public function testEveryRuntimeEventIsMapped(): void
    {
        self::assertCount(self::EXPECTED_EVENTS, EventFactory::knownEvents());
    }

    public function testMappedEventNamesUseTheRuntimesNamespace(): void
    {
        // The wire protocol is upstream's namespace, not ours. Renaming a local
        // class must not silently change the name we match on.
        foreach (array_keys(EventFactory::knownEvents()) as $wireName) {
            self::assertStringStartsWith('Native\Desktop\Events\\', $wireName);
        }
    }

    public function testEveryRuntimePushedEventNameIsMapped(): void
    {
        $dir = self::runtimeSourceDir();

        if (null === $dir) {
            self::markTestSkipped('Runtime sources not available.');
        }

        $pushed = [];

        foreach ($this->phpFilesIn($dir, 'ts') as $file) {
            preg_match_all(
                '/Native\\\\{1,2}Desktop\\\\{1,2}Events(?:\\\\{1,2}\w+)+/',
                (string) file_get_contents($file),
                $matches,
            );

            foreach ($matches[0] as $name) {
                $pushed[str_replace('\\\\', '\\', $name)] = true;
            }
        }

        $mapped = EventFactory::knownEvents();
        $unmapped = array_diff(array_keys($pushed), array_keys($mapped));

        self::assertSame([], array_values($unmapped), 'The runtime pushes events we do not map.');
    }

    /** @return iterable<string, array{string, string}> */
    public static function runtimeEndpoints(): iterable
    {
        $endpoints = self::parseRuntimeEndpoints();

        if ([] === $endpoints) {
            // A provider cannot skip, so yield one case that skips itself.
            yield 'runtime sources unavailable' => ['GET', '__skip__'];

            return;
        }

        foreach ($endpoints as [$method, $path]) {
            yield "{$method} {$path}" => [$method, $path];
        }
    }

    /** @return list<array{string, string}> */
    private static function parseRuntimeEndpoints(): array
    {
        $dir = self::runtimeSourceDir();

        if (null === $dir) {
            return [];
        }

        $endpoints = [];

        foreach (glob($dir.'/api/*.ts') ?: [] as $file) {
            $module = basename($file, '.ts');

            if (!isset(self::MOUNTS[$module])) {
                continue;
            }

            preg_match_all(
                "/router\\.(get|post|delete|put)\\('([^']*)'/",
                (string) file_get_contents($file),
                $matches,
                \PREG_SET_ORDER,
            );

            foreach ($matches as [, $method, $path]) {
                $endpoints[] = [
                    strtoupper($method),
                    self::MOUNTS[$module].('/' === $path ? '' : $path),
                ];
            }
        }

        return $endpoints;
    }

    /**
     * Module name → mount path, read out of api.ts's own `httpServer.use()` calls.
     *
     * @return array<string, string>
     */
    private static function parseRuntimeMounts(): array
    {
        $dir = self::runtimeSourceDir();

        if (null === $dir || !is_file($dir.'/api.ts')) {
            return [];
        }

        $source = (string) file_get_contents($dir.'/api.ts');

        // `import windowRoutes from './api/window.js'` ties the local name to the file, and
        // `httpServer.use('/api/window', windowRoutes)` ties it to the mount path.
        preg_match_all("/import\s+(\w+)\s+from\s+'\.\/api\/([\w-]+)\.js'/", $source, $imports, \PREG_SET_ORDER);

        $modules = [];

        foreach ($imports as [, $local, $module]) {
            $modules[$local] = $module;
        }

        preg_match_all("/httpServer\.use\(\s*'\/api\/([\w-]+)'\s*,\s*(\w+)\s*\)/", $source, $mounts, \PREG_SET_ORDER);

        $found = [];

        foreach ($mounts as [, $mount, $local]) {
            if (isset($modules[$local])) {
                $found[$modules[$local]] = $mount;
            }
        }

        ksort($found);

        return $found;
    }

    private static function runtimeSourceDir(): ?string
    {
        foreach ([
            __DIR__.'/../../upstream/np-desktop/resources/electron/electron-plugin/src/server',
            __DIR__.'/../../spike/app/nativephp/electron/electron-plugin/src/server',
        ] as $candidate) {
            if (is_dir($candidate.'/api')) {
                return $candidate;
            }
        }

        return null;
    }

    private function isReferenced(string $path): bool
    {
        if ('__skip__' === $path) {
            self::markTestSkipped('Runtime sources not available.');
        }

        $source = $this->bundleSource();

        // A :param route is called as its literal prefix plus a runtime value.
        if (str_contains($path, '/:')) {
            return str_contains($source, explode('/:', $path)[0].'/');
        }

        // An exact literal, optionally followed by a query string.
        return 1 === preg_match(
            '/[\'"]'.preg_quote($path, '/').'(\?[^\'"]*)?[\'"]/',
            $source,
        );
    }

    private function bundleSource(): string
    {
        static $source = null;

        if (null === $source) {
            $source = '';

            foreach ($this->phpFilesIn(__DIR__.'/../src', 'php') as $file) {
                $source .= file_get_contents($file);
            }
        }

        return $source;
    }

    /** @return list<string> */
    private function phpFilesIn(string $dir, string $extension): array
    {
        $files = [];
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS));

        /** @var \SplFileInfo $file */
        foreach ($iterator as $file) {
            if ($file->isFile() && $extension === $file->getExtension()) {
                $files[] = $file->getPathname();
            }
        }

        return $files;
    }
}
