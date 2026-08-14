<?php

declare(strict_types=1);

namespace Native\Symfony\Mobile\Tests;

use Native\Symfony\Mobile\Ui\CallbackRegistry;
use Native\Symfony\Mobile\Ui\Element;
use Native\Symfony\Mobile\Ui\ElementPublisher;
use Native\Symfony\Mobile\Ui\Elements;
use Native\Symfony\Mobile\Ui\Routing\NativeRoute;
use Native\Symfony\Mobile\Ui\Routing\NativeRouteManifest;
use Native\Symfony\Mobile\Ui\Routing\NativeRouteMatch;
use Native\Symfony\Mobile\Ui\Routing\NativeRouteMatcher;
use Native\Symfony\Mobile\Ui\Routing\NativeRouteRegistry;
use Native\Symfony\Mobile\Ui\Routing\NativeScreen;
use Native\Symfony\Mobile\Ui\Routing\NativeScreenAttributeLoader;
use Native\Symfony\Mobile\Ui\Routing\NativeScreenNotFound;
use Native\Symfony\Mobile\Ui\Routing\NativeScreenResponder;
use Native\Symfony\Mobile\Ui\Routing\NativeScreenRouteLoader;
use Native\Symfony\Mobile\Ui\Routing\ScreenRendererInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Native-screen routing.
 *
 * The matcher cases below are the important ones. They are not "what seems reasonable for a
 * route pattern" — they are transcribed from `BootPlanner.matches()` in
 * `resources/androidstudio/.../ui/BootPlanner.kt` and its Swift twin, because that matcher
 * runs on device *before* PHP exists on a cold launch and decides whether the runloop or a
 * WebView boots. A disagreement between the two is invisible in PHP and shows up as a blank
 * frame or a dead screen on hardware, so the semantics are pinned here rather than left to
 * whatever the implementation happens to do.
 */
final class UiRoutingTest extends TestCase
{
    // ── Matcher: BootPlanner parity ─────────────────

    /**
     * @return iterable<string, array{string, string, bool}>
     */
    public static function matcherCases(): iterable
    {
        // Literal segments.
        yield 'literal exact' => ['/items', '/items', true];
        yield 'literal mismatch' => ['/items', '/orders', false];
        yield 'literal case sensitive' => ['/items', '/Items', false];
        yield 'literal deeper pattern' => ['/items/new', '/items', false];

        // {id}: matches exactly one segment, whatever is in it.
        yield 'required param' => ['/items/{id}', '/items/42', true];
        yield 'required param non numeric' => ['/items/{id}', '/items/not-a-number', true];
        yield 'required param missing' => ['/items/{id}', '/items', false];
        yield 'required param cannot span segments' => ['/items/{id}', '/items/42/edit', false];
        yield 'two required params' => ['/{a}/{b}', '/x/y', true];

        // {id?}: optional, and only as a trailing run.
        yield 'optional param present' => ['/items/{id?}', '/items/42', true];
        yield 'optional param absent' => ['/items/{id?}', '/items', true];
        yield 'optional param absent, no trailing slash difference' => ['/items/{id?}', '/items/', true];
        yield 'optional param with extra segment' => ['/items/{id?}', '/items/42/edit', false];
        yield 'two optional params, none given' => ['/items/{a?}/{b?}', '/items', true];
        yield 'two optional params, one given' => ['/items/{a?}/{b?}', '/items/1', true];
        yield 'two optional params, both given' => ['/items/{a?}/{b?}', '/items/1/2', true];
        // Upstream's `return isOptional || (i until p.size).all { ... }` short-circuits on the
        // FIRST missing segment, so one optional segment makes the entire remaining tail
        // optional — required segments and literals included. Contrary to the doc comment
        // above it in BootPlanner, and it makes the `all()` branch dead code (if the first
        // missing segment is required, `all()` fails on that same segment). Pinned because it
        // is the device's behaviour, not because it is desirable.
        yield 'required after optional, short path' => ['/items/{a?}/{b}', '/items', true];
        yield 'required after optional, full path' => ['/items/{a?}/{b}', '/items/1/2', true];
        yield 'literal after optional, short path' => ['/items/{a?}/edit', '/items', true];
        // …but only from the point the path runs out: a literal that IS present still has to
        // match.
        yield 'literal after optional, present and wrong' => ['/items/{a?}/edit', '/items/1/delete', false];

        // Slashes are insignificant, including duplicated interior ones — segments are
        // filtered for emptiness on both sides.
        yield 'pattern without leading slash' => ['items/{id}', '/items/42', true];
        yield 'path without leading slash' => ['/items/{id}', 'items/42', true];
        yield 'trailing slash on path' => ['/items/{id}', '/items/42/', true];
        yield 'trailing slash on pattern' => ['/items/{id}/', '/items/42', true];
        yield 'double interior slash on path' => ['/items/{id}', '/items//42', true];
        yield 'double interior slash on literal' => ['/items//new', '/items/new', true];

        // The root.
        yield 'root pattern, root path' => ['/', '/', true];
        yield 'root pattern, empty path' => ['/', '', true];
        yield 'root pattern, other path' => ['/', '/items', false];
        yield 'root path against literal' => ['/items', '/', false];

        // Positional brace test, exactly as upstream writes it.
        yield 'empty braces are a param' => ['/items/{}', '/items/42', true];
        yield 'braces not at the edges are literal' => ['/items/{id}x', '/items/42', false];
        yield 'braces not at the edges match literally' => ['/items/{id}x', '/items/{id}x', true];
    }

