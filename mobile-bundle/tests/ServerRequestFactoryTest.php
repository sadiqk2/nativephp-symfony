<?php

declare(strict_types=1);

namespace Native\Symfony\Mobile\Tests;

use Native\Symfony\Mobile\Runtime\ServerRequestFactory;
use PHPUnit\Framework\TestCase;

/**
 * The SAPI shim is the one piece of mobile that cannot be checked by inspection —
 * it substitutes for a web server, and getting it subtly wrong produces an app that
 * mostly works until a POST or a cookie goes missing. These tests are the substitute
 * for the device run this environment cannot do.
 */
final class ServerRequestFactoryTest extends TestCase
{
    public function testItBuildsABasicGet(): void
    {
        [$request] = ServerRequestFactory::fromServer([
            'REQUEST_METHOD' => 'GET',
            'REQUEST_URI' => '/dashboard',
            'HTTP_HOST' => 'localhost',
        ]);

        self::assertSame('GET', $request->getMethod());
        self::assertSame('/dashboard', $request->getPathInfo());
    }

    public function testQueryStringIsAuthoritativeEvenOnAPost(): void
    {
        // The native layer supplies QUERY_STRING separately, and it carries query
        // parameters on POSTs where the URI may not. Laravel's bootstrap has a
        // comment flagging exactly this; losing it breaks paginated form posts.
        [$request] = ServerRequestFactory::fromServer([
            'REQUEST_METHOD' => 'POST',
            'REQUEST_URI' => '/search',
            'QUERY_STRING' => 'page=3&sort=name',
        ]);

        self::assertSame('3', $request->query->get('page'));
        self::assertSame('name', $request->query->get('sort'));
    }

    public function testCookiesAreParsedIncludingWithoutSpacesAfterSemicolons(): void
    {
        // Upstream splits on '; ' — a client that omits the space silently loses
        // every cookie after the first, which on a device means being logged out
        // with no clue why.
        [$request, $cookies] = ServerRequestFactory::fromServer([
            'REQUEST_METHOD' => 'GET',
            'REQUEST_URI' => '/',
            'HTTP_COOKIE' => 'PHPSESSID=abc123;remember=yes; theme=dark',
        ]);

        self::assertSame('abc123', $request->cookies->get('PHPSESSID'));
        self::assertSame('yes', $request->cookies->get('remember'));
        self::assertSame('dark', $request->cookies->get('theme'));
        self::assertCount(3, $cookies);
    }

    public function testCookieValuesAreUrlDecoded(): void
    {
        [$request] = ServerRequestFactory::fromServer([
            'REQUEST_METHOD' => 'GET',
            'REQUEST_URI' => '/',
            'HTTP_COOKIE' => 'redirect=%2Fadmin%2Fusers%3Fpage%3D2',
        ]);

        self::assertSame('/admin/users?page=2', $request->cookies->get('redirect'));
    }

    public function testMalformedCookiePairsAreSkippedNotFatal(): void
    {
        [$request] = ServerRequestFactory::fromServer([
            'REQUEST_METHOD' => 'GET',
            'REQUEST_URI' => '/',
            'HTTP_COOKIE' => 'valid=1; garbage; =novalue; other=2',
        ]);

        self::assertSame('1', $request->cookies->get('valid'));
        self::assertSame('2', $request->cookies->get('other'));
        self::assertFalse($request->cookies->has('garbage'));
    }

    public function testFormEncodedBodiesArePopulatedIntoRequestParameters(): void
    {
        [$request] = ServerRequestFactory::fromServer([
            'REQUEST_METHOD' => 'POST',
            'REQUEST_URI' => '/login',
            'CONTENT_TYPE' => 'application/x-www-form-urlencoded',
        ], 'email=a%40b.test&password=hunter2');

        self::assertSame('a@b.test', $request->request->get('email'));
        self::assertSame('hunter2', $request->request->get('password'));
    }

    public function testJsonBodiesAreLeftUntouchedForTheApplicationToRead(): void
    {
        // Parsing JSON here would break Symfony's own toArray() and any request
        // that needs the raw body — a signed webhook, for instance.
        $json = '{"name":"Ada","tags":["a","b"]}';

        [$request] = ServerRequestFactory::fromServer([
            'REQUEST_METHOD' => 'POST',
            'REQUEST_URI' => '/api/people',
            'CONTENT_TYPE' => 'application/json',
        ], $json);

        self::assertSame($json, $request->getContent());
        self::assertSame([], $request->request->all());
        self::assertSame('Ada', $request->toArray()['name']);
    }

    public function testMultipartBodiesAreNotParsedAsForms(): void
    {
        $body = "------x\r\nContent-Disposition: form-data; name=\"a\"\r\n\r\n1\r\n------x--\r\n";

        [$request] = ServerRequestFactory::fromServer([
            'REQUEST_METHOD' => 'POST',
            'REQUEST_URI' => '/upload',
            'CONTENT_TYPE' => 'multipart/form-data; boundary=----x',
        ], $body);

        self::assertSame([], $request->request->all());
        self::assertSame($body, $request->getContent());
    }

    public function testHeadersFromTheNativeLayerSurvive(): void
    {
        [$request] = ServerRequestFactory::fromServer([
            'REQUEST_METHOD' => 'GET',
            'REQUEST_URI' => '/',
            'HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest',
            'HTTP_ACCEPT' => 'application/json',
        ]);

        self::assertTrue($request->isXmlHttpRequest());
        self::assertSame('application/json', $request->headers->get('Accept'));
    }

    public function testAnAbsoluteRequestUriIsUsedAsGiven(): void
    {
        [$request] = ServerRequestFactory::fromServer([
            'REQUEST_METHOD' => 'GET',
            'REQUEST_URI' => 'http://example.test/a/b',
        ]);

        self::assertSame('example.test', $request->getHost());
        self::assertSame('/a/b', $request->getPathInfo());
    }

    public function testAMissingMethodDefaultsToGetRatherThanFailing(): void
    {
        [$request] = ServerRequestFactory::fromServer(['REQUEST_URI' => '/']);

        self::assertSame('GET', $request->getMethod());
    }
}
