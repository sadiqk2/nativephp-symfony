<?php

declare(strict_types=1);

namespace Native\Symfony;

use Native\Symfony\App\AppManager;
use Native\Symfony\Clipboard\ClipboardManager;
use Native\Symfony\Client\Client;
use Native\Symfony\Command\BuildCommand;
use Native\Symfony\Command\ConfigCommand;
use Native\Symfony\Command\DoctorCommand;
use Native\Symfony\Command\InstallCommand;
use Native\Symfony\Command\ManifestCommand;
use Native\Symfony\Command\PhpIniCommand;
use Native\Symfony\Command\RunCommand;
use Native\Symfony\Command\ScheduleTickCommand;
use Native\Symfony\Contract\AppBootstrapper;
use Native\Symfony\Dialog\DialogManager;
use Native\Symfony\Dock\DockManager;
use Native\Symfony\Contract\ClientInterface;
use Native\Symfony\Contract\ProvidesPhpIni;
use Native\Symfony\DependencyInjection\AliasContractsPass;
use Native\Symfony\EventBridge\BroadcastingDispatcher;
use Native\Symfony\EventBridge\EventFactory;
use Native\Symfony\EventBridge\RuntimeBroadcaster;
use Native\Symfony\Http\BootedController;
use Native\Symfony\Manifest\Manifest;
use Native\Symfony\Manifest\ManifestSupportDetector;
use Native\Symfony\Manifest\ManifestWriter;
use Native\Symfony\Menu\MenuManager;
use Native\Symfony\MenuBar\MenuBarManager;
use Native\Symfony\Notification\NotificationManager;
use Native\Symfony\PowerMonitor\PowerMonitorManager;
use Native\Symfony\Process\ChildProcessManager;
use Native\Symfony\Process\MessengerWorker;
use Native\Symfony\Http\EventsController;
use Native\Symfony\Runtime\RuntimePatcher;
use Native\Symfony\Screen\ScreenManager;
use Native\Symfony\Security\RuntimeAccessSubscriber;
use Native\Symfony\Security\RuntimeRoutesAccessMap;
use Native\Symfony\Settings\SettingsManager;
use Native\Symfony\Shell\ShellManager;
use Native\Symfony\Shortcut\GlobalShortcutManager;
use Native\Symfony\Support\NativePaths;
use Native\Symfony\Support\Platform;
use Native\Symfony\System\DebugLogger;
use Native\Symfony\System\ProgressBar;
use Native\Symfony\System\RuntimeInfo;
use Native\Symfony\System\SystemManager;
use Native\Symfony\Testing\FakeRuntime;
use Native\Symfony\Testing\RuntimeEventSimulator;
use Native\Symfony\Testing\RuntimeExpectations;
use Native\Symfony\Updater\UpdaterManager;
use Native\Symfony\Window\UrlResolver;
use Native\Symfony\Window\WindowManager;
use Symfony\Component\Config\Definition\Configurator\DefinitionConfigurator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpKernel\Bundle\AbstractBundle;
use Symfony\Component\Security\Http\AccessMapInterface;

use function Symfony\Component\DependencyInjection\Loader\Configurator\service;

final class NativeDesktopBundle extends AbstractBundle
{
    protected string $extensionAlias = 'native_desktop';

    public function build(ContainerBuilder $container): void
    {
        parent::build($container);

        // Any service implementing a contract gets tagged, and the pass below
        // aliases the interface to it. Without this, implementing AppBootstrapper
        // silently does nothing.
        $container->registerForAutoconfiguration(Contract\AppBootstrapper::class)
            ->addTag(AliasContractsPass::BOOTSTRAPPER_TAG);

        $container->registerForAutoconfiguration(Contract\ProvidesPhpIni::class)
            ->addTag(AliasContractsPass::PHP_INI_TAG);

        $container->addCompilerPass(new AliasContractsPass());
    }

