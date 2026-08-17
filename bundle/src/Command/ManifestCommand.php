<?php

declare(strict_types=1);

namespace Native\Symfony\Command;

use Native\Symfony\Manifest\Manifest;
use Native\Symfony\Manifest\ManifestSupport;
use Native\Symfony\Manifest\ManifestSupportDetector;
use Native\Symfony\Manifest\ManifestWriter;
use Native\Symfony\Support\Platform;
use Native\Symfony\Support\ProjectPath;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Filesystem\Path;

/**
 * Writes the `nativephp.json` that tells a manifest-aware Electron runtime where
 * this Symfony app's entry points are.
 *
 * Running this is what replaces patching the runtime. It is safe to run at any
 * time and on any runtime: an older runtime ignores the file entirely, so an app
 * can carry the manifest while still being served by a patched copy.
 *
 * The report is not decoration. Every value here is a path or a command the
 * runtime will use *before* the app can log anything, so a wrong one shows up as a
 * blank window; printing them is the cheapest place to notice.
 */
#[AsCommand(name: 'native:manifest', description: 'Write nativephp.json describing this app to the Electron runtime')]
final class ManifestCommand extends Command
{
    private readonly ProjectPath $paths;

    public function __construct(
        private readonly ManifestWriter $writer,
        private readonly Manifest $manifest,
        private readonly ManifestSupportDetector $detector,
        private readonly string $projectDir,
        ?Platform $platform = null,
    ) {
        parent::__construct();

        $this->paths = new ProjectPath($projectDir, $platform);
    }

    protected function configure(): void
    {
        $this
            ->addOption('dry-run', null, InputOption::VALUE_NONE,
                'Print the manifest without writing it')
            ->addOption('electron-path', null, InputOption::VALUE_REQUIRED,
                'Electron project to check for manifest support', 'nativephp/electron');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $shape = $this->manifest->toArray();

        $io->definitionList(
            ['CLI' => $shape['cli']],
            ['Router' => $shape['router']],
            ['Doc root' => $shape['docroot']],
            ['APP_ENV' => sprintf('%s (dev) / %s (packaged)', $shape['env']['dev'], $shape['env']['prod'])],
            ['Optimize' => $this->describeLifecycle($shape['lifecycle']['optimize'])],
            ['Migrate' => $this->describeLifecycle($shape['lifecycle']['migrate'])],
            ['Schedule' => $this->describeLifecycle($shape['lifecycle']['schedule'])],
            ['Writable' => implode(', ', $shape['writableDirs'])],
            ['Seed dir' => $shape['seedDir'] ?? '(none — Symfony has no storage/ to copy)'],
            ['Cache env' => [] === $shape['cacheEnv']
                ? '(none — Symfony has no APP_*_CACHE equivalents)'
                : implode(', ', array_keys($shape['cacheEnv'])), ],
        );

        if ($input->getOption('dry-run')) {
            $io->section($this->writer->path());
            $io->write($this->manifest->toJson(), false, OutputInterface::OUTPUT_RAW);
            $io->newLine();

            return Command::SUCCESS;
        }

        $changed = $this->writer->write();

        $io->text($changed
            ? sprintf('Wrote %s', $this->writer->path())
            : sprintf('%s is already up to date.', $this->writer->path()));

        $this->reportRuntime((string) $input->getOption('electron-path'), $io);

        return Command::SUCCESS;
    }

    /**
     * Whether the installed runtime will actually read what we just wrote.
     *
     * Advisory only — the exit code does not depend on it. A manifest is worth
     * having before the runtime is installed, and an app may deliberately keep a
     * patched runtime around.
     */
    private function reportRuntime(string $electronPath, SymfonyStyle $io): void
    {
        // Here the consequence of getting this wrong is only advisory, but it is advice
        // pointing the wrong way: a Windows --electron-path was joined onto the project
        // directory, so an installed runtime was reported missing and the fix suggested
        // was to install it again.
        $electron = $this->paths->absolute($electronPath);

        if (!is_dir($electron)) {
            $io->comment(sprintf('No runtime at %s yet; run native:install when you need one.', $electron));

            return;
        }

        match ($this->detector->detect($electron)) {
            ManifestSupport::Supported => $io->success('The installed runtime reads this manifest. No patching needed.'),
            ManifestSupport::Absent => $io->note([
                'The installed runtime still has Laravel\'s hardcoded paths and will ignore this file.',
                'It works anyway because native:install patched it. The manifest becomes live once the',
                'runtime supports it (NativePHP/desktop patch 0001).',
            ]),
            ManifestSupport::Unknown => $io->warning(sprintf(
                'Could not tell whether the runtime at %s reads a manifest. native:install will patch it to be safe.',
                $electron,
            )),
        };
    }

    /** @param list<string>|null $command */
    private function describeLifecycle(?array $command): string
    {
        if (null === $command || [] === $command) {
            return '(none)';
        }

        return implode(' ', $command);
    }
}