    #[DataProvider('matcherCases')]
    public function testMatcherAgreesWithBootPlanner(string $pattern, string $path, bool $expected): void
    {
        self::assertSame($expected, NativeRouteMatcher::matches($pattern, $path));
    }

    public function testStartPathIsNormalizedTheWayBootPlannerDoes(): void
    {
        // `startPath.substringBefore('?').ifEmpty { "/" }` — the query string never reaches
        // the matcher, and a query-only path is the root.
        self::assertSame('/items/42', NativeRouteMatcher::normalizeStartPath('/items/42?ref=push'));
        self::assertSame('/items/42', NativeRouteMatcher::normalizeStartPath('/items/42'));
        self::assertSame('/', NativeRouteMatcher::normalizeStartPath('?ref=push'));
        self::assertSame('/', NativeRouteMatcher::normalizeStartPath(''));

        // Not stripped: a fragment is compared literally on device too.
        self::assertSame('/items#top', NativeRouteMatcher::normalizeStartPath('/items#top'));
    }

    public function testParameterExtractionIsSegmentWiseAndHandlesOptionals(): void
    {
        self::assertSame(['id' => '42'], NativeRouteMatcher::parameters('/items/{id}', '/items/42'));
        self::assertSame(['a' => '1', 'b' => '2'], NativeRouteMatcher::parameters('/{a}/{b}', '/1/2'));

        // Upstream's regex extraction cannot see `{id?}` at all (`\w` does not match `?`), so
        // it mounts the screen with no parameters. Ours extracts it — the values never cross
        // the wire, so PHP and the device cannot disagree about them.
        self::assertSame(['id' => '42'], NativeRouteMatcher::parameters('/items/{id?}', '/items/42'));

        // A missing optional is omitted rather than null, so the screen's own default wins.
        self::assertSame([], NativeRouteMatcher::parameters('/items/{id?}', '/items'));

        self::assertSame(['id'], NativeRouteMatcher::parameterNames('/items/{id}'));
        self::assertSame(['a', 'b'], NativeRouteMatcher::parameterNames('/items/{a}/{b?}'));
        self::assertSame([], NativeRouteMatcher::parameterNames('/items'));
    }

