<?php

declare(strict_types=1);

namespace Native\Symfony\Desktop\Tests;

use Native\Symfony\Desktop\Builder\Builder;
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

    public function testTheBuildDirectoryIsNeverStagedIntoItself(): void
    {
        // `nativephp` is in the shipped exclude default, but `native_desktop.build.exclude`
        // replaces that list rather than adding to it — so an application with its own
        // exclude list staged the previous build into the new one. Unbounded on the second
        // run, and invisible on the first, when the directory is still empty.
        $build = $this->source.'/nativephp/build';
        $builder = new Builder(
            sourcePath: $this->source,
            buildPath: $build,
            excludePatterns: ['var/cache'],   // a plausible custom list, without `nativephp`
        );

        $builder->stageApplication();
        $this->fs->dumpFile($builder->appPath('marker.txt'), 'from the first build');
        $builder->stageApplication();

        // The behavioural assertion first, so this test fails on the recursion itself
        // rather than on a missing accessor when the guard is taken away.
        self::assertFileDoesNotExist($builder->appPath('nativephp/build/app/marker.txt'), 'The previous build must not be staged inside the new one.');
        self::assertFileDoesNotExist($builder->appPath('marker.txt'), 'The second staging clears the directory.');
        self::assertContains('nativephp/build', $builder->excludePatterns());
    }

    public function testARemovedMultiLineValueTakesItsContinuationLinesWithIt(): void
    {
        // Continuation was only tracked for lines that were *kept*, so dropping the first
        // line of a removed secret left its remaining lines to be read as fresh entries —
        // and a line that happens to contain `=`, which base64 padding and JSON both
        // produce, then looked like a key/value pair and was written into the staged .env.
        // The value the remove list existed to strip shipped inside the package anyway.
        $this->write('.env', implode("\n", [
            'APP_SECRET=keepme',
            'STRIPE_SECRET="{',
            '  "key": "sk_live_deadbeef",',
            '  "pad": "AAAA=="',
            '}"',
            'DATABASE_URL=sqlite:///db.sqlite',
            '',
        ]));

        $builder = $this->builder([]);   // its default remove list already has *_SECRET
        $builder->stageApplication();
        $builder->cleanEnvironmentFile();

        $env = (string) file_get_contents($builder->appPath('.env'));

        self::assertStringNotContainsString('sk_live_deadbeef', $env, 'A removed value must not survive in its own continuation lines.');
        self::assertStringNotContainsString('AAAA==', $env);
        self::assertStringNotContainsString('STRIPE_SECRET', $env);
        self::assertStringContainsString('APP_SECRET=keepme', $env);
        self::assertStringContainsString('DATABASE_URL=sqlite:///db.sqlite', $env);
        self::assertSame(0, substr_count($env, '"') % 2, 'Unbalanced quotes mean the staged file no longer parses.');
    }

    public function testAMultiLineQuotedValueSurvivesTheClean(): void
    {
        // Splitting on newlines and dropping every line without an `=` truncated a
        // multi-line value at its first line and left the quote open, so the staged
        // .env no longer parsed. bootEnv() runs before the kernel, so the packaged
        // app died on every launch — and the build reported success.
        $this->write('.env', implode("\n", [
            'APP_ENV=dev',
            'APP_SECRET=abc123',
            'JWT_PASSPHRASE="line one',
            'line two"',
            'DATABASE_URL="mysql://u:p@h/db?opt=1"',
            '',
        ]));

        $builder = $this->builder([]);
        $builder->stageApplication();
        $builder->cleanEnvironmentFile();

        $env = (string) file_get_contents($builder->appPath('.env'));

        self::assertStringContainsString("JWT_PASSPHRASE=\"line one\nline two\"", $env);
        self::assertStringContainsString('DATABASE_URL="mysql://u:p@h/db?opt=1"', $env);
        // The quote count is the cheap proxy for "this still parses".
        self::assertSame(0, substr_count($env, '"') % 2, 'An unbalanced quote means the app cannot boot.');
    }

    public function testAnExportPrefixDoesNotDefeatEitherList(): void
    {
        // `export FOO=bar` is valid Dotenv. Taking the key as everything before the
        // `=` gave "export FOO", and fnmatch is anchored at both ends — so a prefix
        // glob stopped matching and the secret shipped, while a leading-star glob
        // still matched and stripped the APP_SECRET the keep list protects. Both
        // directions wrong, in one file.
        $this->write('.env', "export APP_SECRET=s3cr3t\nexport STRIPE_KEY=sk_live_abc\nAPP_SECRET=plain\n");

        $builder = new Builder(
            sourcePath: $this->source,
            buildPath: $this->build,
            envRemove: ['*_SECRET', 'STRIPE_*'],
            envKeep: ['APP_SECRET'],
        );

        $builder->stageApplication();
        $builder->cleanEnvironmentFile();

        $env = (string) file_get_contents($builder->appPath('.env'));

        self::assertStringContainsString('export APP_SECRET=s3cr3t', $env);
        self::assertStringNotContainsString('STRIPE_KEY', $env);
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

    public function testTheForcedDefaultsAreWrittenEvenWithoutAnEnvFile(): void
    {
        // An app can keep its configuration elsewhere and still need APP_ENV=prod in
        // the package. The defaults are advertised as forced, so "no .env" must not
        // quietly mean "no defaults".
        $builder = $this->builder([]);
        $builder->stageApplication();
        $builder->cleanEnvironmentFile();

        $env = (string) file_get_contents($builder->appPath('.env'));

        self::assertStringContainsString('APP_ENV=prod', $env);
        self::assertStringContainsString('APP_DEBUG=0', $env);
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

    /**
     * Upstream's build path is not built from nothing: `resources/build/.gitignore`
     * whitelists exactly `icon.png`, `IconTemplate.png` and `IconTemplate@2x.png`, so
     * every packaged Laravel app has all three whether or not it ships its own, and
     * `installIcon()` only overrides them. Ours starts empty, and the packaged runtime
     * reads `$NATIVEPHP_BUILD_PATH/icon.png` for the dock and the Linux window icon and
     * the same path with `icon.png` swapped for `IconTemplate.png` for the tray that
     * `MenuBar::create()` builds by default — so an app with an empty public/ handed
     * Electron two paths that do not exist.
     */
    public function testTheRuntimesOwnIconsAreUsedWhenTheAppShipsNone(): void
    {
        $electron = \dirname($this->source).'/electron';
        $this->fs->dumpFile($electron.'/build/icon.png', 'DEFAULT');
        $this->fs->dumpFile($electron.'/build/IconTemplate.png', 'DEFAULT');
        $this->fs->dumpFile($electron.'/build/IconTemplate@2x.png', 'DEFAULT');

        $installed = $this->builder([])->installIcons($electron);

        self::assertSame(['icon.png', 'IconTemplate.png', 'IconTemplate@2x.png'], $installed);
        self::assertSame('DEFAULT', file_get_contents($this->build.'/icon.png'));
        self::assertSame('DEFAULT', file_get_contents($this->build.'/IconTemplate.png'));
        self::assertSame('DEFAULT', file_get_contents($this->build.'/IconTemplate@2x.png'));
    }

    public function testTheAppsOwnIconStillWinsOverTheRuntimesDefault(): void
    {
        $this->write('public/icon.png', 'MINE');

        $electron = \dirname($this->source).'/electron';
        $this->fs->dumpFile($electron.'/build/icon.png', 'DEFAULT');

        $this->builder([])->installIcons($electron);

        self::assertSame('MINE', file_get_contents($this->build.'/icon.png'));
        self::assertSame('MINE', file_get_contents($electron.'/build/icon.png'));
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

    public function testAssetsInstallSymlinksAreStaged(): void
    {
        if ('Windows' === \PHP_OS_FAMILY) {
            self::markTestSkipped('POSIX symlinks only.');
        }

        // `assets:install --symlink --relative` is the Flex default, so this shape is
        // in essentially every real project. A cycle guard that skips any directory
        // already visited drops one of the two paths — and which one is readdir
        // order — packaging an app whose bundle assets all 404, with a build that
        // still reports success.
        $this->write('vendor/acme/admin-bundle/public/admin.css', 'body{}');
        $this->write('public/index.php', '<?php');
        $this->fs->mkdir($this->source.'/public/bundles');
        symlink('../../vendor/acme/admin-bundle/public', $this->source.'/public/bundles/acmeadmin');

        $builder = $this->builder([]);
        $builder->stageApplication();

        self::assertFileExists($builder->appPath('public/bundles/acmeadmin/admin.css'));
        self::assertFileExists($builder->appPath('vendor/acme/admin-bundle/public/admin.css'));
    }

    public function testTwoDirectoriesLinkedToEachOtherTerminate(): void
    {
        if ('Windows' === \PHP_OS_FAMILY) {
            self::markTestSkipped('POSIX symlinks only.');
        }

        // A mutual cycle, which an "is my target my own parent" test would miss:
        // the repeat only shows up as an ancestor further down the path.
        $this->write('one/a.txt', 'a');
        $this->write('two/b.txt', 'b');
        symlink($this->source.'/two', $this->source.'/one/to-two');
        symlink($this->source.'/one', $this->source.'/two/to-one');

        $copied = $this->builder([])->stageApplication();

        // Terminates, and every real file is reachable from both sides exactly once
        // more than its own copy: a.txt, b.txt, one/to-two/b.txt, two/to-one/a.txt.
        self::assertSame(4, $copied);
    }

    public function testADanglingSymlinkIsSkippedRatherThanAbortingTheBuild(): void
    {
        if ('Windows' === \PHP_OS_FAMILY) {
            self::markTestSkipped('POSIX symlinks only.');
        }

        // Left behind whenever a link's target is deleted, or an absolute link is
        // carried over from another machine. copy() throws on it, and BuildCommand
        // has no catch, so the whole build died with a stack trace.
        $this->write('src/Kernel.php', '<?php');
        symlink('/nonexistent/target.txt', $this->source.'/public-storage');

        $copied = $this->builder([])->stageApplication();

        self::assertSame(1, $copied);
        self::assertFileExists($this->builder([])->appPath('src/Kernel.php'));
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
