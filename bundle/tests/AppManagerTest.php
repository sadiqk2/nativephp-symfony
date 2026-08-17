<?php

declare(strict_types=1);

namespace Native\Symfony\Desktop\Tests;

use Native\Symfony\Desktop\App\AppManager;
use Native\Symfony\Desktop\App\AppPath;
use PHPUnit\Framework\TestCase;

final class AppManagerTest extends TestCase
{
    public function testPathAcceptsTheEnumAndAString(): void
    {
        $client = new FakeClient();
        $client->willReturn('app/path/downloads', ['path' => '/home/u/Downloads']);
        $manager = new AppManager($client);

        self::assertSame('/home/u/Downloads', $manager->path(AppPath::Downloads));
        self::assertSame('/home/u/Downloads', $manager->path('downloads'));
    }

    public function testReadersUnwrapTheRuntimesKeys(): void
    {
        $client = new FakeClient();
        $client->willReturn('app/version', ['version' => '2.0.1']);
        $client->willReturn('app/is-hidden', ['is_hidden' => true]);
        $client->willReturn('app/badge-count', ['count' => 7]);
        $client->willReturn('app/locale-country-code', ['locale_country_code' => 'NL']);
        $client->willReturn('app/open-at-login', ['open' => true]);
        $manager = new AppManager($client);

        self::assertSame('2.0.1', $manager->version());
        self::assertTrue($manager->isHidden());
        self::assertSame(7, $manager->badgeCount());
        self::assertSame('NL', $manager->localeCountryCode());
        self::assertTrue($manager->opensAtLogin());
    }

    public function testMissingKeysFallBackInsteadOfReturningNull(): void
    {
        $manager = new AppManager(new FakeClient());

        self::assertSame('', $manager->version());
        self::assertSame(0, $manager->badgeCount());
        self::assertFalse($manager->isHidden());
    }

    public function testRelaunchToleratesNeverReceivingAResponse(): void
    {
        // app/relaunch relaunches and quits without answering; awaiting it would
        // hang until the process dies.
        $client = new class implements \Native\Symfony\Desktop\Contract\ClientInterface {
            public function isAvailable(): bool
            {
                return true;
            }

            public function get(string $endpoint, array $query = [], ?int $timeout = null): \Native\Symfony\Desktop\Contract\Response
            {
                throw new \LogicException('not used');
            }

            public function post(string $endpoint, array $data = [], ?int $timeout = null): \Native\Symfony\Desktop\Contract\Response
            {
                throw new \RuntimeException('connection reset');
            }

            public function delete(string $endpoint, array $data = [], ?int $timeout = null): \Native\Symfony\Desktop\Contract\Response
            {
                throw new \LogicException('not used');
            }
        };

        (new AppManager($client))->relaunch();

        $this->expectNotToPerformAssertions();
    }

    public function testClearRecentDocumentsUsesDelete(): void
    {
        $client = new FakeClient();
        (new AppManager($client))->clearRecentDocuments();

        self::assertSame('DELETE', $client->lastCall()['method']);
        self::assertSame('app/recent-documents', $client->lastCall()['endpoint']);
    }
}
