<?php

declare(strict_types=1);

namespace Native\Symfony\Tests;

use Native\Symfony\Client\RuntimeCallFailed;
use Native\Symfony\Dialog\DialogManager;
use Native\Symfony\EventBridge\EventFactory;
use Native\Symfony\Event\AutoUpdater\UpdateAvailable;
use Native\Symfony\Process\ProcessHandle;
use Native\Symfony\Shell\ShellManager;
use Native\Symfony\Window\UrlResolver;
use Native\Symfony\Window\WindowManager;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * What each wrapper does when the runtime's answer cannot be trusted.
 *
 * `Client` only throws on a 403 or a transport error, so a 400 or a 500 arrives
 * as an ordinary Response carrying no data. Every case below used to turn that
 * into a plausible, specific, wrong answer — which is the failure mode this whole
 * runtime is prone to, and the one worth pinning hardest.
 */
final class FailingRuntimeAnswersTest extends TestCase
{
    public function testConfirmDoesNotAnswerYesWhenTheDialogNeverAppeared(): void
    {
        // The worst of them. Button 0 is the confirming button, so defaulting the
        // result to 0 meant `confirm('Permanently delete these records?')` returned
        // true after a 500 — a destructive action approved by a question nobody saw.
        $client = new FakeClient();
        $client->willReturnStatus('alert/message', 500);

        $this->expectException(RuntimeCallFailed::class);
        $this->expectExceptionMessageMatches('/Refusing to guess/');

        (new DialogManager($client))->confirm('Permanently delete 400 records?');
    }

    public function testAlertStillReturnsAScriptedButtonIndex(): void
    {
        $client = new FakeClient();
        $client->willReturn('alert/message', ['result' => 2]);

        self::assertSame(2, (new DialogManager($client))->alert('Pick one', ['a', 'b', 'c']));
        self::assertFalse((new DialogManager($client))->confirm('Sure?'));
    }

    public function testGetReturnsNullRatherThanAPhantomWindow(): void
    {
        // Window::fromRuntime([]) is a perfectly confident window: empty id, zero
        // size, every flag false. A caller reading ->closable off it is told "no"
        // by a window that does not exist.
        $client = new FakeClient();
        $client->willReturnStatus('window/get/editor', 500);

        $windows = new WindowManager($client, new UrlResolver(new RequestStack(), null), new RequestStack());

        self::assertNull($windows->get('editor'));
    }

    public function testOpenPathCannotReportSuccessWhenTheCallFailed(): void
    {
        // This endpoint's contract is "empty string means it worked", so defaulting
        // to an empty string on failure says exactly the wrong thing.
        $client = new FakeClient();
        $client->willReturnStatus('shell/open-item', 500);

        self::assertNotSame('', (new ShellManager($client))->openPath('/tmp/report.pdf'));
    }

    public function testAProcessThatFailedToForkIsDistinguishable(): void
    {
        // The success path also returns a null pid, because the runtime answers
        // before Electron's spawn event fires — so without the error field there is
        // nothing to tell "about to start" from "never started".
        $starting = ProcessHandle::fromRuntime('report', ['pid' => null, 'settings' => []]);
        $failed = ProcessHandle::fromRuntime('report', ['pid' => null, 'settings' => [], 'error' => 'spawn ENOENT']);

        self::assertFalse($starting->failed());
        self::assertTrue($failed->failed());
        self::assertSame('spawn ENOENT', $failed->error);
    }

    public function testUpdaterEventsSurviveARealisticElectronUpdaterPayload(): void
    {
        // electron-updater leaves most of UpdateInfo undefined, and JSON.stringify
        // drops undefined keys. The payload is spread as named arguments, so those
        // become missing arguments — every updater event degraded to NativeEvent
        // and no typed listener ran.
        $payload = json_decode(
            '{"version":"1.4.0","files":[{"url":"App-1.4.0.dmg"}],'.
            '"releaseDate":"2026-08-15T00:00:00.000Z","releaseName":"1.4.0","releaseNotes":null}',
            true,
        );

        $event = (new EventFactory([]))->create('\\Native\\Desktop\\Events\\AutoUpdater\\UpdateAvailable', $payload);

        self::assertInstanceOf(UpdateAvailable::class, $event);
        self::assertSame('1.4.0', $event->version);
        self::assertNull($event->stagingPercentage);
    }

    public function testEveryUpdaterEventAcceptsTheMinimumPayload(): void
    {
        $minimum = ['version' => '2.0.0', 'files' => []];

        foreach ([
            'UpdateAvailable' => $minimum,
            'UpdateNotAvailable' => $minimum,
            'UpdateCancelled' => $minimum,
            'UpdateDownloaded' => ['downloadedFile' => '/tmp/App.dmg'] + $minimum,
            'Error' => ['name' => 'Error', 'message' => 'network down'],
        ] as $name => $payload) {
            $event = (new EventFactory([]))->create("\\Native\\Desktop\\Events\\AutoUpdater\\{$name}", $payload);

            self::assertSame(
                'Native\\Symfony\\Event\\AutoUpdater\\'.$name,
                $event::class,
                sprintf('%s degraded instead of constructing.', $name),
            );
        }
    }
}
