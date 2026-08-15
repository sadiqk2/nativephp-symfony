<?php

declare(strict_types=1);

namespace Native\Symfony\Tests;

use Native\Symfony\Builder\Builder;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;

final class BuilderTest extends TestCase
{
    private string $source;
    private string $build;
    private Filesystem $fs;

    protected function setUp(): void
    {
        $this->fs = new Filesystem();
        $root = sys_get_temp_dir().'/np-builder-'.bin2hex(random_bytes(6));
        $this->source = $root.'/app';
        $this->build = $root.'/build';

        $this->fs->mkdir([$this->source, $this->build]);
    }

    protected function tearDown(): void
    {
        $this->fs->remove(\dirname($this->source));
    }

    public function testItStagesTheApplicationAndSkipsExcludedPaths(): void
    {
        $this->write('src/Kernel.php', '<?php');
        $this->write('public/index.php', '<?php');
        $this->write('vendor/autoload.php', '<?php');
        $this->write('tests/SomeTest.php', '<?php');
        $this->write('node_modules/left-pad/index.js', '//');
        $this->write('.git/config', '[core]');
        $this->write('var/cache/prod/container.php', '<?php');
        $this->write('database.sqlite', '');

        $builder = $this->builder(['tests', 'node_modules', '.git', 'var/cache', '*.sqlite']);
        $copied = $builder->stageApplication();

        self::assertFileExists($builder->appPath('src/Kernel.php'));
        self::assertFileExists($builder->appPath('public/index.php'));
        self::assertFileExists($builder->appPath('vendor/autoload.php'));

        self::assertFileDoesNotExist($builder->appPath('tests/SomeTest.php'));
        self::assertFileDoesNotExist($builder->appPath('.git/config'));
        self::assertFileDoesNotExist($builder->appPath('database.sqlite'));
        self::assertFileDoesNotExist($builder->appPath('var/cache/prod/container.php'));

        // An excluded directory is never descended into, so its contents are not
        // copied and then deleted — which is the difference between a fast build
        // and copying node_modules twice.
        self::assertDirectoryDoesNotExist($builder->appPath('node_modules'));

        self::assertSame(3, $copied);
    }

    public function testItCreatesPlaceholdersForDirectoriesThatMustSurvive(): void
    {
        // electron-builder prunes empty directories out of the package and dotfiles
        // do not stop it. Symfony will not boot without a writable var/cache.
        $this->write('src/Kernel.php', '<?php');

        $builder = $this->builder(['var/cache'], keep: ['var/cache', 'var/log']);
        $builder->stageApplication();

        self::assertFileExists($builder->appPath('var/cache/.nativephp-keep'));
        self::assertFileExists($builder->appPath('var/log/.nativephp-keep'));
    }

    public function testItPreservesTheExecutableBitOnBinConsole(): void
    {
        if ('Windows' === \PHP_OS_FAMILY) {
            self::markTestSkipped('POSIX permissions only.');
        }

        $this->write('bin/console', "#!/usr/bin/env php\n");
        chmod($this->source.'/bin/console', 0o755);

        $builder = $this->builder([]);
        $builder->stageApplication();

        // The runtime invokes bin/console directly; a non-executable copy makes the
        // whole app fail to boot, and the cause is very unobvious.
        self::assertSame('0755', substr(sprintf('%o', fileperms($builder->appPath('bin/console'))), -4));
    }

