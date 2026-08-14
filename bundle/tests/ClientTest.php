<?php

declare(strict_types=1);

namespace Native\Symfony\Tests;

use Native\Symfony\Client\Client;
use Native\Symfony\Client\RuntimeCallFailed;
use Native\Symfony\Client\RuntimeNotAvailable;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class ClientTest extends TestCase
{
    public function testItSendsTheSecretOnEveryRequest(): void
    {
        $seen = [];
        $http = new MockHttpClient(function (string $method, string $url, array $options) use (&$seen) {
            $seen = ['method' => $method, 'url' => $url, 'headers' => $options['headers'] ?? []];

            return new MockResponse('', ['http_code' => 200]);
        });

        $this->client($http)->post('window/resize', ['id' => 'main']);

        self::assertSame('POST', $seen['method']);
        self::assertSame('http://127.0.0.1:4000/api/window/resize', $seen['url']);
        self::assertContains('X-NativePHP-Secret: s3cret', $seen['headers']);
    }

    public function testBaseUrlAndEndpointSlashesAreNormalised(): void
    {
        $urls = [];
        $http = new MockHttpClient(function (string $method, string $url) use (&$urls) {
            $urls[] = $url;

            return new MockResponse('', ['http_code' => 200]);
        });

        // The runtime supplies NATIVEPHP_API_URL already ending in /api/; a
        // double slash would 404 against express' router.
        $client = new Client($http, 'http://127.0.0.1:4000/api/', 's3cret');
        $client->get('window/all');
        $client->get('/window/all');

        self::assertSame([
            'http://127.0.0.1:4000/api/window/all',
            'http://127.0.0.1:4000/api/window/all',
        ], $urls);
    }

    public function testABareTwoHundredWithNoBodyIsNotAnError(): void
    {
        // Most mutations answer exactly like this.
        $response = $this->client(new MockHttpClient(new MockResponse('', ['http_code' => 200])))
            ->post('window/close', ['id' => 'main']);

        self::assertTrue($response->successful());
        self::assertNull($response->data);
        self::assertSame([], $response->array());
    }

    public function testJsonBodiesAreDecoded(): void
    {
        $response = $this->client(new MockHttpClient(new MockResponse('{"version":"1.2.3"}')))
            ->get('app/version');

        self::assertSame('1.2.3', $response->value('version'));
    }

    public function testForbiddenIsRaisedWithAnExplanation(): void
    {
        $this->expectException(RuntimeCallFailed::class);
        $this->expectExceptionMessageMatches('/did not match/');

        $this->client(new MockHttpClient(new MockResponse('', ['http_code' => 403])))
            ->get('app/version');
    }

    public function testCallingTheRuntimeOutsideItIsALogicError(): void
    {
        $this->expectException(RuntimeNotAvailable::class);
        $this->expectExceptionMessageMatches('/was not started by the runtime/');

        (new Client(new MockHttpClient(), null, null))->get('app/version');
    }

    public function testExpressStatusPhraseBodiesAreTreatedAsNoData(): void
    {
        // res.sendStatus(200) sends the literal body "OK", and sendStatus(404)
        // sends "Not Found". Those are the runtime's normal bodyless replies.
        $ok = $this->client(new MockHttpClient(new MockResponse('OK', ['http_code' => 200])))
            ->post('window/resize', ['id' => 'main']);

        self::assertTrue($ok->successful());
        self::assertNull($ok->data);

        $missing = $this->client(new MockHttpClient(new MockResponse('Not Found', ['http_code' => 404])))
            ->get('window/get/ghost');

        self::assertSame(404, $missing->status);
        self::assertNull($missing->data);
    }

    public function testGenuinelyUnexpectedBodiesAreLoggedRatherThanSwallowed(): void
    {
        // An HTML error page is not a status phrase. Silently treating "the
        // runtime did not answer" as "the runtime said nothing" is the most
        // expensive failure mode in this system.
        $logger = new class extends AbstractLogger {
            /** @var list<string> */
            public array $records = [];

            public function log($level, $message, array $context = []): void
            {
                $this->records[] = (string) $level;
            }
        };

        $response = (new Client(
            new MockHttpClient(new MockResponse('<html>nope</html>')),
            'http://127.0.0.1:4000/api/',
            's3cret',
            $logger,
        ))->get('app/version');

        self::assertNull($response->data);
        self::assertSame(['error'], $logger->records);
    }

    public function testUnavailableWhenTheApiUrlIsEmpty(): void
    {
        self::assertFalse((new Client(new MockHttpClient(), '', null))->isAvailable());
        self::assertFalse((new Client(new MockHttpClient(), null, null))->isAvailable());
        self::assertTrue((new Client(new MockHttpClient(), 'http://x/api/', null))->isAvailable());
    }

    private function client(MockHttpClient $http): Client
    {
        return new Client($http, 'http://127.0.0.1:4000/api/', 's3cret');
    }
}