    // ── Registry ────────────────────────────────────

    public function testPatternsAreCanonicalisedAndKeepDeclarationOrder(): void
    {
        $registry = new NativeRouteRegistry();
        $registry->register('/', HomeScreen::class);
        $registry->register('items', ItemsController::class, 'index');
        $registry->register('/items/{id}', ItemsController::class, 'show');

        self::assertSame(['/', '/items', '/items/{id}'], $registry->patterns());
        self::assertSame(3, $registry->count());
        self::assertNotNull($registry->get('items'));
        self::assertSame('/items', $registry->get('/items')?->pattern);
    }

    public function testDuplicatePatternsFromDifferentScreensAreRefused(): void
    {
        $registry = new NativeRouteRegistry();
        $registry->register('/items', ItemsController::class, 'index');

        // Re-declaring the same screen is tolerated — discovery can legitimately run twice.
        $registry->register('/items', ItemsController::class, 'index');
        self::assertSame(1, $registry->count());

        $this->expectException(\LogicException::class);
        $registry->register('/items', HomeScreen::class);
    }

    public function testResolvePrefersAnExactPatternOverAPlaceholder(): void
    {
        $registry = new NativeRouteRegistry();
        // Declared placeholder-first on purpose: precedence must not depend on order.
        $registry->register('/items/{id}', ItemsController::class, 'show');
        $registry->register('/items/new', ItemsController::class, 'create');

        self::assertSame('/items/new', $registry->resolve('/items/new')?->route->pattern);
        self::assertSame('/items/{id}', $registry->resolve('/items/42')?->route->pattern);
    }

    public function testResolveCarriesParametersPathAndTolerantSlashes(): void
    {
        $registry = new NativeRouteRegistry();
        $registry->register('/items/{id}', ItemsController::class, 'show', layout: 'tabs');

        $match = $registry->resolve('/items/42?from=push');

        self::assertInstanceOf(NativeRouteMatch::class, $match);
        self::assertSame(['id' => '42'], $match->parameters);
        self::assertSame('42', $match->parameter('id'));
        self::assertNull($match->parameter('missing'));
        self::assertSame('tabs', $match->route->layout);
        // The query string is gone but the path is otherwise verbatim — a screen needs the URI
        // it was reached by, and pattern + params does not round-trip `/items//42`.
        self::assertSame('/items/42', $match->path);

        // A trailing slash misses the exact-key lookup and is caught by the segment matcher,
        // which is how upstream behaves too.
        self::assertSame('/items/{id}', $registry->resolve('/items/42/')?->route->pattern);

        self::assertNull($registry->resolve('/orders/42'));
        self::assertTrue($registry->isNativePath('/items/42'));
        self::assertFalse($registry->isNativePath('/orders/42'));
    }

    public function testRouteExposesItsControllerOnlyWhenCallable(): void
    {
        self::assertSame(ItemsController::class.'::show', (new NativeRoute('/items/{id}', ItemsController::class, 'show'))->controller());
        self::assertSame(HomeScreen::class, (new NativeRoute('/', HomeScreen::class))->controller());
        // A class-level screen with no __invoke is a component for the runloop, not a
        // controller — there is nothing HTTP can call.
        self::assertNull((new NativeRoute('/settings', SettingsComponent::class))->controller());
    }

    // ── Manifest ────────────────────────────────────

