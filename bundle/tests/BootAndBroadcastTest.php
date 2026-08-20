<?php

declare(strict_types=1);

namespace Native\Symfony\Desktop\Tests;

use Native\Symfony\Desktop\Command\ConfigCommand;
use Native\Symfony\Desktop\Command\PhpIniCommand;
use Native\Symfony\Desktop\Command\ScheduleTickCommand;
use Native\Symfony\Desktop\Contract\AppBootstrapper;
use Native\Symfony\Desktop\Contract\BroadcastsToRuntime;
use Native\Symfony\Desktop\Contract\ProvidesPhpIni;
use Native\Symfony\Desktop\Event\App\ApplicationBooted;
use Native\Symfony\Desktop\EventBridge\BroadcastingDispatcher;
use Native\Symfony\Desktop\EventBridge\RuntimeBroadcaster;
use Native\Symfony\Desktop\Http\BootedController;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Contracts\EventDispatcher\Event;

/**
 * The three things the runtime asks PHP for at boot, and the one thing PHP pushes back.
 *
 * All four were uncovered, and all four fail silently by design — which is the argument
 * for testing them rather than the argument against. `native:config` runs before the API
 * server exists and its stdout is JSON.parse()d: anything else on that stream and the
 * runtime logs an error, carries on with an empty config, and the app launches with no
 * id, no deep links and no updater. `/booted` is where a window comes from, and the
 * runtime swallows non-2xx. A failed broadcast must not take down the dispatch that
 * caused it, so it is caught and logged where nobody looks.
 */
final class BootAndBroadcastTest extends TestCase
{
    // ── native:config ───────────────────────────────────────────────────────

    public function testConfigPrintsNothingButJson(): void
    {
        $config = [
            'app_id' => 'com.acme.pad',
            'version' => '1.2.3',
            'deeplink_scheme' => 'deskpad',
            'updater' => ['enabled' => false, 'default' => 'github', 'providers' => []],
        ];

        $tester = new CommandTester(new ConfigCommand($config));

        self::assertSame(Command::SUCCESS, $tester->execute([]));

        $display = $tester->getDisplay();

        // No banner, no trailing newline, no formatter decoration: JSON.parse() gets the
        // whole stream, not a well-formed prefix of it.
        self::assertSame(json_encode($config, \JSON_UNESCAPED_SLASHES), $display);
        self::assertSame($config, json_decode($display, true, 512, \JSON_THROW_ON_ERROR));
    }

    public function testConfigLeavesSlashesReadable(): void
    {
        // Only cosmetic on the wire, but this output is the first thing a developer reads
        // when the runtime says the config is wrong.
        $tester = new CommandTester(new ConfigCommand(['website' => 'https://example.test/pad']));
        $tester->execute([]);

        self::assertStringContainsString('https://example.test/pad', $tester->getDisplay());
    }

    public function testConfigSurvivesAnEmptyConfiguration(): void
    {
        $tester = new CommandTester(new ConfigCommand([]));
        $tester->execute([]);

        // An empty *object*, not `[]`… PHP cannot tell the difference, and the runtime
        // reads it with optional chaining either way — so what matters is that it parses.
        self::assertIsArray(json_decode($tester->getDisplay(), true, 512, \JSON_THROW_ON_ERROR));
    }

    // ── native:php-ini ──────────────────────────────────────────────────────

    public function testPhpIniPrintsTheConfiguredOverrides(): void
    {
        $tester = new CommandTester(new PhpIniCommand(['memory_limit' => '512M']));
        $tester->execute([]);

        self::assertSame(['memory_limit' => '512M'], json_decode($tester->getDisplay(), true, 512, \JSON_THROW_ON_ERROR));
    }

    public function testAnApplicationProviderWinsOverConfiguration(): void
    {
        // Whatever this prints is passed as -d flags to every PHP process the runtime
        // spawns, so the last word has to belong to the application's own provider.
        $provider = new class implements ProvidesPhpIni {
            public function phpIni(): array
            {
                return ['memory_limit' => '1G', 'max_execution_time' => '0'];
            }
        };

        $tester = new CommandTester(new PhpIniCommand(['memory_limit' => '512M'], $provider));
        $tester->execute([]);

        self::assertSame(
            ['memory_limit' => '1G', 'max_execution_time' => '0'],
            json_decode($tester->getDisplay(), true, 512, \JSON_THROW_ON_ERROR),
        );
    }