    public function testItStripsSecretsFromTheStagedEnvAndForcesProductionValues(): void
    {
        $this->write('.env', implode("\n", [
            '# a comment',
            'APP_ENV=dev',
            'APP_DEBUG=1',
            'APP_SECRET=abc123',
            'AWS_ACCESS_KEY_ID=AKIA',
            'STRIPE_TOKEN=sk_live_x',
            'DATABASE_URL=sqlite:///%kernel.project_dir%/var/data.db',
            '',
        ]));

        $builder = $this->builder([]);
        $builder->stageApplication();
        $builder->cleanEnvironmentFile();

        $env = (string) file_get_contents($builder->appPath('.env'));

        self::assertStringNotContainsString('AWS_ACCESS_KEY_ID', $env);
        self::assertStringNotContainsString('STRIPE_TOKEN', $env);
        // Kept by env_keep, which wins over the *_SECRET glob.
        self::assertStringContainsString('APP_SECRET=abc123', $env);
        self::assertStringNotContainsString('# a comment', $env);

        // Kept, because the app cannot run without it.
        self::assertStringContainsString('DATABASE_URL=', $env);

        // Forced, and the dev values gone rather than duplicated.
        self::assertStringContainsString('APP_ENV=prod', $env);
        self::assertStringContainsString('APP_DEBUG=0', $env);
        self::assertStringNotContainsString('APP_ENV=dev', $env);
        self::assertStringNotContainsString('APP_DEBUG=1', $env);
    }

    public function testAppSecretSurvivesEvenABlanketSecretGlob(): void
    {
        // Upstream's Laravel list globs *_SECRET, which is harmless there (its key
        // is APP_KEY) and fatal here: framework.yaml reads %env(APP_SECRET)%, so a
        // stripped one means the packaged app cannot boot at all. Found by
        // inspecting a real build, not by reading.
        $this->write('.env', "APP_SECRET=s3cret\nSTRIPE_SECRET=sk_live\n");

        $builder = new Builder(
            sourcePath: $this->source,
            buildPath: $this->build,
            envRemove: ['*_SECRET'],
            envKeep: ['APP_SECRET'],
        );

        $builder->stageApplication();
        $builder->cleanEnvironmentFile();

        $env = (string) file_get_contents($builder->appPath('.env'));

        self::assertStringContainsString('APP_SECRET=s3cret', $env);
        self::assertStringNotContainsString('STRIPE_SECRET', $env);
    }

    public function testCleaningTheEnvNeverTouchesTheDevelopersOwnFile(): void
    {
        $original = "APP_ENV=dev\nAWS_ACCESS_KEY_ID=AKIA\n";
        $this->write('.env', $original);

        $builder = $this->builder([]);
        $builder->stageApplication();
        $builder->cleanEnvironmentFile();

        self::assertSame($original, file_get_contents($this->source.'/.env'));
    }

    public function testCleaningIsANoOpWithoutAnEnvFile(): void
    {
        $builder = $this->builder([]);
        $builder->stageApplication();
        $builder->cleanEnvironmentFile();

        self::assertFileDoesNotExist($builder->appPath('.env'));
    }

    public function testItInstallsTheCertificateAuthority(): void
    {
        $this->write('vendor/nativephp/php-bin/cacert.pem', 'PEM');

        $builder = $this->builder([]);

        self::assertTrue($builder->installCertificateAuthority());
        self::assertSame('PEM', file_get_contents($builder->buildPath('cacert.pem')));
    }

    public function testMissingCertificateAuthorityIsReportedNotFatal(): void
    {
        // Not fatal, but every outbound HTTPS call in the packaged app fails, so the
        // caller has to be able to warn about it.
        self::assertFalse($this->builder([])->installCertificateAuthority());
    }

    public function testItInstallsIconsIntoBothPlaces(): void
    {
        $this->write('public/icon.png', 'PNG');
        $this->write('public/IconTemplate.png', 'PNG');

        $electron = \dirname($this->source).'/electron';
        $this->fs->mkdir($electron.'/build');

        $installed = $this->builder([])->installIcons($electron);

        self::assertSame(['icon.png', 'IconTemplate.png'], $installed);
        // The build dir travels into the package as extraResources…
        self::assertFileExists($this->build.'/icon.png');
        self::assertFileExists($this->build.'/IconTemplate.png');
        // …while electron-builder reads the app icon from its own buildResources.
        self::assertFileExists($electron.'/build/icon.png');
        // A tray template is not an app icon, so it does not belong there.
        self::assertFileDoesNotExist($electron.'/build/IconTemplate.png');
    }

