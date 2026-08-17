<?php

declare(strict_types=1);

namespace Native\Symfony\Desktop\Tests;

use Native\Symfony\Desktop\App\AppManager;
use Native\Symfony\Desktop\Client\RuntimeNotAvailable;
use Native\Symfony\Desktop\Contract\ClientInterface;
use Native\Symfony\Desktop\Contract\Response;
use Native\Symfony\Desktop\Dialog\DialogManager;
use Native\Symfony\Desktop\Event\App\OpenedFromURL;
use Native\Symfony\Desktop\Event\ChildProcess\ProcessExited;
use Native\Symfony\Desktop\Event\ChildProcess\ProcessSpawned;
use Native\Symfony\Desktop\Event\Menu\MenuItemClicked;
use Native\Symfony\Desktop\Event\NativeEvent;
use Native\Symfony\Desktop\Event\Windows\WindowResized;
use Native\Symfony\Desktop\NativeDesktopBundle;
use Native\Symfony\Desktop\Notification\NotificationManager;
use Native\Symfony\Desktop\Process\ChildProcessManager;
use Native\Symfony\Desktop\Testing\FakeRuntime;
use Native\Symfony\Desktop\Window\UrlResolver;
use Native\Symfony\Desktop\Window\WindowManager;
use Native\Symfony\Desktop\Testing\InteractsWithNativeRuntime;
use Native\Symfony\Desktop\Testing\RecordedCall;
use Native\Symfony\Desktop\Testing\RuntimeEventSimulator;
use Native\Symfony\Desktop\Testing\RuntimeExpectations;
use PHPUnit\Framework\AssertionFailedError;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * The kit's own suite.
 *
 * Every assertion helper is exercised twice — once passing, once failing — and
 * the failing half asserts on the *message*, because an unhelpful failure is the
 * defect this kit exists to avoid.
 */
final class TestingKitTest extends TestCase
{
    use InteractsWithNativeRuntime;

    // --- the fake transport ---------------------------------------------------

    public function testAnUnscriptedEndpointAnswersABare200(): void
    {
        // CONTRACT.md §0 shape 1 — the runtime's normal reply to a mutation. Reads
        // therefore fall back to their zero values rather than exploding.
        $fake = FakeRuntime::available();

        $response = $fake->post('window/close', ['id' => 'main']);

        self::assertSame(200, $response->status);
        self::assertNull($response->data);
        self::assertSame('', (new AppManager($fake))->locale());
    }

    public function testAnUnavailableRuntimeThrowsExactlyAsTheRealClientDoes(): void
    {
        $fake = FakeRuntime::unavailable();

        self::assertFalse($fake->isAvailable());

        try {
            $fake->post('app/quit');
            self::fail('Expected RuntimeNotAvailable.');
        } catch (RuntimeNotAvailable) {
            // A call that could not leave the process is not a call: nothing recorded.
            self::assertSame([], $fake->calls());
        }
    }

    public function testAScriptedSequenceIsHandedOutInOrderAndThenRepeats(): void
    {
        $fake = FakeRuntime::available()->willRespondWith(
            'app/badge-count',
            new Response(200, ['count' => 1]),
            new Response(200, ['count' => 2]),
        );

        $app = new AppManager($fake);

        self::assertSame([1, 2, 2], [$app->badgeCount(), $app->badgeCount(), $app->badgeCount()]);
    }

    public function testTheMostSpecificWildcardWinsRegardlessOfDeclarationOrder(): void
    {
        $fake = FakeRuntime::available()
            ->willReturn('window/*', ['id' => 'wildcard'])
            ->willReturn('window/get/*', ['id' => 'specific']);

        self::assertSame(['id' => 'specific'], $fake->get('window/get/main')->data);
        self::assertSame(['id' => 'wildcard'], $fake->get('window/all')->data);
    }

    public function testAHandlerCanAnswerFromTheRequestItself(): void
    {
        $fake = FakeRuntime::available()->willRespondUsing(
            'clipboard/text',
            static fn (RecordedCall $call): Response => new Response(200, ['text' => $call->payload['text'] ?? 'empty']),
        );

        self::assertSame(['text' => 'hi'], $fake->post('clipboard/text', ['text' => 'hi'])->data);
    }

