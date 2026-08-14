<?php

declare(strict_types=1);

namespace Native\Symfony\Mobile\Tests;

use Native\Symfony\Mobile\Ui\CallbackRegistry;
use Native\Symfony\Mobile\Ui\Element;
use Native\Symfony\Mobile\Ui\ElementFactory;
use Native\Symfony\Mobile\Ui\ElementPublisher;
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
        self::assertSame(['gap' => 8], $tree['layout']);
        self::assertCount(3, $tree['children']);
        self::assertSame('Hello from Twig', $tree['children'][0]['props']['text']);
        self::assertSame(24, $tree['children'][0]['props']['fontSize']);
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