    public function testMissingIconsAreSkippedQuietly(): void
    {
        $electron = \dirname($this->source).'/electron';
        $this->fs->mkdir($electron.'/build');

        self::assertSame([], $this->builder([])->installIcons($electron));
    }

    public function testHooksRunInTheProjectRootAndStopOnFailure(): void
    {
        $builder = $this->builder([]);

        self::assertTrue($builder->runHooks(['pwd > hook-ran.txt']));
        self::assertSame($this->source, trim((string) file_get_contents($this->source.'/hook-ran.txt')));

        self::assertFalse($builder->runHooks(['exit 3', 'touch should-not-exist.txt']));
        self::assertFileDoesNotExist($this->source.'/should-not-exist.txt');
    }

    public function testStagingIsRepeatableAndDoesNotAccumulate(): void
    {
        $this->write('src/One.php', '<?php');
        $builder = $this->builder([]);
        $builder->stageApplication();

        $this->fs->remove($this->source.'/src/One.php');
        $this->write('src/Two.php', '<?php');
        $builder->stageApplication();

        // A stale file from a previous build must not ship.
        self::assertFileDoesNotExist($builder->appPath('src/One.php'));
        self::assertFileExists($builder->appPath('src/Two.php'));
    }

    /**
     * @param list<string> $exclude
     * @param list<string> $keep
     */
    public function testASymlinkLoopDoesNotCopyTheTreeOverAndOver(): void
    {
        if ('Windows' === \PHP_OS_FAMILY) {
            self::markTestSkipped('POSIX symlinks only.');
        }

        // `public/storage -> ..` is a link people actually write. Followed without a
        // cycle guard it re-enters the tree until opendir() fails on path length,
        // and the iterator then stops silently — so the symptom is a package that is
        // quietly dozens of times too large, not an error anyone would notice.
        $this->write('src/Kernel.php', '<?php');
        $this->write('public/index.php', '<?php');
        symlink($this->source, $this->source.'/public/storage');

        $builder = $this->builder([]);
        $copied = $builder->stageApplication();

        self::assertFileExists($builder->appPath('src/Kernel.php'));
        self::assertFileExists($builder->appPath('public/index.php'));

        // Each real file exactly once, and no second copy underneath the link.
        self::assertSame(2, $copied);
        self::assertFileDoesNotExist($builder->appPath('public/storage/src/Kernel.php'));
    }

    public function testADirectorySymlinkPointingOutsideTheProjectIsStillCopied(): void
    {
        if ('Windows' === \PHP_OS_FAMILY) {
            self::markTestSkipped('POSIX symlinks only.');
        }

        // The cycle guard must not reject ordinary links: a composer path repository
        // installed with `symlink: true` puts exactly this in vendor/, and dropping
        // it would package an app whose own dependencies are missing.
        $outside = \dirname($this->source).'/outside';
        $this->fs->dumpFile($outside.'/Bundle.php', '<?php');
        symlink($outside, $this->source.'/vendor-link');

        $builder = $this->builder([]);
        $builder->stageApplication();

        self::assertFileExists($builder->appPath('vendor-link/Bundle.php'));
    }

    private function builder(array $exclude, array $keep = []): Builder
    {
        return new Builder(
            sourcePath: $this->source,
            buildPath: $this->build,
            excludePatterns: $exclude,
            keepDirectories: $keep,
            envDefaults: ['APP_ENV' => 'prod', 'APP_DEBUG' => '0'],
            envRemove: ['AWS_*', '*_SECRET', '*_TOKEN'],
            envKeep: ['APP_SECRET'],
        );
    }

    private function write(string $relative, string $contents): void
    {
        $this->fs->dumpFile($this->source.'/'.$relative, $contents);
    }
}
