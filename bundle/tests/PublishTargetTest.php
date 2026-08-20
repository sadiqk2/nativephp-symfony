<?php

declare(strict_types=1);

namespace Native\Symfony\Desktop\Tests;

use Native\Symfony\Desktop\Updater\PublishTarget;
use Native\Symfony\Desktop\Updater\UpdaterMisconfigured;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * What `native:build` tells electron-builder to publish with.
 *
 * The same comparison that the payload contract makes for HTTP, one layer down: an
 * environment variable is a wire format too. `electron-builder.mjs` does
 * `...(updaterEnabled ? { publish: updaterConfig } : {})`, so `NATIVEPHP_UPDATER_CONFIG`
 * has to be a publish provider and `NATIVEPHP_UPDATER_ENABLED` has to be the string
 * 'true' — and until this class existed we sent the runtime's config tree for the first
 * and nothing at all for the second, which meant `--publish` published nothing and no
 * update manifest was ever written.
 *
 * The provider options are checked against upstream's own three provider classes rather
 * than a list written here, because "the same keys upstream sends" is the actual
 * requirement and a hand-copied list drifts silently.
 */
final class PublishTargetTest extends TestCase
{
    private const UPSTREAM = __DIR__.'/../../upstream/np-desktop/src/Drivers/Electron/Updater';

    public function testTheUpdaterIsOffByDefault(): void
    {
        self::assertNull(PublishTarget::fromConfig([]));
        self::assertNull(PublishTarget::fromConfig(['enabled' => false, 'default' => 'github', 'providers' => []]));
    }

    public function testAGithubProviderBecomesAGithubPublishTarget(): void
    {
        $target = PublishTarget::fromConfig([
            'enabled' => true,
            'default' => 'github',
            'providers' => [
                'github' => ['driver' => 'github', 'owner' => 'sadiqk2', 'repo' => 'deskpad', 'token' => 'ghp_x'],
            ],
        ]);

        self::assertNotNull($target);
        self::assertSame('github', $target->builderOptions()['provider']);
        self::assertSame('sadiqk2', $target->builderOptions()['owner']);
        self::assertSame('deskpad', $target->builderOptions()['repo']);
        // Upstream's defaults, so a build here and a build there produce the same release.
        self::assertTrue($target->builderOptions()['vPrefixedTagName']);
        self::assertSame('draft', $target->builderOptions()['releaseType']);
        self::assertSame(['GH_TOKEN' => 'ghp_x'], $target->environmentVariables());
    }

    public function testAPrivateRepositoryCarriesTheReadTokenIntoTheApp(): void
    {
        // The upload token and the token the installed app reads releases with are two
        // different things; only the second belongs in the published metadata.
        $target = PublishTarget::fromConfig($this->config([
            'driver' => 'github', 'owner' => 'a', 'repo' => 'b',
            'private' => true, 'autoupdate_token' => 'ghp_read', 'token' => 'ghp_write',
        ]));

        self::assertSame('ghp_read', $target?->builderOptions()['token']);
        self::assertSame(['GH_TOKEN' => 'ghp_write'], $target?->environmentVariables());
    }

    public function testAPublicRepositoryShipsNoToken(): void
    {
        $target = PublishTarget::fromConfig($this->config([
            'driver' => 'github', 'owner' => 'a', 'repo' => 'b', 'autoupdate_token' => 'ghp_read',
        ]));

        self::assertArrayNotHasKey('token', (array) $target?->builderOptions());
    }

    public function testAnS3ProviderCarriesItsCredentialsInTheEnvironment(): void
    {
        $target = PublishTarget::fromConfig($this->config([
            'driver' => 's3', 'bucket' => 'releases', 'region' => 'eu-west-1',
            'endpoint' => 'https://s3.example', 'path' => 'deskpad',
            'key' => 'AKIA', 'secret' => 'shhh',
        ]));

        self::assertSame('s3', $target?->builderOptions()['provider']);
        self::assertSame('releases', $target?->builderOptions()['bucket']);
        self::assertSame(['AWS_ACCESS_KEY_ID' => 'AKIA', 'AWS_SECRET_ACCESS_KEY' => 'shhh'], $target?->environmentVariables());
    }

    public function testASpacesProviderUsesDigitalOceansVariables(): void
    {
        $target = PublishTarget::fromConfig($this->config([
            'driver' => 'spaces', 'name' => 'deskpad', 'region' => 'ams3', 'path' => '/', 'key' => 'k', 'secret' => 's',
        ]));

        self::assertSame('spaces', $target?->builderOptions()['provider']);
        self::assertSame(['DO_KEY_ID' => 'k', 'DO_SECRET_KEY' => 's'], $target?->environmentVariables());
    }

    public function testAnAbsentCredentialIsOmittedRatherThanSentEmpty(): void
    {
        // An empty AWS_SECRET_ACCESS_KEY in the environment overrides the one the
        // developer already has, and then the failure blames the wrong thing.
        $target = PublishTarget::fromConfig($this->config(['driver' => 's3', 'bucket' => 'releases']));

        self::assertSame([], $target?->environmentVariables());
    }