    public function testRuntimeDumpAndBundleMetaHaveTheShapesBootPlannerReads(): void
    {
        $registry = new NativeRouteRegistry();
        $registry->register('/', HomeScreen::class);
        $registry->register('/items/{id?}', ItemsController::class, 'show');

        $manifest = new NativeRouteManifest($registry, '1.4.2');

        // Runtime dump: `routes`.
        self::assertSame(['version' => '1.4.2', 'routes' => ['/', '/items/{id?}']], $manifest->runtimeDump());

        // Baked: `native_routes`, plus the entry-mode escape hatch. Different key for the same
        // list — upstream's inconsistency, and both readers depend on it.
        self::assertSame([
            'version' => '1.4.2',
            'entry_mode' => 'auto',
            'native_routes' => ['/', '/items/{id?}'],
        ], $manifest->bundleMetaFragment());

        self::assertSame('web', $manifest->bundleMetaFragment(forceWebEntry: true)['entry_mode']);

        // Patterns cross the wire verbatim: `{id?}` must survive, since the device's matcher
        // is the one that interprets it.
        self::assertContains('/items/{id?}', $manifest->bundleMetaFragment()['native_routes']);
    }

    public function testAnEmptyVersionIsRefused(): void
    {
        // "" is what both platforms fall back to when the key is missing, so an empty version
        // would make an unversioned dump look like a match for any bake.
        $this->expectException(\InvalidArgumentException::class);
        new NativeRouteManifest(new NativeRouteRegistry(), '');
    }

    public function testRuntimeDumpIsWrittenToThePathTheDeviceReads(): void
    {
        $registry = new NativeRouteRegistry();
        $registry->register('/items/{id}', ItemsController::class, 'show');

        $root = sys_get_temp_dir().'/native-routing-'.bin2hex(random_bytes(6));
        $path = (new NativeRouteManifest($registry, '1.0.0'))->writeRuntimeDump($root);

        // The tail is hard-coded on both platforms, and the intermediate directories do not
        // exist in a Symfony app — creating them is this class's job or the dump silently
        // never appears.
        self::assertSame($root.'/storage/framework/native_routes.json', $path);
        self::assertSame(
            '{"version":"1.0.0","routes":["/items/{id}"]}',
            file_get_contents($path),
            'Slashes must not be escaped: the file is compared by eye when a boot decision goes wrong.'
        );

        unlink($path);
        rmdir(\dirname($path));
        rmdir(\dirname($path, 2));
        rmdir($root);
    }

    public function testEffectivePatternsReplaysTheDeviceSourceChoice(): void
    {
        $baked = ['version' => '1.0.0', 'native_routes' => ['/']];
        $dump = ['version' => '1.0.0', 'routes' => ['/', '/items/{id}']];

        // Versions match → the fresher runtime dump wins, which is what makes hot-reloading a
        // new screen work without a rebuild.
        self::assertSame(['/', '/items/{id}'], NativeRouteManifest::effectivePatterns($baked, $dump));

        // Stale dump from a previous build → ignored, the bake stands.
        self::assertSame(['/'], NativeRouteManifest::effectivePatterns($baked, ['version' => '0.9.0', 'routes' => ['/x']]));

        // No dump at all → the bake.
        self::assertSame(['/'], NativeRouteManifest::effectivePatterns($baked, null));

        // No manifest at all → null, i.e. WEB_LEGACY. The safe fallback.
        self::assertNull(NativeRouteManifest::effectivePatterns(null, null));

        // Android tolerates a missing bundle_meta.json and boots off an unversioned dump; iOS
        // does not get this far. Pinned because it is the one place the two platforms differ.
        self::assertSame(['/x'], NativeRouteManifest::effectivePatterns(null, ['routes' => ['/x']]));
    }

    // ── Attribute discovery ─────────────────────────

    public function testAttributesOnClassesAndMethodsAreDiscovered(): void
    {
        $registry = new NativeRouteRegistry();
        $added = (new NativeScreenAttributeLoader($registry))->loadClasses([HomeScreen::class, ItemsController::class]);

        self::assertCount(4, $added);
        self::assertSame(['/', '/items', '/items/{id}', '/items/{id}/edit'], $registry->patterns());

        // Class-level: the class is the screen, no action.
        $home = $registry->get('/');
        self::assertSame(HomeScreen::class, $home?->screen);
        self::assertNull($home?->action);
        self::assertSame('tabs', $home?->layout);
        self::assertSame('home', $home?->name);

        // Method-level: the action is recorded, and the attribute is repeatable.
        self::assertSame('show', $registry->get('/items/{id}')?->action);
        self::assertSame('show', $registry->get('/items/{id}/edit')?->action);
        self::assertSame('index', $registry->get('/items')?->action);
    }

