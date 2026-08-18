<?php

declare(strict_types=1);

namespace Native\Symfony\Mobile\Tests;

use Native\Symfony\Mobile\Ui\CallbackRegistry;
use Native\Symfony\Mobile\Ui\Element;
use Native\Symfony\Mobile\Ui\ElementFactory;
use Native\Symfony\Mobile\Ui\ElementPublisher;
use Native\Symfony\Mobile\Ui\Elements;
use Native\Symfony\Mobile\Ui\Style\StyleApplier;
use Native\Symfony\Mobile\Ui\Twig\NativeUiExtension;
use PHPUnit\Framework\TestCase;
use Twig\Environment;
use Twig\Loader\ArrayLoader;

final class NativeUiAuthoringTest extends TestCase
{
    public function testTheFactoryBuildsEveryRegisteredType(): void
    {
        $factory = new ElementFactory();

        foreach ($factory->types() as $type) {
            self::assertInstanceOf(Element::class, $factory->create($type));
        }
    }

    public function testAnUnknownTypeListsWhatIsAvailable(): void
    {
        // The alternative — emitting an unrecognised type — gives a screen with a
        // missing region and no error anywhere.
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/Unknown native element type "flexbox"\. Available: .*\bcolumn\b.*\brow\b/');

        (new ElementFactory())->create('flexbox');
    }

    public function testAnUnknownPropertyIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/has no property "fontSizes"/');