    // ── refusals ────────────────────────────────────────────────────────────

    public function testAnEnabledUpdaterWithNoProviderIsRefused(): void
    {
        $this->expectException(UpdaterMisconfigured::class);
        $this->expectExceptionMessageMatches('/No providers are configured/');

        PublishTarget::fromConfig(['enabled' => true, 'default' => 'github', 'providers' => []]);
    }

    public function testADefaultNamingSomethingElseIsRefused(): void
    {
        $this->expectException(UpdaterMisconfigured::class);
        $this->expectExceptionMessageMatches('/Configured providers: s3/');

        PublishTarget::fromConfig([
            'enabled' => true,
            'default' => 'github',
            'providers' => ['s3' => ['driver' => 's3', 'bucket' => 'b']],
        ]);
    }

    public function testAnUnknownDriverIsRefused(): void
    {
        $this->expectException(UpdaterMisconfigured::class);
        $this->expectExceptionMessageMatches('/github, s3 and spaces/');

        PublishTarget::fromConfig($this->config(['driver' => 'ftp']));
    }

    public function testAProviderMissingWhatItNeedsIsRefused(): void
    {
        $this->expectException(UpdaterMisconfigured::class);
        $this->expectExceptionMessageMatches('/"owner"/');

        PublishTarget::fromConfig($this->config(['driver' => 'github', 'repo' => 'b']));
    }

    // ── the same keys upstream sends ────────────────────────────────────────

    /**
     * @param list<string> $required
     * @param array<string, mixed> $config
     */
    #[DataProvider('providers')]
    public function testOurOptionsAreTheOnesUpstreamSends(string $driver, string $class, array $config): void
    {
        $upstream = self::UPSTREAM.'/'.$class.'.php';

        if (!is_file($upstream)) {
            self::markTestSkipped('upstream/np-desktop is not checked out; clone it to compare the publish options.');
        }

        $source = (string) file_get_contents($upstream);

        $target = PublishTarget::fromConfig($this->config($config));

        self::assertNotNull($target);
        // A parser that quietly found nothing would make every comparison below trivially
        // true, which is the failure mode of every test that reads somebody else's source.
        self::assertGreaterThanOrEqual(4, \count($this->keysOf($source, 'builderOptions')));
        self::assertGreaterThanOrEqual(1, \count($this->keysOf($source, 'environmentVariables')));
        self::assertSame(
            $this->keysOf($source, 'builderOptions'),
            array_keys($target->builderOptions()),
            sprintf('The %s publish options have drifted from upstream\'s %s.', $driver, $class),
        );
        self::assertSame(
            $this->keysOf($source, 'environmentVariables'),
            array_keys($target->environmentVariables()),
            sprintf('The %s upload credentials have drifted from upstream\'s %s.', $driver, $class),
        );
    }

    /** @return iterable<string, array{string, string, array<string, mixed>}> */
    public static function providers(): iterable
    {
        yield 'github' => ['github', 'GitHubProvider', [
            'driver' => 'github', 'owner' => 'a', 'repo' => 'b', 'token' => 't',
        ]];

        yield 's3' => ['s3', 'S3Provider', [
            'driver' => 's3', 'bucket' => 'b', 'region' => 'r', 'endpoint' => 'e', 'path' => 'p',
            'key' => 'k', 'secret' => 's',
        ]];

        yield 'spaces' => ['spaces', 'SpacesProvider', [
            'driver' => 'spaces', 'name' => 'n', 'region' => 'r', 'path' => 'p', 'key' => 'k', 'secret' => 's',
        ]];
    }

    // ── helpers ─────────────────────────────────────────────────────────────

    /**
     * The keys of the array a given upstream method returns.
     *
     * @return list<string>
     */
    private function keysOf(string $source, string $method): array
    {
        $start = strpos($source, 'function '.$method.'(');

        self::assertIsInt($start, 'Upstream no longer has a '.$method.'() to compare against.');

        // Up to the return of the *next* method, so the conditional 'token' that GitHub
        // adds after building its array is included.
        $end = strpos($source, 'function ', $start + 1);
        $body = substr($source, $start, false === $end ? null : $end - $start);

        preg_match_all("/'([A-Za-z_][A-Za-z0-9_]*)'\s*(?:=>|\])\s*=?/", $body, $matches);

        $keys = [];

        foreach ($matches[1] as $key) {
            // Config reads look identical to option keys; only the ones written into the
            // returned array are keys, and those are the `'x' =>` form.
            if (str_contains($body, "'".$key."' =>")) {
                $keys[$key] = true;
            }
        }

        return array_keys($keys);
    }

    /**
     * @param array<string, mixed> $provider
     *
     * @return array<string, mixed>
     */
    private function config(array $provider): array
    {
        return ['enabled' => true, 'default' => 'target', 'providers' => ['target' => $provider]];
    }
}