    public function testInheritedActionsAreNotRegisteredTwice(): void
    {
        $registry = new NativeRouteRegistry();
        // A subclass inherits `show()` and its attribute. Registering it again would collide on
        // the same pattern — reflection sees inherited methods, so this has to be filtered.
        (new NativeScreenAttributeLoader($registry))->loadClasses([ItemsController::class, ChildItemsController::class]);

        self::assertSame(ItemsController::class, $registry->get('/items/{id}')?->screen);
        self::assertSame(['/items', '/items/{id}', '/items/{id}/edit', '/child'], $registry->patterns());
    }

    public function testAnAbstractScreenIsRefused(): void
    {
        $this->expectException(\LogicException::class);
        (new NativeScreenAttributeLoader(new NativeRouteRegistry()))->loadClass(AbstractScreen::class);
    }

    public function testANonPublicActionIsRefused(): void
    {
        $this->expectException(\LogicException::class);
        (new NativeScreenAttributeLoader(new NativeRouteRegistry()))->loadClass(PrivateActionController::class);
    }

    public function testDirectoryDiscoveryIsPsr4AndSorted(): void
    {
        $namespace = 'NativeScreenFixtures'.bin2hex(random_bytes(4));
        $dir = sys_get_temp_dir().'/'.$namespace;

        mkdir($dir.'/Deep', 0o777, true);
        file_put_contents($dir.'/Beta.php', $this->fixtureClass($namespace, 'Beta', '/beta'));
        file_put_contents($dir.'/Alpha.php', $this->fixtureClass($namespace, 'Alpha', '/alpha'));
        file_put_contents($dir.'/Deep/Nested.php', $this->fixtureClass($namespace.'\\Deep', 'Nested', '/deep/nested'));
        file_put_contents($dir.'/NotAClass.php', "<?php\n// no class here\n");
        file_put_contents($dir.'/ignored.txt', 'not php');

        $autoload = static function (string $class) use ($namespace, $dir): void {
            if (str_starts_with($class, $namespace.'\\')) {
                $relative = str_replace('\\', '/', substr($class, \strlen($namespace) + 1));
                $file = $dir.'/'.$relative.'.php';
                if (is_file($file)) {
                    require $file;
                }
            }
        };
        spl_autoload_register($autoload);

        try {
            $registry = new NativeRouteRegistry();
            (new NativeScreenAttributeLoader($registry))->loadDirectory($dir, $namespace);

            // Sorted by path, not by readdir order: an unstable order makes the build machine's
            // manifest and the device's dump impossible to diff.
            self::assertSame(['/alpha', '/beta', '/deep/nested'], $registry->patterns());
        } finally {
            spl_autoload_unregister($autoload);
            array_map('unlink', glob($dir.'/Deep/*') ?: []);
            array_map('unlink', glob($dir.'/*.*') ?: []);
            rmdir($dir.'/Deep');
            rmdir($dir);
        }
    }

    public function testAMissingDirectoryIsReportedRatherThanSilentlyEmpty(): void
    {
        // Silently registering nothing here is the worst outcome: it bakes an empty manifest
        // and every screen boots into a WebView.
        $this->expectException(\InvalidArgumentException::class);
        (new NativeScreenAttributeLoader(new NativeRouteRegistry()))->loadDirectory(sys_get_temp_dir().'/does-not-exist-'.bin2hex(random_bytes(4)), 'X');
    }

    // ── Responding to a native-screen request ───────