        (new ElementFactory())->create('text', ['text' => 'x', 'fontSizes' => 12]);
    }

    public function testAConstructorOptionOnTheWrongElementIsRejectedToo(): void
    {
        // `text`, `source` and `value` are consumed by the constructor — but only for the
        // element whose constructor consumes them. Skipping them for every type meant a
        // template could pass `text` to a text input, or `source` to a column, and get a
        // silently empty element while every other typo threw. That is the exact failure
        // this layer exists to prevent, at its own front door.
        $factory = new ElementFactory();

        foreach ([
            ['text_input', 'text'],
            ['column', 'source'],
            ['text', 'value'],
        ] as [$type, $option]) {
            try {
                $factory->create($type, [$option => 'x']);
                self::fail(sprintf('native(\'%s\', {%s: …}) must be refused.', $type, $option));
            } catch (\InvalidArgumentException $e) {
                self::assertStringContainsString(sprintf('has no property "%s"', $option), $e->getMessage());
            }
        }
    }

    public function testTheOptionEachConstructorDoesConsumeStillWorks(): void
    {
        $factory = new ElementFactory();
        $registry = new CallbackRegistry();
        $nextId = 1;

        $text = $factory->create('text', ['text' => 'hello'])->toArray($registry, $nextId);
        $image = $factory->create('image', ['source' => 'https://example.test/a.png'])->toArray($registry, $nextId);
        $input = $factory->create('text_input', ['value' => 'typed'])->toArray($registry, $nextId);
        $toggle = $factory->create('toggle', ['value' => true])->toArray($registry, $nextId);

        self::assertSame('hello', $text['props']['text']);
        // `src` on the wire, not `source`: the renderers intern it at PropKey 14.
        self::assertSame('https://example.test/a.png', $image['props']['src']);
        self::assertSame('typed', $input['props']['value']);
        self::assertTrue($toggle['props']['value']);
    }

    public function testAParserVocabularyKeyInAnExplicitLayoutIsRefused(): void
    {
        // The wire wants snake_case; the parser speaks camelCase and StyleApplier
        // translates. Writing the parser's name in an explicit `layout` — or passing a
        // parsed result straight through, which this project's own demo did — put a key on
        // the wire that every renderer ignores without a word.
        $factory = new ElementFactory();

        try {
            $factory->create('column', ['layout' => ['flexGrow' => 1]]);
            self::fail('A camelCase layout key must be refused.');
        } catch (\InvalidArgumentException $e) {
            self::assertStringContainsString('flex_grow', $e->getMessage());
        }

        try {
            $factory->create('column', ['style' => ['bg' => '#fff']]);
            self::fail('The parser calls it bg; the wire calls it bg_color.');
        } catch (\InvalidArgumentException $e) {
            self::assertStringContainsString('bg_color', $e->getMessage());
        }

        // The wire names themselves pass, and so does anything the maps do not know —
        // this refuses a known-wrong name, it is not an allowlist of every valid key.
        $registry = new CallbackRegistry();
        $nextId = 1;
        $node = $factory->create('column', ['layout' => ['flex_grow' => 1, 'gap' => 8]])->toArray($registry, $nextId);

        self::assertSame(1, $node['layout']['flex_grow']);
        self::assertSame(8, $node['layout']['gap']);
    }

    public function testSharedOptionsAreApplied(): void
    {
        $registry = new CallbackRegistry();
        $nextId = 1;

        $node = (new ElementFactory())->create('button', [
            'label' => 'Save',
            'key' => 'save-btn',
            'ref' => 'save',
            'onPress' => 'save',
            'layout' => ['flex_grow' => 1],
            'style' => ['background' => '#123456'],
        ])->toArray($registry, $nextId);

        self::assertSame('button', $node['type']);
        self::assertSame('Save', $node['props']['label']);
        self::assertSame('save', $node['ref']);
        self::assertSame(['flex_grow' => 1], $node['layout']);
        self::assertSame(['background' => '#123456'], $node['style']);
        self::assertSame('save', $registry->expression($node['on_press']));
    }

    public function testAKeyedElementGetsAHashedIdNotASequentialOne(): void
    {
        $registry = new CallbackRegistry();
        $nextId = 1;

        $node = (new ElementFactory())->create('text', ['text' => 'x', 'key' => 'k'])
            ->toArray($registry, $nextId);

        self::assertGreaterThan(1000, $node['id']);
    }

    public function testATreeCanBeAuthoredFromTwig(): void
    {
        $twig = new Environment(new ArrayLoader([
            'screen' => <<<'TWIG'
                {% do publish(native('column', {layout: {gap: 8}}, [
                    native('text', {text: 'Hello from Twig', fontSize: 24}),
                    native('button', {label: 'Tap me', onPress: 'save'}),
                    native('spacer'),
                ])) %}
                TWIG,
        ]));

        $factory = new ElementFactory();
        $publisher = new ElementPublisher();
        $registry = new CallbackRegistry();

        $twig->addExtension(new NativeUiExtension($factory));
        // `do` + a void function mirrors real use: a screen template publishes a frame,
        // it does not render markup. Returning JSON through the template would only be
        // testing Twig's autoescaping.
        $twig->addFunction(new \Twig\TwigFunction(
            'publish',
            static function (Element $root) use ($publisher, $registry): void {
                $publisher->publish($root, $registry);
            },
        ));

        self::assertSame('', trim($twig->render('screen')));

        /** @var array<string, mixed> $tree */
        $tree = $publisher->lastFrame();

        self::assertSame('column', $tree['type']);
        // An explicit `layout` option is the author's own value, passed through
        // verbatim — only values StyleApplier derives from classes get cast to the
        // types upstream sends.
        self::assertSame(['gap' => 8], $tree['layout']);
        self::assertCount(3, $tree['children']);
        self::assertSame('Hello from Twig', $tree['children'][0]['props']['text']);
        // The option is named fontSize (the setter's name); the wire key is font_size, and
        // the wire type is float — upstream's attribute path casts, and the content hash is
        // a serialize() so 24 and 24.0 are different frames.
        self::assertSame(24.0, $tree['children'][0]['props']['font_size']);
        self::assertSame('Tap me', $tree['children'][1]['props']['label']);
        self::assertSame('save', $registry->expression($tree['children'][1]['on_press']));
        // The spacer's default survives authoring.
        self::assertSame(['flex_grow' => 1], $tree['children'][2]['layout']);
    }

    public function testAStringChildIsRejectedWithAUsefulMessage(): void
    {
        // Interpolating a string where an element belongs is the most likely template
        // mistake, and without this it fails deep in the tree walk.
        $extension = new NativeUiExtension(new ElementFactory());

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches("/is a string, not a native element/");

        $extension->native('column', [], ['just text']);
    }

    public function testASingleChildNeedsNoArrayWrapper(): void
    {
        $extension = new NativeUiExtension(new ElementFactory());
        $registry = new CallbackRegistry();
        $nextId = 1;

        $node = $extension->native('column', [], $extension->native('text', ['text' => 'solo']))
            ->toArray($registry, $nextId);

        self::assertCount(1, $node['children']);
    }

    public function testAClassOptionReachesTheWireUnderItsWireNames(): void
    {
        // The spelling a template actually reaches for, and the one the README documented
        // before it worked. camelCase keys here would be silently ignored on the device.
        $factory = new ElementFactory([], new StyleApplier());
        $registry = new CallbackRegistry();
        $nextId = 1;

        $node = $factory->create('column', ['class' => 'flex-1 gap-2 bg-slate-900'])
            ->toArray($registry, $nextId);

        self::assertSame(1.0, $node['layout']['flex_grow']);
        self::assertSame(8.0, $node['layout']['gap']);
        self::assertSame('#0F172A', $node['style']['bg_color']);
    }

    public function testAnExplicitLayoutOptionBeatsAClass(): void
    {
        $factory = new ElementFactory([], new StyleApplier());
        $registry = new CallbackRegistry();
        $nextId = 1;

        $node = $factory->create('column', ['class' => 'gap-2', 'layout' => ['gap' => 99.0]])
            ->toArray($registry, $nextId);

        self::assertSame(99.0, $node['layout']['gap']);
    }

    public function testNativePublishPublishesAFrameFromATemplate(): void
    {
        $publisher = new ElementPublisher();
        $factory = new ElementFactory();
        $extension = new NativeUiExtension($factory, $publisher);

        $twig = new Environment(new ArrayLoader([
            'screen' => "{% do native_publish(native('column', {}, [native('text', {text: 'Hi'})])) %}",
        ]));
        $twig->addExtension($extension);

        self::assertSame('', trim($twig->render('screen')));
        self::assertSame('column', $publisher->lastFrame()['type']);
        self::assertSame('Hi', $publisher->lastFrame()['children'][0]['props']['text']);
    }

    public function testNativePublishWithoutAPublisherSaysSo(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessageMatches('/needs an ElementPublisher/');

        (new NativeUiExtension(new ElementFactory()))->publish(Elements\Column::make());
    }

    public function testThePublisherCapturesFramesWhenTheExtensionIsAbsent(): void
    {
        // Off a device there is nothing to publish to. Capturing rather than
        // discarding is what makes a native-UI screen testable at all.
        $publisher = new ElementPublisher();

        self::assertFalse($publisher->isAvailable());

        $publisher->publish((new ElementFactory())->create('column'), new CallbackRegistry());

        self::assertCount(1, $publisher->capturedFrames());
        self::assertSame('column', $publisher->lastFrame()['type']);
    }

    public function testThePublisherReusesUnchangedSubtreesAcrossFrames(): void
    {
        $publisher = new ElementPublisher();
        $registry = new CallbackRegistry();
        $factory = new ElementFactory();

        $build = static fn (): Element => $factory->create('column', [], [
            $factory->create('text', ['text' => 'static', 'key' => 's']),
        ]);

        $publisher->publish($build(), $registry);
        $second = $publisher->publish($build(), $registry);

        // The publisher keeps the hash map between frames, which is what turns
        // repeated renders into cheap ones. Upstream leaves that to the caller.
        self::assertSame(1, $second['flags']);
    }

    public function testResettingDiffStateForcesAFullRepaint(): void
    {
        // Node ids are only meaningful within one screen's tree, so navigating has to
        // drop the map or an unrelated subtree could be reused.
        $publisher = new ElementPublisher();
        $registry = new CallbackRegistry();
        $factory = new ElementFactory();

        $build = static fn (): Element => $factory->create('column', [], [
            $factory->create('text', ['text' => 'static', 'key' => 's']),
        ]);

        $publisher->publish($build(), $registry);
        $publisher->resetDiffState();
        $second = $publisher->publish($build(), $registry);

        self::assertArrayNotHasKey('flags', $second);
    }
}
