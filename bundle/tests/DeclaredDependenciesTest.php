<?php

declare(strict_types=1);

namespace Native\Symfony\Desktop\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Everything `src/` imports has to be a package this bundle declares.
 *
 * The failure this catches is invisible at install time and total at run time:
 * `composer require native-symfony/desktop-bundle` succeeds, the class is there because
 * something else in the tree happened to pull the component in, and the feature dies with
 * a class-not-found the first time anyone reaches it. That is the shape the undeclared
 * `ext-zip` had, and the shape `symfony/routing` had in the mobile bundle, where an install
 * satisfying exactly the declared `require` set had no routing component at all.
 *
 * Here the two gaps were the contracts packages — every event class extends
 * `Symfony\Contracts\EventDispatcher\Event` and the client type-hints
 * `Symfony\Contracts\HttpClient\HttpClientInterface`, and neither was named. Both arrive
 * today only because `symfony/event-dispatcher` and `symfony/http-client` require them, so
 * this bundle's code depends on someone else's dependency list not changing.
 *
 * `require-dev` and `suggest` count as declaring a package too: `phpunit/phpunit` for the
 * testing kit and `symfony/security-http` for the firewall exemption are genuinely optional,
 * and naming them is what makes the requirement visible to a consumer.
 *
 * Imports are read from tokens rather than by grepping, so a name is only counted when PHP
 * itself would have to resolve it: a `use` statement or a fully-qualified reference. Class
 * names that only ever appear inside string literals are not counted — the runtime event
 * simulator builds upstream event *names* that look like FQCNs and are never autoloaded.
 */
final class DeclaredDependenciesTest extends TestCase
{
    /**
     * Vendor namespace roots that the tree may reach into. An import under any other root
     * fails {@see testEveryImportedNamespaceMapsToAKnownPackage} rather than being ignored,
     * so a new dependency cannot arrive unnoticed.
     */
    private const VENDOR_ROOTS = ['Symfony', 'Psr', 'Twig', 'PHPUnit'];

    /**
     * `Native\Symfony\Desktop\*` is this bundle. `App\*` is the consuming application's own
     * namespace, which nothing here provides.
     */
    private const OWN_ROOTS = ['Native', 'App'];

    #[DataProvider('importedNamespaces')]
    public function testEveryImportedNamespaceIsADeclaredDependency(string $namespace, string $usedBy): void
    {
        $package = self::packageFor($namespace);
        $composer = self::composer();

        $declared = array_merge(
            array_keys($composer['require'] ?? []),
            array_keys($composer['require-dev'] ?? []),
            array_keys($composer['suggest'] ?? []),
        );

        self::assertContains($package, $declared, sprintf(
            '%s is used by %s but bundle/composer.json never names %s. '.
            'An install that satisfies only the declared dependencies cannot load that code.',
            $namespace,
            $usedBy,
            $package,
        ));
    }

    #[DataProvider('importedNamespaces')]
    public function testEveryImportedNamespaceMapsToAKnownPackage(string $namespace, string $usedBy): void
    {
        self::assertNotSame('', self::packageFor($namespace), $namespace.' (used by '.$usedBy.')');
    }

    /**
     * The discovery has to keep finding things, or both tests above pass by finding nothing —
     * the way the contract tests passed while their providers were empty.
     */
    public function testTheImportScanFindsTheKnownDependencies(): void
    {
        $found = array_keys(iterator_to_array(self::importedNamespaces()));

        self::assertGreaterThanOrEqual(10, count($found));

        foreach (['Symfony\Component\HttpKernel', 'Symfony\Contracts\EventDispatcher', 'Psr\Log'] as $expected) {
            self::assertContains($expected, $found);
        }
    }