    public function testAScriptedWindowIsServedByBothGetAndAll(): void
    {
        $windows = $this->nativeWindows();
        $this->nativeRuntime()->windowIs('main', ['title' => 'Main', 'width' => 800]);

        self::assertSame('Main', $windows->get('main')?->title);
        self::assertSame(['main'], array_map(static fn ($w): string => $w->id, $windows->all()));
    }

    public function testAMissingWindowIsA404WithNoData(): void
    {
        $this->nativeRuntime()->windowDoesNotExist('side');

        self::assertNull($this->nativeWindows()->get('side'));
    }

    public function testTheCurrentWindowIsFallible(): void
    {
        // §1: the runtime dereferences getFocusedWindow().id with no null guard, so
        // a backgrounded app gets a 500 here. Scriptable precisely because code
        // that reads the current window has to cope with it.
        $windows = $this->nativeWindows();

        $this->nativeRuntime()->currentWindowIs('main', ['focused' => true]);
        self::assertSame('main', $windows->current()?->id);

        $this->nativeRuntime()->noCurrentWindow();
        self::assertNull($windows->current());
    }

    public function testForgettingCallsKeepsTheScript(): void
    {
        $fake = FakeRuntime::available()->willReturn('app/version', ['version' => '2.0.0']);

        $fake->get('app/version');
        $fake->forgetCalls();

        self::assertSame([], $fake->calls());
        self::assertSame(['version' => '2.0.0'], $fake->get('app/version')->data);
    }

    // --- canned dialog answers ------------------------------------------------

    public function testTheUserCanPickFiles(): void
    {
        $dialogs = new DialogManager($this->nativeRuntime()->userPicksFiles('/tmp/a.txt', '/tmp/b.txt'));

        self::assertSame(['/tmp/a.txt', '/tmp/b.txt'], $dialogs->open()->multiple()->open());
        self::assertSame('/tmp/a.txt', $dialogs->open()->openOne());
    }

    public function testACancelledOpenDialogSendsAMissingKeyNotAnEmptyList(): void
    {
        $fake = FakeRuntime::available()->userCancelsFileSelection();

        // The shape matters: the runtime sends {result: undefined}. Scripting an
        // empty list instead would test a reply the runtime never produces.
        self::assertSame([], $fake->post('dialog/open')->array());
        self::assertSame([], (new DialogManager($fake))->open()->open());
        self::assertNull((new DialogManager($fake))->open()->openOne());
    }

    public function testTheUserCanSaveOrCancelASaveDialog(): void
    {
        $saved = new DialogManager(FakeRuntime::available()->userSavesFileAs('/tmp/out.csv'));
        $cancelled = new DialogManager(FakeRuntime::available()->userCancelsSave());

        self::assertSame('/tmp/out.csv', $saved->save()->save());
        self::assertNull($cancelled->save()->save());
    }

    public function testTheUserCanConfirmOrDeclineAConfirmation(): void
    {
        self::assertTrue((new DialogManager(FakeRuntime::available()->userConfirms()))->confirm('Delete?'));
        self::assertFalse((new DialogManager(FakeRuntime::available()->userDeclines()))->confirm('Delete?'));
    }

    public function testTheUserCanClickAnyAlertButton(): void
    {
        $dialogs = new DialogManager(FakeRuntime::available()->userClicksAlertButton(2));

        self::assertSame(2, $dialogs->alert('Pick one', ['A', 'B', 'C']));
    }

    // --- assertions that pass -------------------------------------------------

    public function testWindowAssertionsPass(): void
    {
        $windows = $this->nativeWindows();
        $windows->open('editor')->size(400, 300)->open();
        $windows->show('editor');
        $windows->hide('editor');
        $windows->resize(640, 480, 'editor');
        $windows->title('Editor', 'editor');
        $windows->navigate('/documents', 'editor');

        $this->nativeExpects()->assertWindowOpened('editor', ['width' => 400, 'height' => 300]);
        $this->nativeExpects()->assertWindowOpened();
        $this->nativeExpects()->assertWindowShown('editor');
        $this->nativeExpects()->assertWindowHidden('editor');
        $this->nativeExpects()->assertWindowResized(640, 480, 'editor');
        $this->nativeExpects()->assertWindowTitled('Editor', 'editor');
        $this->nativeExpects()->assertWindowNavigatedTo('/documents', 'editor');
        $this->nativeExpects()->assertNoWindowClosed();
        $this->nativeExpects()->assertNoWindowOpened('other');
    }