    public function testPhpIniWithNothingConfiguredStillPrintsParseableJson(): void
    {
        $tester = new CommandTester(new PhpIniCommand());
        $tester->execute([]);

        // `[]` rather than `{}`, because PHP cannot tell an empty map from an empty list.
        // Harmless on the other side — the runtime does
        // `Object.assign(getDefaultPhpIniSettings(), settings)`, and assigning an empty
        // array changes nothing — but it is the sort of thing worth pinning rather than
        // rediscovering.
        self::assertSame('[]', $tester->getDisplay());
        self::assertSame([], json_decode($tester->getDisplay(), true, 512, \JSON_THROW_ON_ERROR));
    }

    // ── native:schedule-tick ────────────────────────────────────────────────

    public function testTheSchedulerTickSucceedsSilently(): void
    {
        // The runtime spawns this every minute without checking it exists, so the default
        // has to be a clean no-op — an error here is an error a minute, forever.
        $tester = new CommandTester(new ScheduleTickCommand());

        self::assertSame(Command::SUCCESS, $tester->execute([]));
        self::assertSame('', $tester->getDisplay());
    }

    // ── POST /_native/api/booted ────────────────────────────────────────────

    public function testBootingRunsTheApplicationsBootstrapper(): void
    {
        $bootstrapper = new class implements AppBootstrapper {
            public int $booted = 0;

            public function boot(): void
            {
                ++$this->booted;
            }
        };

        $dispatcher = new EventDispatcher();
        $seen = 0;
        $dispatcher->addListener(ApplicationBooted::class, static function () use (&$seen): void { ++$seen; });

        $response = (new BootedController($dispatcher, $bootstrapper))();

        self::assertSame(200, $response->getStatusCode());
        self::assertSame(['success' => true], json_decode((string) $response->getContent(), true, 512, \JSON_THROW_ON_ERROR));
        self::assertSame(1, $bootstrapper->booted);
        self::assertSame(1, $seen, 'ApplicationBooted is the hook an app uses instead of implementing the contract twice.');
    }

    public function testBootingTwiceBootsTwice(): void
    {
        // The runtime posts here again on macOS `activate` with no windows open, so this
        // is a normal occurrence rather than a fault — the idempotence lives in
        // WindowManager::open(), not here.
        $bootstrapper = new class implements AppBootstrapper {
            public int $booted = 0;

            public function boot(): void
            {
                ++$this->booted;
            }
        };

        $controller = new BootedController(new EventDispatcher(), $bootstrapper);
        $controller();
        $controller();

        self::assertSame(2, $bootstrapper->booted);
    }

    public function testWithoutABootstrapperItSaysSoWhereSomeoneWillSeeIt(): void
    {
        // The runtime discards this response, so the log line is the only diagnostic
        // between a developer and an app that opens nothing and looks hung.
        $logger = new class extends AbstractLogger {
            /** @var list<string> */
            public array $messages = [];

            public function log($level, \Stringable|string $message, array $context = []): void
            {
                $this->messages[] = strtr((string) $message, ['{contract}' => (string) ($context['contract'] ?? '')]);
            }
        };

        $response = (new BootedController(new EventDispatcher(), null, $logger))();

        self::assertSame(500, $response->getStatusCode());
        // Decoded, because the body is JSON and the contract's separators arrive escaped.
        /** @var array{error: string} $body */
        $body = json_decode((string) $response->getContent(), true, 512, \JSON_THROW_ON_ERROR);

        self::assertStringContainsString(AppBootstrapper::class, $body['error']);
        self::assertNotSame([], $logger->messages);
        self::assertStringContainsString(AppBootstrapper::class, $logger->messages[0]);
    }

    // ── app → runtime broadcasts ────────────────────────────────────────────

    public function testABroadcastingEventReachesTheRuntimeAfterItsListeners(): void
    {
        $client = new FakeClient();
        $dispatcher = new BroadcastingDispatcher(new EventDispatcher(), new RuntimeBroadcaster($client));

        $dispatcher->addListener(BroadcastProbe::class, static function (BroadcastProbe $event): void {
            // Listeners run first on purpose: one of them may be what fills the payload.
            $event->note = 'enriched';
        });

        $dispatcher->dispatch(new BroadcastProbe());

        self::assertCount(1, $client->calls);
        self::assertSame('broadcast', $client->calls[0]['endpoint']);
        self::assertSame('\\'.BroadcastProbe::class, $client->calls[0]['data']['event']);
        self::assertSame(['note' => 'enriched'], $client->calls[0]['data']['payload']);
    }