    /** @return iterable<string, array{string, string}> */
    public static function importedNamespaces(): iterable
    {
        $src = \dirname(__DIR__).'/src';
        $found = [];

        /** @var \SplFileInfo $file */
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($src)) as $file) {
            if (!$file->isFile()) {
                continue;
            }

            $contents = (string) file_get_contents($file->getPathname());

            // The boot shims are `.php` too, but Resources holds templates and configs as well.
            if ('php' !== $file->getExtension() && !str_starts_with($contents, '<?php')) {
                continue;
            }

            $relative = str_replace($src.'/', '', $file->getPathname());

            foreach (self::importsIn($contents) as $name) {
                $namespace = self::namespaceKey($name);

                if (null !== $namespace) {
                    $found[$namespace][$relative] = true;
                }
            }
        }

        ksort($found);

        foreach ($found as $namespace => $files) {
            $names = array_keys($files);
            // Named, not counted: a failure has to say where to look, and one namespace can
            // be imported by fifty files without the fifty being any more informative.
            $shown = implode(', ', \array_slice($names, 0, 3));

            yield $namespace => [$namespace, \count($names) > 3 ? $shown.' and '.(\count($names) - 3).' more' : $shown];
        }
    }

    /**
     * Every name in a file that the autoloader would have to resolve: the target of a `use`,
     * and any name written out fully qualified.
     *
     * @return list<string>
     */
    private static function importsIn(string $contents): array
    {
        $tokens = token_get_all($contents);
        $names = [];
        $inUse = false;

        foreach ($tokens as $token) {
            if (\is_string($token)) {
                if (';' === $token || '{' === $token) {
                    $inUse = false;
                }

                continue;
            }

            if (\T_USE === $token[0]) {
                $inUse = true;

                continue;
            }

            if (\T_NAME_FULLY_QUALIFIED === $token[0]) {
                $names[] = ltrim($token[1], '\\');

                continue;
            }

            // A relative name inside a `use` is still absolute — that is what `use` means.
            if ($inUse && \T_NAME_QUALIFIED === $token[0]) {
                $names[] = $token[1];
            }
        }

        return $names;
    }

    /**
     * The part of a class name that decides which package provides it, or null when the name
     * belongs to this bundle or to the application.
     */
    private static function namespaceKey(string $name): ?string
    {
        $parts = explode('\\', $name);

        if (\count($parts) < 2 || \in_array($parts[0], self::OWN_ROOTS, true)) {
            return null;
        }

        // Symfony splits one namespace level deeper than its vendor name, and Security splits
        // one deeper again: Symfony\Component\Security\Http is symfony/security-http.
        if ('Symfony' === $parts[0] && isset($parts[2])) {
            if ('Security' === $parts[2] && isset($parts[3])) {
                return implode('\\', \array_slice($parts, 0, 4));
            }

            return implode('\\', \array_slice($parts, 0, 3));
        }

        return $parts[0].'\\'.$parts[1];
    }

    /** The composer package providing a namespace, or '' when the mapping does not know it. */
    private static function packageFor(string $namespace): string
    {
        $parts = explode('\\', $namespace);

        if (!\in_array($parts[0], self::VENDOR_ROOTS, true)) {
            return '';
        }

        if ('Symfony' === $parts[0]) {
            if ('Component' === $parts[1] && 'Security' === $parts[2]) {
                return 'symfony/security-'.self::kebab($parts[3]);
            }

            if ('Component' === $parts[1]) {
                return 'symfony/'.self::kebab($parts[2]);
            }

            if ('Contracts' === $parts[1]) {
                return 'symfony/'.self::kebab($parts[2]).'-contracts';
            }

            if ('Bundle' === $parts[1] && str_ends_with($parts[2], 'Bundle')) {
                return 'symfony/'.self::kebab(substr($parts[2], 0, -6)).'-bundle';
            }

            return '';
        }

        if ('Psr' === $parts[0]) {
            return 'psr/'.self::kebab($parts[1]);
        }

        return 'Twig' === $parts[0] ? 'twig/twig' : 'phpunit/phpunit';
    }

    private static function kebab(string $studly): string
    {
        return strtolower((string) preg_replace('/(?<!^)[A-Z]/', '-$0', $studly));
    }

    /** @return array<string, mixed> */
    private static function composer(): array
    {
        /** @var array<string, mixed> $decoded */
        $decoded = json_decode((string) file_get_contents(\dirname(__DIR__).'/composer.json'), true, 512, \JSON_THROW_ON_ERROR);

        return $decoded;
    }
}