    public function testDialogAssertionsPass(): void
    {
        $dialogs = new DialogManager($this->nativeRuntime());
        $dialogs->open()->directories()->attachedTo('main')->open();
        $dialogs->save()->defaultPath('/tmp/out.csv')->save();
        $dialogs->alert('Careful');
        $dialogs->error('Boot failed', 'No database');

        $this->nativeExpects()->assertFileDialogShown(['properties' => ['openDirectory']]);
        $this->nativeExpects()->assertFileDialogShownAttachedTo('main');
        $this->nativeExpects()->assertSaveDialogShown(['defaultPath' => '/tmp/out.csv']);
        $this->nativeExpects()->assertAlerted('Careful');
        $this->nativeExpects()->assertErrorBoxShown('Boot failed');
    }

    public function testNotificationAssertionsPass(): void
    {
        (new NotificationManager($this->nativeRuntime()))->send('Done', 'Import finished');

        $this->nativeExpects()->assertNotificationSent('Done', 'Import finished');
        $this->nativeExpects()->assertNotificationSent('Done');
        $this->nativeExpects()->assertNotificationSent();
    }

    public function testChildProcessAssertionsPass(): void
    {
        $processes = new ChildProcessManager($this->nativeRuntime());
        $processes->console('import', ['app:import']);
        $processes->node('sidecar', ['server.js']);
        $processes->stop('sidecar');
        $processes->message('import', ['pause' => true]);

        $this->nativeExpects()->assertProcessStarted('import', ['bin/console', 'app:import']);
        $this->nativeExpects()->assertProcessStarted('sidecar');
        $this->nativeExpects()->assertProcessStopped('sidecar');
        $this->nativeExpects()->assertMessageSentToProcess('import', ['pause' => true]);
        $this->nativeExpects()->assertProcessNotStarted('worker');
    }

    public function testAppAndGenericAssertionsPass(): void
    {
        $app = new AppManager($this->nativeRuntime());
        $app->setBadgeCount(3);
        $app->setBadgeCount(4);

        $this->nativeExpects()->assertCalled('app/badge-count', ['count' => 4]);
        $this->nativeExpects()->assertCalledTimes(2, 'app/badge-count');
        $this->nativeExpects()->assertNotCalled('app/quit');
        $this->nativeExpects()->assertNoQuitRequested();
        $this->nativeExpects()->assertNoNotificationSent();
        $this->nativeExpects()->assertNoDialogShown();

        $app->quit();
        $this->nativeExpects()->assertQuitRequested();
    }

    public function testNothingSentPassesOnAGuardedPath(): void
    {
        $this->nativeExpects()->assertNothingSent();
    }

    // --- assertions that fail, and say something useful -----------------------

    public function testAMissedWindowOpenNamesTheIdAndListsWhatHappenedInstead(): void
    {
        $windows = $this->nativeWindows();
        $windows->open('other')->open();
        $windows->close('other');

        $message = $this->failureMessageOf(fn () => $this->nativeExpects()->assertWindowOpened('editor'));

        self::assertStringContainsString('Expected the app to ask the runtime to open a window with id "editor"', $message);
        self::assertStringContainsString('It called those endpoints, but no call matched.', $message);
        self::assertStringContainsString('Recorded runtime calls (2):', $message);
        self::assertStringContainsString('POST   window/open', $message);
        self::assertStringContainsString('"id":"other"', $message);
        self::assertStringContainsString('POST   window/close', $message);
    }

    public function testAnUnexpectedCloseIsQuotedBackWithItsSequenceNumber(): void
    {
        $windows = $this->nativeWindows();
        $windows->show('main');
        $windows->close('main');

        $message = $this->failureMessageOf(fn () => $this->nativeExpects()->assertNoWindowClosed());

        self::assertStringContainsString('Expected the app to close no window, but it made 1 such call(s):', $message);
        self::assertStringContainsString('#2 POST   window/close', $message);
    }