    public function testAnOrdinaryEventIsNotBroadcast(): void
    {
        $client = new FakeClient();
        $dispatcher = new BroadcastingDispatcher(new EventDispatcher(), new RuntimeBroadcaster($client));

        $dispatcher->dispatch(new \stdClass());

        self::assertSame([], $client->calls);
    }

    public function testAStoppedEventIsNotBroadcast(): void
    {
        // A listener that stops propagation has vetoed the event; sending it to every
        // renderer anyway would make the veto meaningless on the one side that shows it.
        $client = new FakeClient();
        $dispatcher = new BroadcastingDispatcher(new EventDispatcher(), new RuntimeBroadcaster($client));

        $dispatcher->addListener(StoppableBroadcastProbe::class, static function (StoppableBroadcastProbe $event): void {
            $event->stopPropagation();
        });

        $dispatcher->dispatch(new StoppableBroadcastProbe());

        self::assertSame([], $client->calls);
    }

    public function testNothingIsSentWhenTheAppIsNotRunningInTheRuntime(): void
    {
        // An ordinary web request through the same kernel: there is no runtime to talk to.
        $client = new FakeClient(available: false);

        (new RuntimeBroadcaster($client))->broadcast(new BroadcastProbe());

        self::assertSame([], $client->calls);
    }

    public function testAFailedBroadcastIsLoggedRatherThanThrown(): void
    {
        $client = new UnreachableClient();

        $logger = new class extends AbstractLogger {
            /** @var list<string> */
            public array $messages = [];

            public function log($level, \Stringable|string $message, array $context = []): void
            {
                $this->messages[] = strtr((string) $message, [
                    '{event}' => (string) ($context['event'] ?? ''),
                    '{error}' => (string) ($context['error'] ?? ''),
                ]);
            }
        };

        (new RuntimeBroadcaster($client, $logger))->broadcast(new BroadcastProbe());

        self::assertCount(1, $logger->messages);
        self::assertStringContainsString('connection refused', $logger->messages[0]);
        self::assertStringContainsString(BroadcastProbe::class, $logger->messages[0]);
    }

    public function testTheDecoratorStillBehavesLikeADispatcher(): void
    {
        // The container calls addListener()/addSubscriber() on this object while compiling.
        // A decorator that only implemented dispatch() broke the build with "Attempted to
        // call an undefined method", so the delegation is load-bearing, not politeness.
        $inner = new EventDispatcher();
        $dispatcher = new BroadcastingDispatcher($inner, new RuntimeBroadcaster(new FakeClient()));

        $listener = static function (): void {};
        $dispatcher->addListener('probe', $listener, 7);

        self::assertTrue($dispatcher->hasListeners('probe'));
        self::assertSame(7, $dispatcher->getListenerPriority('probe', $listener));
        self::assertSame([$listener], $dispatcher->getListeners('probe'));

        $dispatcher->removeListener('probe', $listener);

        self::assertFalse($dispatcher->hasListeners('probe'));
        self::assertFalse($inner->hasListeners('probe'));
    }
}

/** A runtime that is there right up until you talk to it — a window closing mid-post. */
final class UnreachableClient implements \Native\Symfony\Desktop\Contract\ClientInterface
{
    public function isAvailable(): bool
    {
        return true;
    }

    public function get(string $endpoint, array $query = [], ?int $timeout = null): \Native\Symfony\Desktop\Contract\Response
    {
        throw new \RuntimeException('connection refused');
    }

    public function post(string $endpoint, array $data = [], ?int $timeout = null): \Native\Symfony\Desktop\Contract\Response
    {
        throw new \RuntimeException('connection refused');
    }

    public function delete(string $endpoint, array $data = [], ?int $timeout = null): \Native\Symfony\Desktop\Contract\Response
    {
        throw new \RuntimeException('connection refused');
    }
}

final class BroadcastProbe implements BroadcastsToRuntime
{
    public string $note = 'plain';

    public function broadcastAs(): string
    {
        return self::class;
    }

    public function broadcastPayload(): array
    {
        return ['note' => $this->note];
    }
}

final class StoppableBroadcastProbe extends Event implements BroadcastsToRuntime
{
    public function broadcastAs(): string
    {
        return self::class;
    }

    public function broadcastPayload(): array
    {
        return [];
    }
}
