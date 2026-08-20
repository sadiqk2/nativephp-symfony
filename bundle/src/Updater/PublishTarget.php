<?php

declare(strict_types=1);

namespace Native\Symfony\Desktop\Updater;

/**
 * The updater configuration, translated into what electron-builder publishes with.
 *
 * Two different consumers read the `updater` config tree and they want different
 * shapes, which is the whole reason this class exists:
 *
 *  - the *runtime* reads `native:config` output and looks at `updater.enabled`,
 *    `updater.default` and `updater.providers[default].public_url` — so the tree is
 *    forwarded there as it stands;
 *  - *electron-builder* reads `NATIVEPHP_UPDATER_CONFIG` and assigns it straight to its
 *    own `publish` option (`electron-builder.mjs`: `...(updaterEnabled ? { publish:
 *    updaterConfig } : {})`), which has to be a publish-provider object — `{provider:
 *    'github', owner, repo}` and so on.
 *
 * Handing the second consumer the first one's shape put `{enabled, default, providers}`
 * into `publish`, which electron-builder cannot use; and `NATIVEPHP_UPDATER_ENABLED` was
 * never sent at all, so `updaterEnabled` was false and the `publish` key was dropped
 * before it got that far. The net effect was that `native:build --publish` uploaded
 * nothing and no `latest.yml` was written, so an app with the updater switched on found
 * no updates — with nothing failing anywhere to say why.
 *
 * The three providers mirror upstream's, key for key: `GitHubProvider`, `S3Provider` and
 * `SpacesProvider` in NativePHP/desktop. A provider named in config but not implemented
 * here is an error rather than a silent empty publish target.
 */
final class PublishTarget
{
    /**
     * @param array<string, scalar|null> $builderOptions
     * @param array<string, string>      $environmentVariables
     */
    private function __construct(
        private readonly array $builderOptions,
        private readonly array $environmentVariables,
    ) {
    }

    /**
     * @param array<string, mixed> $updater The `native_desktop.updater` config tree
     *
     * @return self|null Null when the updater is switched off, which is the default
     *
     * @throws UpdaterMisconfigured
     */
    public static function fromConfig(array $updater): ?self
    {
        if (true !== ($updater['enabled'] ?? false)) {
            return null;
        }

        $name = (string) ($updater['default'] ?? '');
        /** @var array<string, array<string, mixed>> $providers */
        $providers = $updater['providers'] ?? [];

        if ('' === $name || !isset($providers[$name])) {
            throw UpdaterMisconfigured::noSuchProvider($name, array_keys($providers));
        }

        $provider = $providers[$name];
        $driver = (string) ($provider['driver'] ?? $name);

        return match ($driver) {
            'github' => self::github($provider),
            's3' => self::s3($provider),
            'spaces' => self::spaces($provider),
            default => throw UpdaterMisconfigured::unsupportedDriver($driver, $name),
        };
    }

    /** @return array<string, scalar|null> */
    public function builderOptions(): array
    {
        return $this->builderOptions;
    }

    /**
     * Credentials electron-builder reads from the environment when it uploads.
     *
     * @return array<string, string>
     */
    public function environmentVariables(): array
    {
        return $this->environmentVariables;
    }

    /** @param array<string, mixed> $config */
    private static function github(array $config): self
    {
        self::require($config, ['owner', 'repo'], 'github');

        $options = [
            'provider' => 'github',
            'repo' => (string) $config['repo'],
            'owner' => (string) $config['owner'],
            'vPrefixedTagName' => (bool) ($config['vPrefixedTagName'] ?? true),
            'private' => (bool) ($config['private'] ?? false),
            'channel' => (string) ($config['channel'] ?? 'latest'),
            'releaseType' => (string) ($config['releaseType'] ?? 'draft'),
        ];

        // A private repository needs a token the *published app* can read with, which is
        // a different token from the one that uploads the release.
        if (true === $options['private'] && '' !== (string) ($config['autoupdate_token'] ?? '')) {
            $options['token'] = (string) $config['autoupdate_token'];
        }

        return new self($options, self::environment(['GH_TOKEN' => $config['token'] ?? null]));
    }

    /** @param array<string, mixed> $config */
    private static function s3(array $config): self
    {
        self::require($config, ['bucket'], 's3');

        return new self(
            [
                'provider' => 's3',
                'endpoint' => (string) ($config['endpoint'] ?? ''),
                'region' => (string) ($config['region'] ?? ''),
                'bucket' => (string) $config['bucket'],
                'path' => (string) ($config['path'] ?? ''),
            ],
            self::environment([
                'AWS_ACCESS_KEY_ID' => $config['key'] ?? null,
                'AWS_SECRET_ACCESS_KEY' => $config['secret'] ?? null,
            ]),
        );
    }

    /** @param array<string, mixed> $config */
    private static function spaces(array $config): self
    {
        self::require($config, ['name'], 'spaces');

        return new self(
            [
                'provider' => 'spaces',
                'name' => (string) $config['name'],
                'region' => (string) ($config['region'] ?? ''),
                'path' => (string) ($config['path'] ?? ''),
            ],
            self::environment([
                'DO_KEY_ID' => $config['key'] ?? null,
                'DO_SECRET_KEY' => $config['secret'] ?? null,
            ]),
        );
    }

    /**
     * @param array<string, mixed> $config
     * @param list<string>         $keys
     *
     * @throws UpdaterMisconfigured
     */
    private static function require(array $config, array $keys, string $driver): void
    {
        $missing = array_values(array_filter(
            $keys,
            static fn (string $key): bool => '' === (string) ($config[$key] ?? ''),
        ));

        if ([] !== $missing) {
            throw UpdaterMisconfigured::missingKeys($driver, $missing);
        }
    }

    /**
     * Credentials are omitted rather than exported empty: an empty AWS_SECRET_ACCESS_KEY
     * overrides one the developer already has in their environment, and the failure it
     * produces names the wrong cause.
     *
     * @param array<string, mixed> $candidates
     *
     * @return array<string, string>
     */
    private static function environment(array $candidates): array
    {
        $variables = [];

        foreach ($candidates as $name => $value) {
            if (null !== $value && '' !== (string) $value) {
                $variables[$name] = (string) $value;
            }
        }

        return $variables;
    }
}