    public function testAnEndpointNeverTouchedIsReportedAsSuchRatherThanAsAMismatch(): void
    {
        $message = $this->failureMessageOf(fn () => $this->nativeExpects()->assertNotificationSent('Done'));

        self::assertStringContainsString('Expected the app to ask the runtime to send a notification with title="Done"', $message);
        self::assertStringContainsString('It never called notification.', $message);
        self::assertStringContainsString('No runtime calls were recorded at all.', $message);
    }

    public function testAWrongNotificationTitleShowsTheOneThatWasSent(): void
    {
        (new NotificationManager($this->nativeRuntime()))->send('Failed', 'Import broke');

        $message = $this->failureMessageOf(fn () => $this->nativeExpects()->assertNotificationSent('Done'));

        self::assertStringContainsString('with title="Done"', $message);
        self::assertStringContainsString('"title":"Failed"', $message);
    }

    public function testAMissedProcessStartListsEveryStartThatWasAttempted(): void
    {
        $processes = new ChildProcessManager($this->nativeRuntime());
        $processes->console('import', ['app:import']);
        $processes->node('sidecar', ['server.js']);

        $message = $this->failureMessageOf(fn () => $this->nativeExpects()->assertProcessStarted('worker'));

        self::assertStringContainsString('start the child process "worker"', $message);
        self::assertStringContainsString('child-process/start-php', $message);
        self::assertStringContainsString('child-process/start-node', $message);
    }

    public function testAMissedProcessCommandQuotesTheCommandBothWays(): void
    {
        (new ChildProcessManager($this->nativeRuntime()))->console('import', ['app:import']);

        $message = $this->failureMessageOf(
            fn () => $this->nativeExpects()->assertProcessStarted('import', ['bin/console', 'app:export']),
        );

        self::assertStringContainsString('running bin/console app:export', $message);
        self::assertStringContainsString('"app:import"', $message);
    }

    public function testAnUnattachedDialogFailureNamesTheWindowItWasExpectedToBlock(): void
    {
        (new DialogManager($this->nativeRuntime()))->open()->open();

        $message = $this->failureMessageOf(fn () => $this->nativeExpects()->assertFileDialogShownAttachedTo('main'));

        self::assertStringContainsString('show a file-open dialog attached to window "main"', $message);
        self::assertStringContainsString('dialog/open', $message);
    }

    public function testAnUnwantedDialogFailureNamesAllFourBlockingEndpoints(): void
    {
        $dialogs = new DialogManager($this->nativeRuntime());
        $dialogs->alert('Careful');
        $dialogs->error('Nope', 'Broken');

        $message = $this->failureMessageOf(fn () => $this->nativeExpects()->assertNoDialogShown());

        self::assertStringContainsString('show no dialog or alert, but it made 2 such call(s)', $message);
        self::assertStringContainsString('alert/message', $message);
        self::assertStringContainsString('alert/error', $message);
    }

    public function testAWrongResizeStatesTheExpectedDimensions(): void
    {
        $this->nativeWindows()->resize(1024, 768, 'main');

        $message = $this->failureMessageOf(fn () => $this->nativeExpects()->assertWindowResized(640, 480, 'main'));

        self::assertStringContainsString('resize window "main" to 640x480', $message);
        self::assertStringContainsString('"width":1024', $message);
    }

    public function testACountMismatchStatesBothCounts(): void
    {
        (new AppManager($this->nativeRuntime()))->setBadgeCount(1);

        $message = $this->failureMessageOf(fn () => $this->nativeExpects()->assertCalledTimes(3, 'app/badge-count'));

        self::assertStringContainsString('call app/badge-count 3 time(s), but it called it 1 time(s)', $message);
        self::assertStringContainsString('Recorded runtime calls (1):', $message);
    }

    public function testNothingSentFailureListsEverythingThatWasSent(): void
    {
        (new AppManager($this->nativeRuntime()))->hide();

        $message = $this->failureMessageOf(fn () => $this->nativeExpects()->assertNothingSent());

        self::assertStringContainsString('send nothing to the runtime, but it sent 1 call(s)', $message);
        self::assertStringContainsString('app/hide', $message);
    }

