<?php

declare(strict_types=1);

namespace Native\Symfony\Mobile\Ui\Routing;

/**
 * Fills a {@see NativeRouteRegistry} from `#[NativeScreen]` attributes.
 *
 * Discovery is explicit — a list of classes, or a PSR-4 directory — rather than a scan of
 * everything autoloadable, because the manifest is only correct if discovery is
 * *deterministic*: the patterns baked into the app at build time and the patterns the
 * running app registers have to be the same list, produced by the same walk. A discovery
 * mechanism that depends on which classes happen to be loaded would produce a shorter list
 * during a build than at runtime, and the difference boots screens into a WebView.
 *
 * Reflection, not the tokenizer: a `#[NativeScreen]` on a parent class or a trait method has
 * to resolve the same way Symfony's own attribute loaders resolve `#[Route]`, and only
 * reflection sees through those. The cost is that discovery loads the classes it inspects,
 * which for a build-time command is fine.
 */
final class NativeScreenAttributeLoader
{
    public function __construct(private readonly NativeRouteRegistry $registry)
    {
    }

    /**
     * @param iterable<class-string> $classes
     *
     * @return list<NativeRoute> Routes added, in declaration order
     */
    public function loadClasses(iterable $classes): array
    {
        $added = [];

        foreach ($classes as $class) {
            foreach ($this->loadClass($class) as $route) {
                $added[] = $route;
            }
        }

        return $added;
    }

    /**
     * @param class-string $class
     *
     * @return list<NativeRoute>
     */
    public function loadClass(string $class): array
    {
        $reflection = new \ReflectionClass($class);
        $added = [];

        foreach ($reflection->getAttributes(NativeScreen::class, \ReflectionAttribute::IS_INSTANCEOF) as $attribute) {
            $screen = $attribute->newInstance();

            // A class-level declaration means "the class *is* the screen". An abstract one
            // can never be instantiated, so it would be baked into the manifest, boot the
            // runloop natively, and then fail — loudly on a device, where nobody is watching.
            if ($reflection->isAbstract()) {
                throw new \LogicException(sprintf('%s is abstract and cannot be a native screen. Move #[NativeScreen] to a concrete class.', $class));
            }

            $added[] = $this->registry->register($screen->path, $class, null, $screen->layout, $screen->name);
        }

        foreach ($reflection->getMethods() as $method) {
            // Only own methods: an inherited action would otherwise be registered once per
            // subclass, and every registration after the first collides on the same pattern.
            if ($method->getDeclaringClass()->getName() !== $reflection->getName()) {
                continue;
            }

            foreach ($method->getAttributes(NativeScreen::class, \ReflectionAttribute::IS_INSTANCEOF) as $attribute) {
                $screen = $attribute->newInstance();

                if (!$method->isPublic()) {
                    throw new \LogicException(sprintf('%s::%s() is not public and cannot be a native screen action.', $class, $method->getName()));
                }

                $added[] = $this->registry->register($screen->path, $class, $method->getName(), $screen->layout, $screen->name);
            }
        }

        return $added;
    }

    /**
     * Load every class under a PSR-4 root.
     *
     * Names are derived from the path rather than parsed out of the file, which is the same
     * assumption Composer's PSR-4 autoloader makes; a file whose class name does not follow
     * from its path is skipped rather than reported, because it is unloadable anyway.
     *
     * Sorted, because `readdir()` order is filesystem-dependent — the manifest's pattern
     * order would otherwise differ between the build machine and the device, which is
     * harmless for matching but makes two manifests impossible to diff when a boot goes wrong.
     *
     * @param string $namespacePrefix PSR-4 prefix mapped to $directory, e.g. `App\Screen`
     *
     * @return list<NativeRoute>
     */
    public function loadDirectory(string $directory, string $namespacePrefix): array
    {
        if (!is_dir($directory)) {
            throw new \InvalidArgumentException(sprintf('Native screen directory "%s" does not exist.', $directory));
        }

        $prefix = trim($namespacePrefix, '\\');
        $root = rtrim(str_replace('\\', '/', realpath($directory) ?: $directory), '/');

        $files = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS),
        );

        /** @var \SplFileInfo $file */
        foreach ($iterator as $file) {
            if ($file->isFile() && 'php' === $file->getExtension()) {
                $files[] = str_replace('\\', '/', $file->getPathname());
            }
        }

        sort($files);

        $added = [];

        foreach ($files as $file) {
            $relative = substr($file, \strlen($root) + 1, -\strlen('.php'));
            $class = $prefix.'\\'.str_replace('/', '\\', $relative);

            if (!class_exists($class)) {
                continue;
            }

            foreach ($this->loadClass($class) as $route) {
                $added[] = $route;
            }
        }

        return $added;
    }
}