    public function testRespondRendersTheMatchedScreenAndPublishesTheFrame(): void
    {
        $registry = new NativeRouteRegistry();
        $registry->register('/items/{id}', ItemsController::class, 'show');

        $renderer = new RecordingRenderer();
        $publisher = new ElementPublisher();
        $responder = new NativeScreenResponder($registry, $renderer, $publisher);

        self::assertTrue($responder->handles('/items/42?from=push'));

        $tree = $responder->respond('/items/42?from=push');

        self::assertSame([['/items/{id}', ['id' => '42']]], $renderer->calls);
        self::assertSame('column', $tree['type']);
        self::assertSame($tree, $publisher->lastFrame(), 'Off-device the frame is captured, which is what makes a screen testable at all.');
        self::assertSame('/items/42', $responder->currentMatch()?->path);
    }

    public function testCallbackIdsAreScopedToTheScreenPattern(): void
    {
        $registry = new NativeRouteRegistry();
        $registry->register('/items/{id}', ItemsController::class, 'show');
        $registry->register('/orders/{id}', ItemsController::class, 'index');

        $responder = new NativeScreenResponder($registry, new RecordingRenderer(), new ElementPublisher());

        $itemsTree = $responder->respond('/items/1');
        $itemsScope = $responder->callbacks()?->scope();
        $ordersTree = $responder->respond('/orders/1');

        self::assertSame('/items/{id}', $itemsScope);
        self::assertSame('/orders/{id}', $responder->callbacks()?->scope());

        // Same handler expression on two screens: unscoped these would derive the same id, and
        // an interaction resolved against the wrong screen's registry runs the wrong handler.
        self::assertNotSame($itemsTree['children'][1]['on_press'], $ordersTree['children'][1]['on_press']);
    }

    public function testDiffStateIsDroppedWhenTheScreenChangesButNotBetweenFrames(): void
    {
        $registry = new NativeRouteRegistry();
        $registry->register('/items/{id}', ItemsController::class, 'show');
        $registry->register('/orders/{id}', ItemsController::class, 'index');

        $responder = new NativeScreenResponder($registry, new RecordingRenderer(), new ElementPublisher());

        $responder->respond('/items/1');
        $second = $responder->republish();

        // Frame two of the same screen: unchanged subtrees come back as reuse markers, which is
        // the whole point of keeping the hash map.
        self::assertSame(1, $second['flags'] ?? null);
        self::assertArrayNotHasKey('children', $second);

        $responder->respond('/orders/1');
        $back = $responder->respond('/items/1');

        // Returning to a screen must be a full repaint: node ids only mean something within one
        // screen's tree, so a stale hash would splice an unrelated cached subtree.
        self::assertArrayNotHasKey('flags', $back);
        self::assertArrayHasKey('children', $back);
    }

    public function testAnUnknownPathIsACatchableMiss(): void
    {
        $responder = new NativeScreenResponder(new NativeRouteRegistry(), new RecordingRenderer(), new ElementPublisher());

        // Not merely a bug: the device boots from a baked pattern list that can be newer than
        // the running code, so the runloop needs to be able to fall back to the web path.
        $this->expectException(NativeScreenNotFound::class);
        $responder->respond('/items/42');
    }

    public function testRepublishWithoutACurrentScreenIsAProgrammingError(): void
    {
        $responder = new NativeScreenResponder(new NativeRouteRegistry(), new RecordingRenderer(), new ElementPublisher());

        $this->expectException(\LogicException::class);
        $responder->republish();
    }

    public function testATreeBuiltElsewhereCanBePublished(): void
    {
        $publisher = new ElementPublisher();
        $responder = new NativeScreenResponder(new NativeRouteRegistry(), new RecordingRenderer(), $publisher);

        $tree = $responder->publishTree(Elements\Column::make(Elements\Text::make('placeholder')));

        self::assertSame('column', $tree['type']);
        self::assertSame($tree, $publisher->lastFrame());
    }

    // ── Symfony route exposure ──────────────────────