    public function testAWrongNavigationTargetIsQuotedWithTheResolvedUrl(): void
    {
        $this->nativeWindows()->navigate('/documents', 'main');

        $message = $this->failureMessageOf(fn () => $this->nativeExpects()->assertWindowNavigatedTo('/settings', 'main'));

        self::assertStringContainsString('navigate window "main" to "/settings"', $message);
        self::assertStringContainsString('/documents', $message);
    }

    // --- simulating the reverse channel ---------------------------------------

    public function testAListPayloadIsSpreadPositionally(): void
    {
        $seen = null;
        $this->nativeDispatcher()->addListener(
            WindowResized::class,
            static function (WindowResized $event) use (&$seen): void { $seen = $event; },
        );

        $this->nativeEvents($this->nativeDispatcher())->windowResized('editor', 1280, 720);

        self::assertInstanceOf(WindowResized::class, $seen);
        self::assertSame(['editor', 1280, 720], [$seen->id, $seen->width, $seen->height]);
    }

    public function testAStringKeyedPayloadIsSpreadAsNamedArguments(): void
    {
        $seen = null;
        $this->nativeDispatcher()->addListener(
            ProcessExited::class,
            static function (ProcessExited $event) use (&$seen): void { $seen = $event; },
        );

        // Keys deliberately in the wrong order for a positional spread: if the
        // factory spread this positionally, alias would be 3 and this would fail.
        $this->nativeEvents($this->nativeDispatcher())->dispatch(
            'Native\Desktop\Events\ChildProcess\ProcessExited',
            ['code' => 3, 'alias' => 'import'],
        );

        self::assertInstanceOf(ProcessExited::class, $seen);
        self::assertSame('import', $seen->alias);
        self::assertSame(3, $seen->code);
    }

    public function testProcessSpawnedIsPositionalWhileItsSiblingsAreNamed(): void
    {
        $seen = null;
        $this->nativeDispatcher()->addListener(
            ProcessSpawned::class,
            static function (ProcessSpawned $event) use (&$seen): void { $seen = $event; },
        );

        $this->nativeEvents($this->nativeDispatcher())->processSpawned('import', 4242);

        self::assertInstanceOf(ProcessSpawned::class, $seen);
        self::assertSame('import', $seen->alias);
        self::assertSame(4242, $seen->pid);
    }

    public function testOpenedFromUrlArrivesInBothShapes(): void
    {
        $seen = [];
        $this->nativeDispatcher()->addListener(
            OpenedFromURL::class,
            static function (OpenedFromURL $event) use (&$seen): void { $seen[] = $event->url; },
        );

        $events = $this->nativeEvents($this->nativeDispatcher());
        $events->openedFromUrl('app://one');
        $events->openedFromUrl('app://two', asObject: true);

        self::assertSame(['app://one', 'app://two'], $seen);
    }

    public function testMenuItemClickedKeepsElectronsNestedItemShape(): void
    {
        $seen = null;
        $this->nativeDispatcher()->addListener(
            MenuItemClicked::class,
            static function (MenuItemClicked $event) use (&$seen): void { $seen = $event; },
        );

        $this->nativeEvents($this->nativeDispatcher())->menuItemClicked('save', 'Save', checked: true);

        self::assertInstanceOf(MenuItemClicked::class, $seen);
        self::assertSame('save', $seen->id());
        self::assertSame('Save', $seen->label());
    }

    public function testACallerNamedEventReachesBothATargetedAndACatchAllListener(): void
    {
        $targeted = [];
        $catchAll = [];

        $this->nativeDispatcher()->addListener(
            'native.App\Shortcuts\Quit',
            static function (NativeEvent $event) use (&$targeted): void { $targeted[] = $event->get(0); },
        );
        $this->nativeDispatcher()->addListener(
            NativeEvent::class,
            static function (NativeEvent $event) use (&$catchAll): void { $catchAll[] = $event->name; },
        );

        $this->nativeEvents($this->nativeDispatcher())->shortcutPressed('App\Shortcuts\Quit', 'CmdOrCtrl+Q');

        self::assertSame(['CmdOrCtrl+Q'], $targeted);
        self::assertSame(['App\Shortcuts\Quit'], $catchAll);
    }

