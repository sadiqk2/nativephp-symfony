<?php

declare(strict_types=1);

namespace Native\Symfony\Desktop\Command;

use Native\Symfony\Desktop\Contract\AppBootstrapper;
use Native\Symfony\Desktop\Security\RuntimeAccessSubscriber;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Exception\ExceptionInterface as RoutingException;
use Symfony\Component\Routing\Matcher\RequestMatcherInterface;

/**
 * Check that this application can actually be driven by the runtime.
 *
 * Every failure below produces the same symptom — an app that starts, shows a
 * window, and then does nothing forever — because the runtime's half of the
 * conversation happens over HTTP and a request that never lands is logged by
 * Electron rather than by the app. That symptom has cost real time twice here:
 * once for a missing routes import, once for no ScreenRendererInterface. A check
 * that runs in a second is worth more than either diagnosis.
 *
 * The mobile bundle has had `native:mobile:doctor` since it shipped, for the
 * different reason that mobile has no dev loop at all. This is the desktop half.
 */
#[AsCommand(name: 'native:doctor', description: 'Check that the runtime can reach this application')]
final class DoctorCommand extends Command
{
    /** The two paths the runtime POSTs to; see Resources/config/routes.php. */
    private const RUNTIME_PATHS = ['/_native/api/booted', '/_native/api/events'];

    public function __construct(
        private readonly string $projectDir,
        private readonly bool $running,
        private readonly ?string $secret,
        private readonly ?RequestMatcherInterface $router = null,
        private readonly ?AppBootstrapper $bootstrapper = null,
        /**
         * Symfony's `security.access_map`, when the security bundle is installed.
         *
         * Deliberately untyped: type-hinting AccessMapInterface would make
         * symfony/security-http a dependency of every app that installs this
         * bundle, to support a check that only matters to the apps that have it.
         * The DI definition passes it with ignore_on_invalid_reference.
         */
        private readonly ?object $accessMap = null,
        /** Symfony's `security.firewall.map`; untyped for the same reason. */
        private readonly ?object $firewallMap = null,
        /** Whether RuntimeRoutesAccessMap is decorating the access map. */
        private readonly bool $exemptRuntimeFirewall = true,
        /** native_desktop.block_browser_access; the gate the exemption is conditional on. */
        private readonly bool $blockBrowserAccess = true,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $io->definitionList(
            ['project dir' => $this->projectDir],
            ['php' => \PHP_VERSION.' ('.\PHP_OS_FAMILY.', '.\PHP_SAPI.')'],
            ['runtime' => $this->running
                ? 'running inside the Electron runtime'
                : 'not running inside the runtime (an ordinary console invocation)'],
            ['secret' => match (true) {
                !$this->running => 'n/a outside the runtime',
                null === $this->secret || '' === $this->secret => 'ABSENT — the browser gate fails open',
                default => 'present ('.\strlen($this->secret).' chars)',
            }],
        );

        $failures = 0;
        $failures += $this->checkRoutes($io);
        $failures += $this->checkFirewall($io);
        $failures += $this->checkBootstrapper($io);

        $this->reportBuildInputs($io);

        if ($failures > 0) {
            $io->error(sprintf('%d check(s) failed. The app will not work under the runtime until they pass.', $failures));

            return Command::FAILURE;
        }

        $io->success('Everything the runtime needs is in place.');

        return Command::SUCCESS;
    }

    /**
     * The routes import is the single highest-value thing to verify.
     *
     * Matching a real Request rather than a path also catches the case where the
     * routes exist but under the wrong method — the runtime only ever POSTs, so a
     * GET-only registration is as dead as no registration at all.
     */
    private function checkRoutes(SymfonyStyle $io): int
    {
        $io->section('Runtime endpoints');

        if (null === $this->router) {
            $io->warning('No router is available, so the runtime endpoints could not be checked.');

            return 0;
        }

        $missing = [];

        foreach (self::RUNTIME_PATHS as $path) {
            try {
                $this->router->matchRequest(Request::create($path, 'POST'));
                $io->text(sprintf(' ✓ POST %s', $path));
            } catch (RoutingException) {
                $missing[] = $path;
                $io->text(sprintf(' ✗ POST %s — does not route', $path));
            }
        }

        if ([] === $missing) {
            return 0;
        }

        $io->warning([
            'The runtime endpoints are not registered, so the app will boot and show nothing:',
            'the runtime POSTs to /booted, gets a 404, and AppBootstrapper::boot() never runs.',
            'Run `bin/console native:install`, or write config/routes/native_desktop.yaml yourself.',
        ]);

        // Outside the warning block on purpose: SymfonyStyle blank-lines every entry
        // of a block, which turns a YAML snippet into something that cannot be copied.
        $io->writeln([
            '  native_desktop:',
            "      resource: '@NativeDesktopBundle/src/Resources/config/routes.php'",
            '      type: php',
            '',
        ]);

        return 1;
    }

