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
    /** Mount points from the runtime's api.ts. */
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