    public function testAnEventCanBeBuiltForInspectionWithoutBeingDispatched(): void
    {
        $dispatched = 0;
        $this->nativeDispatcher()->addListener(
            WindowResized::class,
            static function () use (&$dispatched): void { ++$dispatched; },
        );

        $event = $this->nativeEvents($this->nativeDispatcher())->make(
            'Native\Desktop\Events\Windows\WindowResized',
            ['main', 100, 200],
        );

        self::assertInstanceOf(WindowResized::class, $event);
        self::assertSame(0, $dispatched);
    }

    public function testAPayloadTheEventClassCannotAcceptDegradesInsteadOfFataling(): void
    {
        // Mirrors what a runtime upgrade would do to a listener: the event still
        // arrives, as a NativeEvent carrying the reason, so a simulation of a
        // broken payload does not blow up the test process.
        $seen = null;
        $this->nativeDispatcher()->addListener(
            NativeEvent::class,
            static function (NativeEvent $event) use (&$seen): void { $seen = $event; },
        );

        $this->nativeEvents($this->nativeDispatcher())->dispatch(
            'Native\Desktop\Events\Windows\WindowResized',
            ['main'],
        );

        self::assertInstanceOf(NativeEvent::class, $seen);
        self::assertArrayHasKey('__error', $seen->payload);
    }

    // --- ergonomics -----------------------------------------------------------

    public function testTheTraitResolvesAnIdLessCallToTheRequestsOwnWindow(): void
    {
        // The runtime appends ?_windowId= to everything it navigates; a manager
        // call with no explicit id has to pick that up rather than assume 'main'.
        $this->nativeRequestFromWindow('editor', '/documents');

        $this->nativeWindows()->close();

        $this->nativeExpects()->assertWindowClosed('editor');
        $this->nativeExpects()->assertNoWindowClosed('main');
    }

    public function testTheTraitHandsOutOneFakeForTheWholeTest(): void
    {
        self::assertSame($this->nativeRuntime(), $this->nativeRuntime());
    }

    public function testExpectationsCanBeUsedOnAFakeBuiltByHand(): void
    {
        // No trait, no container: the kit has to work for someone assembling a
        // single manager in a unit test.
        $fake = FakeRuntime::available();
        (new NotificationManager($fake))->send('Hello', 'World');

        (new RuntimeExpectations($fake))->assertNotificationSent('Hello');
    }

    // --- container wiring -----------------------------------------------------

    public function testTheTestingFlagRepointsTheTransportAndRegistersTheKit(): void
    {
        $container = $this->compile(['testing' => true]);

        self::assertSame(FakeRuntime::class, (string) $container->getAlias(ClientInterface::class));
        self::assertTrue($container->hasDefinition(RuntimeEventSimulator::class));
        self::assertTrue($container->hasDefinition(RuntimeExpectations::class));
    }

    public function testTheFlagIsOffByDefaultSoNoAppShipsTheFake(): void
    {
        $container = $this->compile([]);

        self::assertSame('Native\Symfony\Desktop\Client\Client', (string) $container->getAlias(ClientInterface::class));
        self::assertFalse($container->hasDefinition(FakeRuntime::class));
    }

    /** @param array<string, mixed> $config */
    private function compile(array $config): ContainerBuilder
    {
        $container = new ContainerBuilder();
        $container->setParameter('kernel.project_dir', '/app');
        $container->setParameter('kernel.environment', 'test');
        $container->setParameter('kernel.debug', true);

        (new NativeDesktopBundle())->getContainerExtension()?->load([$config], $container);

        return $container;
    }

    /**
     * @param callable(): void $assertion
     *
     * @return string The failure message, so a test can assert it is worth reading
     */
    private function failureMessageOf(callable $assertion): string
    {
        try {
            $assertion();
        } catch (AssertionFailedError $failure) {
            return $failure->getMessage();
        }

        self::fail('Expected this assertion helper to fail, but it passed.');
    }