    /**
     * An application firewall will happily deny the runtime's own callbacks.
     *
     * RuntimeAccessSubscriber runs at priority 4096 and returns without stopping
     * propagation, so the firewall still evaluates every request behind it. An
     * `access_control` rule of `^/` — which is what most authenticated apps have —
     * therefore redirects the runtime's POST to a login page, and boot() never
     * runs. In Laravel these two routes are registered outside the app's middleware
     * groups entirely, so nothing equivalent bites there; the fix in Symfony is one
     * stanza, but only if you know to write it.
     */
    private function checkFirewall(SymfonyStyle $io): int
    {
        $io->section('Firewall');

        if (null === $this->accessMap || !method_exists($this->accessMap, 'getPatterns')) {
            $io->text(' – no security bundle installed, so nothing can gate the endpoints.');

            return 0;
        }

        // The bundle decorates the access map to return no attributes for these
        // paths while running inside the runtime, so there is nothing left to warn
        // about. Checking the map here would report the *console's* answer anyway —
        // `running` is false on a command line, so the decorator passes straight
        // through and every healthy app would look broken.
        //
        // Only while the secret gate is on, though. RuntimeRoutesAccessMap hands the
        // application's rules away only when something is enforcing in their place,
        // so `block_browser_access: false` — one documented line — silently switches
        // the exemption off too. Claiming it regardless would report the broken app
        // as healthy, which is the one answer this command must never give.
        if ($this->exemptRuntimeFirewall && $this->blockBrowserAccess) {
            $io->text(' ✓ access_control is neutralised on '.RuntimeAccessSubscriber::RUNTIME_PREFIX.' inside the runtime');
            $io->text('   (native_desktop.exempt_runtime_firewall; outside the runtime your firewall still applies).');

            return 0;
        }

        if ($this->exemptRuntimeFirewall) {
            $io->text(' – exempt_runtime_firewall is on but block_browser_access is off, so nothing exempts');
            $io->text('   these paths: the exemption applies only while the shared secret is checked.');
        }

        $gated = [];

        foreach (self::RUNTIME_PATHS as $path) {
            $request = Request::create($path, 'POST');

            // access_control rules live in the access map whether or not anything
            // enforces them: they are applied by the firewall's AccessListener, so a
            // `security: false` firewall means the rule matches and is never checked.
            // Reading the map alone therefore reports a path as gated even when the
            // reader has already written the exact exemption this command recommends,
            // which is the one person a warning must never fire at.
            if (!$this->isSecured($request)) {
                $io->text(sprintf(' ✓ POST %s is handled by a firewall with security disabled', $path));

                continue;
            }

            /** @var array{0: ?array<mixed>, 1: ?string} $patterns */
            $patterns = $this->accessMap->getPatterns($request);
            $attributes = $patterns[0] ?? null;

            if (null !== $attributes && [] !== $attributes) {
                $gated[] = $path;
                $io->text(sprintf(' ✗ POST %s is behind access_control (%s)', $path, implode(', ', array_map('strval', $attributes))));

                continue;
            }

            $io->text(sprintf(' ✓ POST %s is not behind access_control', $path));
        }

        if ([] === $gated) {
            return 0;
        }

        $io->warning([
            'access_control covers the runtime endpoints, so the runtime cannot boot the app:',
            'its POST is redirected to your login page and AppBootstrapper::boot() never runs.',
            ...($this->blockBrowserAccess ? [
                'These two paths carry the shared secret and are checked by RuntimeAccessSubscriber',
                'before your firewall sees them, so exempting them costs you nothing.',
                'Either set native_desktop.exempt_runtime_firewall: true — which does this for you,',
                'and only while running inside the runtime — or write the firewall yourself:',
            ] : [
                'Nothing checks the shared secret either: block_browser_access is off, which is also',
                'why exempt_runtime_firewall cannot help here. Turn the gate back on, or exempt the',
                'paths in your own firewall and accept that any local process can then reach them:',
            ]),
        ]);

        $io->writeln([
            '  security:',
            '      firewalls:',
            '          native:',
            '              pattern: ^/_native/api/',
            '              security: false',
            '',
        ]);

        return 1;
    }

    /**
     * Whether a firewall with security *enabled* handles this path.
     *
     * No firewall map, or no firewall matching the path, means nothing enforces
     * access_control on it either — the AccessListener that reads the access map is
     * part of the firewall.
     */
    private function isSecured(Request $request): bool
    {
        if (null === $this->firewallMap || !method_exists($this->firewallMap, 'getFirewallConfig')) {
            return true;
        }

        $config = $this->firewallMap->getFirewallConfig($request);

        if (null === $config || !method_exists($config, 'isSecurityEnabled')) {
            return null !== $config;
        }

        return $config->isSecurityEnabled();
    }

    /**
     * No bootstrapper is not an error — an app may open its windows some other way —
     * but it is the second most common reason for a window that never appears, and
     * the container cannot tell the two situations apart. Say so and move on.
     */
    private function checkBootstrapper(SymfonyStyle $io): int
    {
        $io->section('Application startup');

        if (null === $this->bootstrapper) {
            $io->text(' – no AppBootstrapper is registered, so /booted does nothing but dispatch its event.');
            $io->text('   Implement Native\Symfony\Desktop\Contract\AppBootstrapper to open a window at startup.');

            return 0;
        }

        $io->text(sprintf(' ✓ %s will run when the runtime finishes booting.', $this->bootstrapper::class));

        return 0;
    }

    /**
     * Informational only: these are needed to *package* an app, not to run one in
     * development, so a dev machine legitimately has neither.
     */
    private function reportBuildInputs(SymfonyStyle $io): void
    {
        $io->section('Build inputs');

        foreach ([
            'nativephp/php-bin' => $this->projectDir.'/vendor/nativephp/php-bin',
            'Electron project' => $this->projectDir.'/nativephp/electron',
        ] as $name => $path) {
            $io->text(sprintf(' %s %s%s', is_dir($path) ? '✓' : '–', $name, is_dir($path) ? '' : ' — absent; `native:build` needs it'));
        }
    }
}