    public function configure(DefinitionConfigurator $definition): void
    {
        $definition->rootNode()
            ->children()
                ->scalarNode('app_id')
                    ->defaultValue('com.example.app')
                    ->info('Reverse-domain application id. The runtime passes it to setAppUserModelId().')
                ->end()
                ->scalarNode('name')
                    ->defaultValue('App')
                    ->info('Human-readable app name. Slugged for the package filename.')
                ->end()
                ->scalarNode('version')->defaultValue('1.0.0')->end()
                ->scalarNode('author')->defaultNull()->end()
                ->scalarNode('copyright')->defaultNull()->end()
                ->scalarNode('description')->defaultNull()->end()
                ->scalarNode('website')->defaultNull()->end()
                ->scalarNode('deeplink_scheme')
                    ->defaultNull()
                    ->info('Custom URL scheme, without "://". Enables the OpenedFromURL event.')
                ->end()
                ->scalarNode('base_url')
                    ->defaultNull()
                    ->info('Fallback base URL for building absolute window URLs outside an HTTP request.')
                ->end()
                ->arrayNode('updater')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->booleanNode('enabled')->defaultFalse()->end()
                        ->scalarNode('default')->defaultValue('github')->end()
                        ->arrayNode('providers')
                            ->useAttributeAsKey('name')
                            ->variablePrototype()->end()
                        ->end()
                    ->end()
                ->end()
                ->arrayNode('php_ini')
                    ->info('Merged over the runtime defaults and passed as -d flags to every spawned PHP process.')
                    ->useAttributeAsKey('name')
                    ->scalarPrototype()->end()
                ->end()
                ->arrayNode('events')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->arrayNode('allowed_namespaces')
                            ->info(
                                'Namespaces whose classes may be instantiated from a runtime event push. '.
                                'Leave empty unless you dispatch your own event classes by class name — '.
                                'anything not listed becomes a generic NativeEvent instead.'
                            )
                            ->scalarPrototype()->end()
                        ->end()
                    ->end()
                ->end()
                ->arrayNode('prebuild')
                    ->info('Shell commands run in the project root before staging.')
                    ->scalarPrototype()->end()
                ->end()
                ->arrayNode('postbuild')
                    ->info('Shell commands run after packaging succeeds.')
                    ->scalarPrototype()->end()
                ->end()
                ->arrayNode('nsis')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->booleanNode('delete_app_data_on_uninstall')->defaultFalse()->end()
                    ->end()
                ->end()
                ->arrayNode('build')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->arrayNode('exclude')
                            ->info(
                                'fnmatch patterns, relative to the project root, never copied into '.
                                'the build. Excluded directories are skipped wholesale rather than '.
                                'copied and deleted.'
                            )
                            ->defaultValue([
                                // VCS and tooling
                                '.git', '.github', '.idea', '.vscode', '.gitignore', '.editorconfig',
                                // Build outputs and the runtime's own project — electron-builder
                                // copies the build dir itself, and re-copying it would recurse.
                                'nativephp', 'node_modules', 'var/cache', 'var/log',
                                // Dev-only
                                'tests', 'phpunit.xml', 'phpunit.xml.dist', '.env.test',
                                '.php-cs-fixer*', 'phpstan*', 'rector.php',
                                // Anything that could carry a secret into a shippable
                                // artifact. `.env.local.php` is the one to notice: it is
                                // what `composer dump-env prod` writes, it holds every
                                // resolved value including secrets, and Symfony's
                                // bootEnv() prefers it over the .env this build cleans.
                                'auth.json', '.env.local', '.env.*.local', '.env.local.php',
                                '.env.*.php',
                                '*.sqlite', '*.sqlite-shm', '*.sqlite-wal',
                                // Doctrine's Flex recipe defaults to
                                // var/data_%kernel.environment%.db, which *.sqlite misses.
                                'var/*.db',
                                // fnmatch is anchored, so the bare names above only match
                                // at the project root; a nested one is copied wholesale.
                                '*/node_modules', '*/tests',
                            ])
                            ->scalarPrototype()->end()
                        ->end()
                        ->arrayNode('keep')
                            ->info(
                                'Directories that must exist in the package. electron-builder prunes '.
                                'empty ones and dotfiles do not stop it, so each gets a placeholder. '.
                                'Symfony will not boot without a writable var/cache and var/log.'
                            )
                            ->defaultValue(['var/cache', 'var/log'])
                            ->scalarPrototype()->end()
                        ->end()
                        ->arrayNode('env_remove')
                            ->info('fnmatch patterns for .env keys stripped from the staged copy.')
                            // Deliberately narrower than Laravel's equivalent list,
                            // which globs *_SECRET and *_KEY. Those are safe there
                            // (its key is APP_KEY) and lethal here: they match
                            // Symfony's own APP_SECRET.
                            ->defaultValue([
                                'AWS_*', 'AZURE_*', 'GITHUB_*', 'DO_SPACES_*',
                                'BIFROST_*', 'NATIVEPHP_APPLE_*', 'NATIVEPHP_AZURE_*',
                                'STRIPE_*', 'MAILER_DSN', 'SENTRY_*',
                            ])
                            ->scalarPrototype()->end()
                        ->end()
                        ->arrayNode('env_keep')
                            ->info(
                                'fnmatch patterns for .env keys that must survive, whatever '.
                                'env_remove says — this list wins. APP_SECRET is here because '.
                                'framework.yaml reads it and the packaged app will not boot '.
                                'without it.'
                            )
                            ->defaultValue(['APP_SECRET'])
                            ->scalarPrototype()->end()
                        ->end()
                        ->arrayNode('env_defaults')
                            ->info('.env values forced in the staged copy. These win over the app\'s own.')
                            ->defaultValue([
                                'APP_ENV' => 'prod',
                                'APP_DEBUG' => '0',
                            ])
                            ->useAttributeAsKey('name')
                            ->scalarPrototype()->end()
                        ->end()
                    ->end()
                ->end()
                ->booleanNode('testing')
                    ->defaultFalse()
                    ->info(
                        'Replace the runtime transport with Testing\FakeRuntime, which records calls '.
                        'and answers with whatever a test scripted. Set it in config/packages/test/ '.
                        'only — with this on, nothing reaches a real runtime.'
                    )
                ->end()
                ->booleanNode('block_browser_access')
                    ->defaultTrue()
                    ->info(
                        'Reject requests that carry neither the _php_native cookie nor the '.
                        'X-NativePHP-Secret header while running inside the runtime. Leave this on: '.
                        'the app is served on a real loopback port and the secret is the only thing '.
                        'keeping other local processes out.'
                    )
                ->end()
                ->booleanNode('exempt_runtime_firewall')
                    ->defaultTrue()
                    ->info(
                        "Keep the application's own access_control off /_native/api/ while running ".
                        'inside the runtime, by decorating security.access_map. Without it a rule of '.
                        '^/ answers POST /_native/api/booted with a 401, the runtime discards it, and '.
                        'the app boots to a window that never does anything. Outside the runtime your '.
                        'firewall still applies in full — that path dispatches events by name, so it '.
                        'must stay protected on the web. Only applies when the security bundle is '.
                        'installed; inside the runtime the shared secret is checked first regardless.'
                    )
                ->end()
            ->end();
    }