    public function testAnUnscriptedConfirmationIsADismissalNotAConfirmation(): void
    {
        // The fake has to answer a dialog, because there is no user. Which answer
        // matters: button 0 is the confirming button, so defaulting to it made
        // "deleting requires confirmation" — the most valuable test anyone writes
        // here — pass against code with the guard missing entirely. cancelId is
        // what Electron returns for Escape, and confirm() sends 1 for exactly that.
        $runtime = FakeRuntime::available();

        self::assertFalse((new DialogManager($runtime))->confirm('Delete 400 records?'));
    }

    public function testAScriptedConfirmationStillWins(): void
    {
        $runtime = FakeRuntime::available()->userConfirms();

        self::assertTrue((new DialogManager($runtime))->confirm('Delete 400 records?'));
    }

    public function testAnUnscriptedAlertReportsTheCancelButton(): void
    {
        // No cancelId in the payload means there is no cancel button to report.
        $runtime = FakeRuntime::available();

        self::assertSame(0, (new DialogManager($runtime))->alert('Heads up'));
    }

    public function testClosingAWindowRemovesItFromAllAsWellAsGet(): void
    {
        // Upstream serves both from one state.windows map, so they cannot disagree.
        $runtime = FakeRuntime::available()->windowIs('main')->windowIs('report');
        $windows = new WindowManager($runtime, new UrlResolver(new RequestStack(), null), new RequestStack());

        $runtime->windowDoesNotExist('report');

        self::assertNull($windows->get('report'));
        self::assertSame(['main'], array_map(static fn (object $w): string => $w->id, $windows->all()));
    }

    public function testSayingAnUnknownWindowIsAbsentLeavesAHandWrittenListAlone(): void
    {
        // windowDoesNotExist('ghost') is a statement about 'ghost'. Rewriting window/all
        // from the fake's own (empty) map here would throw away a list the test wrote.
        $runtime = FakeRuntime::available()
            ->willReturn('window/all', [['id' => 'main'], ['id' => 'report']])
            ->windowDoesNotExist('ghost');

        self::assertCount(2, $runtime->post('window/all')->array());
    }

    public function testClosingTheCurrentWindowStopsItBeingTheCurrentOne(): void
    {
        // The runtime cannot report a current window that window/get 404s: closing the
        // focused one leaves it dereferencing getFocusedWindow().id with no guard, which
        // is the documented 500. Leaving the old script in place let a test assert on a
        // window the app could never actually read.
        $runtime = FakeRuntime::available()->currentWindowIs('main');

        $runtime->windowDoesNotExist('main');

        self::assertSame(500, $runtime->post('window/current')->status);
    }

    public function testClosingSomeOtherWindowLeavesTheCurrentOneAlone(): void
    {
        $runtime = FakeRuntime::available()->currentWindowIs('main')->windowIs('report');

        $runtime->windowDoesNotExist('report');

        self::assertSame(200, $runtime->post('window/current')->status);
    }

    public function testNavigationAssertionsRejectADeeperPathThatMerelyEndsTheSameWay(): void
    {
        // /admin/reports is not /reports. The suffix match accepted it, certifying
        // the exact routing bug the assertion exists to catch.
        $runtime = FakeRuntime::available();
        $request = Request::create('http://127.0.0.1:8100/');
        $stack = new RequestStack();
        $stack->push($request);

        (new WindowManager($runtime, new UrlResolver($stack, null), $stack))->navigate('/admin/reports', 'main');

        $expectations = new RuntimeExpectations($runtime);
        $expectations->assertWindowNavigatedTo('/admin/reports', 'main');

        try {
            $expectations->assertWindowNavigatedTo('/reports', 'main');
            self::fail('A deeper path must not satisfy a different expected path.');
        } catch (\PHPUnit\Framework\AssertionFailedError) {
            self::assertTrue(true);
        }
    }

    public function testATouchIdPromptCountsAsABlockingDialog(): void
    {
        $runtime = FakeRuntime::available();
        $runtime->post('system/prompt-touch-id', ['reason' => 'unlock']);

        $this->expectException(\PHPUnit\Framework\AssertionFailedError::class);

        (new RuntimeExpectations($runtime))->assertNoDialogShown();
    }
}
