<?php

declare(strict_types=1);

namespace Native\Symfony\Mobile\Command;

use Native\Symfony\Mobile\Runtime\MobileRuntimePatcher;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Filesystem\Filesystem;

/**
 * Copy the Android and iOS projects into the application and retarget them.
 *
 * The bundle does not vendor those projects: they are ~25 MB including embedded PHP
 * headers, and requiring `nativephp/mobile` would pull in illuminate/*. So the
 * source is pointed at explicitly, exactly as the desktop install does.
 */
#[AsCommand(name: 'native:mobile:install', description: 'Install and patch the Android and iOS projects')]
final class InstallCommand extends Command
{
    public function __construct(
        private readonly string $projectDir,
        private readonly MobileRuntimePatcher $patcher,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('source', null, InputOption::VALUE_REQUIRED,
                "Path to NativePHP mobile's resources directory (containing androidstudio/ and xcode/)")
            ->addOption('platform', null, InputOption::VALUE_REQUIRED, 'android, ios or both', 'both')
            ->addOption('force', 'f', InputOption::VALUE_NONE, 'Overwrite existing projects');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $fs = new Filesystem();

        $source = $input->getOption('source');

        if (!\is_string($source) || !is_dir($source)) {
            $io->error([
                'A --source is required: the resources/ directory of NativePHP mobile.',
                '  git clone --depth 1 https://github.com/NativePHP/mobile-air /tmp/np-mobile',
                '  bin/console native:mobile:install --source=/tmp/np-mobile/resources',
            ]);

            return Command::INVALID;
        }

        $platform = (string) $input->getOption('platform');
        $force = (bool) $input->getOption('force');

        $io->warning([
            'NativePHP Mobile is a commercial product, unlike the MIT-licensed desktop runtime.',
            'Check its licence terms before distributing anything built this way.',
        ]);

        $done = [];

        foreach ([
            'android' => ['androidstudio', 'nativephp/android'],
            'ios' => ['xcode', 'nativephp/ios'],
        ] as $name => [$from, $to]) {
            if ('both' !== $platform && $platform !== $name) {
                continue;
            }

            $sourceDir = rtrim($source, '/').'/'.$from;
            $targetDir = $this->projectDir.'/'.$to;

            if (!is_dir($sourceDir)) {
                $io->warning(sprintf('No %s project at %s; skipping.', $name, $sourceDir));

                continue;
            }

            if (is_dir($targetDir) && !$force) {
                $io->error(sprintf('%s already exists. Pass --force to overwrite.', $targetDir));

                return Command::FAILURE;
            }

            $io->section(sprintf('Copying the %s project', $name));
            $fs->mkdir(\dirname($targetDir));
            $fs->mirror($sourceDir, $targetDir, options: ['override' => true, 'delete' => true]);
            $io->text('→ '.$targetDir);

            $io->section(sprintf('Retargeting the %s bootstrap paths', $name));

            $applied = 'android' === $name
                ? $this->patcher->patchAndroid($targetDir)
                : $this->patcher->patchIos($targetDir);

            foreach ($applied as $line) {
                $io->text(' • '.$line);
            }

            $done[] = $name;
        }

        if ([] === $done) {
            $io->error('Nothing was installed.');

            return Command::FAILURE;
        }

        $io->success([
            'Installed: '.implode(', ', $done),
            'Next: build with Android Studio or Xcode. There is no headless path —',
            'both toolchains are required and neither runs in CI without a licence.',
        ]);

        return Command::SUCCESS;
    }
}
