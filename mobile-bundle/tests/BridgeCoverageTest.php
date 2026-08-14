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
    private const EXPECTED_METHODS = 54;

    public function testEveryUpstreamBridgeMethodIsWrapped(): void
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

        self::assertSame([], $missing, 'These native methods have no wrapper: '.implode(', ', $missing));
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
            preg_match_all("/nativephp_call\\('([A-Za-z.]+)'/", (string) file_get_contents($file), $matches);
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
