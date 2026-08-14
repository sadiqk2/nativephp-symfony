<?php

declare(strict_types=1);

namespace App\Native\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Requirement 2 of the runtime contract: print the app config as JSON on stdout.
 *
 * The runtime runs this BEFORE its API server exists (boot step 2), captures
 * stdout with execFile and JSON.parse()s it. So: nothing but JSON on stdout,
 * and never assume NATIVEPHP_API_URL / NATIVEPHP_SECRET are set here.
 *
 * Of the ~90-line Laravel config/nativephp.php the runtime reads exactly five
 * keys (CONTRACT.md section 14). These are those five.
 */
#[AsCommand(name: 'native:config', description: 'Print the NativePHP startup configuration as JSON')]
final class NativeConfigCommand extends Command
{
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $output->write(json_encode([
            'app_id' => $_ENV['NATIVEPHP_APP_ID'] ?? 'com.nativephp.symfony',
            'deeplink_scheme' => $_ENV['NATIVEPHP_DEEPLINK_SCHEME'] ?? null,
            'updater' => [
                'enabled' => false,
                'default' => 'github',
                'providers' => [],
            ],
        ], \JSON_THROW_ON_ERROR));

        return Command::SUCCESS;
    }
}