    public function testDeclaredScreensBecomeOrdinarySymfonyRoutes(): void
    {
        $registry = new NativeRouteRegistry();
        (new NativeScreenAttributeLoader($registry))->loadClasses([HomeScreen::class, ItemsController::class]);
        $registry->register('/settings', SettingsComponent::class);
        $registry->register('/items/{id?}/print', ItemsController::class, 'index', name: 'items_print');

        $loader = new NativeScreenRouteLoader($registry);

        self::assertTrue($loader->supports('.', 'native_screens'));
        self::assertFalse($loader->supports('.', 'yaml'));

        $collection = $loader->load('.', 'native_screens');

        // A component with no __invoke has no callable controller, so no route is registered —
        // a route pointing at one would fatal on the first request.
        self::assertArrayNotHasKey('native_screen.settings', $collection->all());

        self::assertSame([
            'home',
            'native_screen.items',
            'native_screen.items_id',
            'native_screen.items_id_edit',
            'items_print',
        ], array_keys($collection->all()));

        $show = $collection->get('native_screen.items_id');
        self::assertNotNull($show);
        self::assertSame('/items/{id}', $show->getPath());
        self::assertSame(['GET'], $show->getMethods());
        self::assertSame(ItemsController::class.'::show', $show->getDefault('_controller'));
        self::assertSame('/items/{id}', $show->getDefault('_native_screen'));

        // Laravel's `{id?}` has no path-level equivalent in Symfony: the placeholder stays and
        // the optionality becomes a null default. Only the manifest keeps the original syntax,
        // because the device is what interprets it.
        $print = $collection->get('items_print');
        self::assertNotNull($print);
        self::assertSame('/items/{id}/print', $print->getPath());
        self::assertTrue($print->hasDefault('id'));
        self::assertNull($print->getDefault('id'));
        self::assertSame('/items/{id?}/print', $print->getDefault('_native_screen'));

        self::assertSame('native_screen.root', NativeScreenRouteLoader::routeName('/'));
    }

    private function fixtureClass(string $namespace, string $class, string $path): string
    {
        return sprintf(
            "<?php\n\nnamespace %s;\n\nuse Native\\Symfony\\Mobile\\Ui\\Routing\\NativeScreen;\n\n#[NativeScreen('%s')]\nfinal class %s\n{\n    public function __invoke(): void\n    {\n    }\n}\n",
            $namespace,
            $path,
            $class,
        );
    }
}

/**
 * A renderer standing in for the component lifecycle, which is deliberately not this
 * namespace's business — it records what routing handed it and returns a trivial tree.
 */
final class RecordingRenderer implements ScreenRendererInterface
{
    /** @var list<array{0: string, 1: array<string, string>}> */
    public array $calls = [];

    public function renderScreen(NativeRouteMatch $match, CallbackRegistry $callbacks): Element
    {
        $this->calls[] = [$match->route->pattern, $match->parameters];

        return Elements\Column::make(
            Elements\Text::make('Screen '.$match->route->pattern),
            Elements\Button::make('Save')->onPress('save'),
        );
    }
}

#[NativeScreen('/', layout: 'tabs', name: 'home')]
final class HomeScreen
{
    public function __invoke(): void
    {
    }
}

class ItemsController
{
    #[NativeScreen('/items')]
    public function index(): void
    {
    }

    #[NativeScreen('/items/{id}')]
    #[NativeScreen('/items/{id}/edit')]
    public function show(): void
    {
    }
}

final class ChildItemsController extends ItemsController
{
    #[NativeScreen('/child')]
    public function child(): void
    {
    }
}

/** A screen for the runloop rather than for HTTP: no __invoke, so no controller. */
final class SettingsComponent
{
}

#[NativeScreen('/abstract')]
abstract class AbstractScreen
{
}

final class PrivateActionController
{
    #[NativeScreen('/private')]
    private function hidden(): void
    {
    }
}