    /** @param array<string, mixed> $config */
    public function loadExtension(array $config, ContainerConfigurator $container, ContainerBuilder $builder): void
    {
        $services = $container->services()->defaults()->autowire(false);

        // --- environment ------------------------------------------------------
        // Every one of these is absent when the app runs as an ordinary web
        // request; default:: yields null rather than throwing.
        $container->parameters()
            ->set('native_desktop.api_url', '%env(default::NATIVEPHP_API_URL)%')
            ->set('native_desktop.secret', '%env(default::NATIVEPHP_SECRET)%')
            ->set('native_desktop.running', '%env(bool:default::NATIVEPHP_RUNNING)%')
            ->set('native_desktop.app_id', $config['app_id'])
            ->set('native_desktop.version', $config['version']);

        // --- transport --------------------------------------------------------
        $services->set(Client::class)
            ->args([
                service('http_client'),
                '%native_desktop.api_url%',
                '%native_desktop.secret%',
                service('logger')->nullOnInvalid(),
            ])
            ->tag('monolog.logger', ['channel' => 'native']);

        $services->alias(ClientInterface::class, Client::class)->public();

        // --- testing ----------------------------------------------------------
        // Re-points the transport alias, and nothing else: every manager, every
        // controller and the event bridge stay exactly as they are in production,
        // so a functional test exercises the real payload building against a
        // runtime that records instead of one that has to be running.
        if ($config['testing'] ?? false) {
            $services->set(FakeRuntime::class)
                ->factory([FakeRuntime::class, 'available'])
                ->public();

            $services->alias(ClientInterface::class, FakeRuntime::class)->public();

            $services->set(RuntimeEventSimulator::class)
                ->args([service('event_dispatcher'), service(EventFactory::class)])
                ->public();

            // Assertions need PHPUnit; the fake and the simulator do not. Keep the
            // container bootable in a test environment that runs another framework.
            if (class_exists(\PHPUnit\Framework\Assert::class)) {
                $services->set(RuntimeExpectations::class)
                    ->args([service(FakeRuntime::class)])
                    ->public();
            }
        }

        // --- APIs -------------------------------------------------------------
        $services->set(UrlResolver::class)
            ->args([service('request_stack'), $config['base_url']]);

        $services->set(WindowManager::class)
            ->args([service(ClientInterface::class), service(UrlResolver::class), service('request_stack')])
            ->public();

        $services->set(AppManager::class)
            ->args([service(ClientInterface::class)])
            ->public();

        $services->set(Platform::class)->public();

        // Single-dependency managers: the client is the only collaborator, so a
        // loop keeps this honest instead of twenty near-identical blocks.
        foreach ([
            DialogManager::class,
            MenuManager::class,
            NotificationManager::class,
            ChildProcessManager::class,
            ClipboardManager::class,
            ScreenManager::class,
            SettingsManager::class,
            ShellManager::class,
            SystemManager::class,
            PowerMonitorManager::class,
            GlobalShortcutManager::class,
            UpdaterManager::class,
            ProgressBar::class,
            RuntimeInfo::class,
            DebugLogger::class,
        ] as $manager) {
            $services->set($manager)->args([service(ClientInterface::class)])->public();
        }

        $services->set(DockManager::class)
            ->args([service(ClientInterface::class), service(Platform::class)])
            ->public();

        $services->set(MenuBarManager::class)
            ->args([service(ClientInterface::class), service(UrlResolver::class)])
            ->public();

        $services->set(MessengerWorker::class)
            ->args([service(ChildProcessManager::class)])
            ->public();

        $services->set(NativePaths::class)
            ->args([[
                'home' => '%env(default::NATIVEPHP_USER_HOME_PATH)%',
                'app_data' => '%env(default::NATIVEPHP_APP_DATA_PATH)%',
                'user_data' => '%env(default::NATIVEPHP_USER_DATA_PATH)%',
                'desktop' => '%env(default::NATIVEPHP_DESKTOP_PATH)%',
                'documents' => '%env(default::NATIVEPHP_DOCUMENTS_PATH)%',
                'downloads' => '%env(default::NATIVEPHP_DOWNLOADS_PATH)%',
                'music' => '%env(default::NATIVEPHP_MUSIC_PATH)%',
                'pictures' => '%env(default::NATIVEPHP_PICTURES_PATH)%',
                'videos' => '%env(default::NATIVEPHP_VIDEOS_PATH)%',
                'recent' => '%env(default::NATIVEPHP_RECENT_PATH)%',
                'extras' => '%env(default::NATIVEPHP_EXTRAS_PATH)%',
                'storage' => '%env(default::NATIVEPHP_STORAGE_PATH)%',
                'database' => '%env(default::NATIVEPHP_DATABASE_PATH)%',
            ]])
            ->public();

        // --- event bridge -----------------------------------------------------
        $services->set(EventFactory::class)
            ->args([$config['events']['allowed_namespaces']]);

        $services->set(RuntimeBroadcaster::class)
            ->args([service(ClientInterface::class), service('logger')->nullOnInvalid()])
            ->public();

        // Decorating the dispatcher is the only place every dispatch passes
        // through, and Symfony has no wildcard listener to hook instead.
        $services->set(BroadcastingDispatcher::class)
            ->decorate('event_dispatcher', priority: -128)
            ->args([
                service('.inner'),
                service(RuntimeBroadcaster::class),
            ]);

        // --- HTTP -------------------------------------------------------------
        $services->set(BootedController::class)
            ->args([
                service('event_dispatcher'),
                service(AppBootstrapper::class)->nullOnInvalid(),
                service('logger')->nullOnInvalid(),
            ])
            ->tag('controller.service_arguments');

        $services->set(EventsController::class)
            ->args([service(EventFactory::class), service('event_dispatcher')])
            ->tag('controller.service_arguments');

        if ($config['block_browser_access']) {
            $services->set(RuntimeAccessSubscriber::class)
                ->args(['%native_desktop.running%', '%native_desktop.secret%'])
                ->tag('kernel.event_subscriber');
        }

        // Only when the app actually has a firewall to get in the way. The class
        // implements a security-http interface, so it must not be referenced at all
        // in an app without that package — hence the interface_exists guard rather
        // than a nullOnInvalid reference.
        if ($config['exempt_runtime_firewall'] && interface_exists(AccessMapInterface::class)) {
            $services->set(RuntimeRoutesAccessMap::class)
                ->decorate('security.access_map', invalidBehavior: ContainerInterface::IGNORE_ON_INVALID_REFERENCE)
                ->args([service('.inner'), '%native_desktop.running%']);
        }

        // --- commands ---------------------------------------------------------
        $services->set(ConfigCommand::class)
            ->args([[
                // Exactly the five keys index.ts reads, plus the metadata build
                // tooling will want later. Anything else is dead weight on a
                // path that runs before every app boot.
                'app_id' => $config['app_id'],
                'version' => $config['version'],
                'author' => $config['author'],
                'description' => $config['description'],
                'website' => $config['website'],
                'deeplink_scheme' => $config['deeplink_scheme'],
                'updater' => $config['updater'],
            ]])
            ->tag('console.command');

        $services->set(PhpIniCommand::class)
            ->args([$config['php_ini'], service(ProvidesPhpIni::class)->nullOnInvalid()])
            ->tag('console.command');

        $services->set(ScheduleTickCommand::class)->tag('console.command');

        // --- runtime manifest -------------------------------------------------
        // All defaults: every value is a property of Symfony itself, not of the
        // application, so there is nothing here for an app to configure. The one
        // exception a real app hits — no Doctrine Migrations — is a decorator or
        // an explicit service definition away.
        $services->set(Manifest::class);
        $services->set(ManifestSupportDetector::class);

        $services->set(ManifestWriter::class)
            ->args(['%kernel.project_dir%', service(Manifest::class)]);

        $services->set(ManifestCommand::class)
            ->args([
                service(ManifestWriter::class),
                service(Manifest::class),
                service(ManifestSupportDetector::class),
                '%kernel.project_dir%',
            ])
            ->tag('console.command');

        $services->set(RuntimePatcher::class)
            // Named, because everything before it in the constructor is a
            // defaulted string.
            ->arg('$detector', service(ManifestSupportDetector::class));

        $services->set(InstallCommand::class)
            ->args(['%kernel.project_dir%', service(RuntimePatcher::class)])
            ->tag('console.command');

        $services->set(RunCommand::class)
            ->args(['%kernel.project_dir%'])
            ->tag('console.command');

        // `router` and `security.access_map` are both optional on purpose: the
        // first is absent in a kernel without FrameworkBundle's routing, and the
        // second only exists when the app installs the security bundle. The
        // command degrades to reporting what it could not check.
        $services->set(DoctorCommand::class)
            ->args([
                '%kernel.project_dir%',
                '%native_desktop.running%',
                '%native_desktop.secret%',
                service('router')->nullOnInvalid(),
                service(AppBootstrapper::class)->nullOnInvalid(),
                service('security.access_map')->nullOnInvalid(),
                service('security.firewall.map')->nullOnInvalid(),
                $config['exempt_runtime_firewall'] && interface_exists(AccessMapInterface::class),
            ])
            ->tag('console.command');

        $services->set(BuildCommand::class)
            ->args(['%kernel.project_dir%', $config])
            ->tag('console.command');
    }
}
