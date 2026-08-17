<?php

declare(strict_types=1);

namespace Native\Symfony\Mobile\Tests;

use Native\Symfony\Mobile\Runtime\MobilePatchFailed;
use Native\Symfony\Mobile\Runtime\MobileRuntimePatcher;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;

/**
 * Retargeting the hosts' hardcoded bootstrap paths.
 *
 * The class had no tests, which is how two silent failures survived in it: an unwritable
 * source file was reported as patched, and a malformed `bundle_meta.json` was replaced by a
 * two-key file — dropping the identity the hosts compare to decide whether to re-extract,
 * so a device would go on running the previous build with nothing to explain it.
 *
 * Every assertion here is about the same promise the class docblock makes: it throws rather
 * than skipping, because on a device a skipped patch is a blank screen and no diagnostic.
 */
final class MobileRuntimePatcherTest extends TestCase
{
    private string $root;

    private const KOTLIN = <<<'KT'
        class PHPBridge {
            fun boot() {
                val script = "${getLaravelPath()}/vendor/nativephp/mobile/bootstrap/android/native.php"
                val persistent = "${getLaravelPath()}/vendor/nativephp/mobile/bootstrap/android/persistent.php"
                val console = "${getLaravelPath()}/vendor/nativephp/mobile/bootstrap/android/artisan.php"
            }
        }
        KT;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir().'/np-mobile-patcher-'.bin2hex(random_bytes(6));
        (new Filesystem())->dumpFile($this->bridge(), self::KOTLIN);
    }

    protected function tearDown(): void
    {
        (new Filesystem())->remove($this->root);
    }

    public function testItRetargetsEveryAndroidBootstrapPath(): void
    {
        $applied = (new MobileRuntimePatcher())->patchAndroid($this->root.'/android');
        $kotlin = (string) file_get_contents($this->bridge());

        self::assertCount(3, $applied);
        self::assertStringNotContainsString('/vendor/nativephp/mobile/bootstrap/', $kotlin);
        self::assertStringContainsString(MobileRuntimePatcher::SHIM_DIR.'/native.php', $kotlin);
        self::assertStringContainsString(MobileRuntimePatcher::SHIM_DIR.'/persistent.php', $kotlin);
        // A Symfony app has no artisan; the console shim answers for it.
        self::assertStringContainsString(MobileRuntimePatcher::SHIM_DIR.'/console.php', $kotlin);
        self::assertStringNotContainsString('artisan.php', $kotlin);
    }

    public function testItIsIdempotent(): void
    {
        $patcher = new MobileRuntimePatcher();
        $patcher->patchAndroid($this->root.'/android');

        $second = $patcher->patchAndroid($this->root.'/android');

        self::assertNotSame([], $second);
        foreach ($second as $line) {
            self::assertStringContainsString('already applied', $line);
        }
    }

    public function testAPatchThatCannotBeWrittenIsAnError(): void
    {
        // Reported as applied while the file on disk still points at another vendor's
        // package — the launches-to-nothing failure, produced by a discarded return value.
        chmod($this->bridge(), 0o444);

        try {
            $this->expectException(MobilePatchFailed::class);
            $this->expectExceptionMessageMatches('/could not write it back/');

            (new MobileRuntimePatcher())->patchAndroid($this->root.'/android');
        } finally {
            chmod($this->bridge(), 0o644);
        }
    }

    public function testAnUnrecognisedBridgeIsAnErrorRatherThanANoOp(): void
    {
        (new Filesystem())->dumpFile($this->bridge(), 'class PHPBridge { fun boot() {} }');

        $this->expectException(MobilePatchFailed::class);

        (new MobileRuntimePatcher())->patchAndroid($this->root.'/android');
    }

    public function testForcingWebEntryModeKeepsTheRestOfTheManifest(): void
    {
        $meta = $this->root.'/bundle_meta.json';
        (new Filesystem())->dumpFile($meta, json_encode([
            'version' => '1.0.0+7',
            'entry_mode' => 'native',
            'native_routes' => ['/items/{id}'],
        ]));

        self::assertTrue((new MobileRuntimePatcher())->forceWebEntryMode($meta));

        /** @var array<string, mixed> $written */
        $written = json_decode((string) file_get_contents($meta), true);

        self::assertSame('web', $written['entry_mode']);
        self::assertSame([], $written['native_routes']);
        // The version is the host's re-extraction key. Losing it is how a device ends up
        // running the previous build.
        self::assertSame('1.0.0+7', $written['version']);
        self::assertFalse((new MobileRuntimePatcher())->forceWebEntryMode($meta), 'Already web: nothing to do.');
    }

    public function testAMalformedManifestIsRefusedRatherThanOverwritten(): void
    {
        $meta = $this->root.'/bundle_meta.json';
        (new Filesystem())->dumpFile($meta, '{"version": "1.0.0+7", trunc');

        try {
            (new MobileRuntimePatcher())->forceWebEntryMode($meta);
            self::fail('A manifest that cannot be read must not be replaced by a two-key one.');
        } catch (MobilePatchFailed) {
            self::assertStringContainsString('trunc', (string) file_get_contents($meta));
        }
    }

    public function testAScalarManifestIsRefusedRatherThanFatal(): void
    {
        // Assigning an offset to a string is a TypeError, which used to happen mid-install.
        $meta = $this->root.'/bundle_meta.json';
        (new Filesystem())->dumpFile($meta, '"web"');

        $this->expectException(MobilePatchFailed::class);

        (new MobileRuntimePatcher())->forceWebEntryMode($meta);
    }

    public function testAnAbsentManifestIsSimplyNothingToDo(): void
    {
        self::assertFalse((new MobileRuntimePatcher())->forceWebEntryMode($this->root.'/nope.json'));
    }

    public function testAnIosProjectWithNoSourcesIsAnError(): void
    {
        $this->expectException(MobilePatchFailed::class);

        (new MobileRuntimePatcher())->patchIos($this->root.'/ios');
    }

    private function bridge(): string
    {
        return $this->root.'/android/app/src/main/java/com/nativephp/mobile/bridge/PHPBridge.kt';
    }
}
