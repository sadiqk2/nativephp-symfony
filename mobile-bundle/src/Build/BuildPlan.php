<?php

declare(strict_types=1);

namespace Native\Symfony\Mobile\Build;

/**
 * Builds the command sequences a debug run or a release build would execute.
 *
 * Every argv here is transcribed from upstream, which is the only available authority:
 * `Concerns/RunsAndroid.php` for the Gradle tasks, APK paths, `adb install -r -d` and the
 * `am start` component name; `Concerns/RunsIos.php` and `Commands/BuildIosAppCommand.php`
 * for the xcodebuild invocation, the simulator product path and the simctl sequence;
 * `Concerns/PackagesIos.php` for `-exportArchive`.
 *
 * **None of it has been executed.** There is no Android SDK and no Xcode in this
 * environment, so what is verified is that the plan is *the plan* — argv shape, ordering,
 * artifact paths, and that device serials and app ids travel as separate arguments rather
 * than interpolated into a shell string (upstream builds several of these by string
 * concatenation, which breaks on a path with a space and is worse than that with hostile
 * input). Whether Gradle accepts them is a claim only a real device run can make.
 *
 * Two upstream details deliberately not copied:
 *  - the `-verbose` flag on xcodebuild — it makes a failure log unreadable, and the
 *    commands stream output already;
 *  - TTY allocation, which Symfony's Process only manages on a real terminal and which
 *    changes Gradle's output format. {@see ProcessRunner} never allocates one.
 */
final class BuildPlan
{
    /**
     * Component the Android host launches. A literal in the copied project's manifest
     * (`com.nativephp.mobile.ui.MainActivity`), not something the app id controls — the
     * activity keeps NativePHP's package even when the application id is the user's.
     */
    public const ANDROID_MAIN_ACTIVITY = 'com.nativephp.mobile.ui.MainActivity';

    /** Xcode scheme names in the copied project. The simulator build uses a separate scheme. */
    public const IOS_SCHEME = 'NativePHP';

    public const IOS_SIMULATOR_SCHEME = 'NativePHP-simulator';

    /**
     * Gradle output paths, relative to the Android project root.
     *
     * @return string Absolute path to the artifact the given build type produces
     */
    public static function androidArtifact(string $projectDir, string $buildType): string
    {
        $project = MobilePlatform::Android->projectPath($projectDir);

        return $project.match ($buildType) {
            'debug' => '/app/build/outputs/apk/debug/app-debug.apk',
            'release' => '/app/build/outputs/apk/release/app-release.apk',
            'bundle' => '/app/build/outputs/bundle/release/app-release.aab',
            default => throw new \InvalidArgumentException(sprintf('Unknown Android build type "%s".', $buildType)),
        };
    }

    public static function androidGradleTask(string $buildType): string
    {
        return match ($buildType) {
            'debug' => 'assembleDebug',
            'release' => 'assembleRelease',
            'bundle' => 'bundleRelease',
            default => throw new \InvalidArgumentException(sprintf('Unknown Android build type "%s".', $buildType)),
        };
    }

    /**
     * Debug build, install and launch.
     *
     * `assembleDebug` then `adb install`, rather than Gradle's own `installDebug`: with more
     * than one device attached Gradle's task is ambiguous, while `adb -s` names the target.
     * `-r` reinstalls over an existing copy and `-d` permits a version-code downgrade, which
     * a DEBUG-versioned build over a released one needs.
     *
     * @param string|null $deviceSerial From `adb devices`; null lets adb pick, which only
     *                                  works when exactly one device is attached
     *
     * @return list<PlannedCommand>
     */
    public static function androidRun(string $projectDir, string $appId, ?string $deviceSerial = null): array
    {
        $project = MobilePlatform::Android->projectPath($projectDir);
        $target = null === $deviceSerial ? [] : ['-s', $deviceSerial];

        return [
            new PlannedCommand(['./gradlew', 'assembleDebug'], $project, 'Compile the debug APK'),
            new PlannedCommand(
                ['adb', ...$target, 'install', '-r', '-d', self::androidArtifact($projectDir, 'debug')],
                $project,
                'Install the APK on the device',
                // A debug APK carries the whole PHP runtime and is ~200 MB; a cold emulator
                // takes minutes to accept it, well past any default timeout.
                timeout: 600,
            ),
            new PlannedCommand(
                ['adb', ...$target, 'shell', 'am', 'start', '-n', $appId.'/'.self::ANDROID_MAIN_ACTIVITY],
                $project,
                'Launch the app',
                timeout: 120,
            ),
        ];
    }

    /**
     * List attached devices — the first thing to run when a run fails, and cheap enough to
     * offer as its own step.
     */
    public static function androidDevices(string $projectDir): PlannedCommand
    {
        return new PlannedCommand(['adb', 'devices', '-l'], MobilePlatform::Android->projectPath($projectDir), 'List attached devices and emulators', timeout: 60);
    }

    /**
     * Release build. Produces an APK or an AAB; nothing is installed.
     *
     * Signing is not handled here. Gradle reads it from the project's own
     * `signingConfigs`, which is where a keystore belongs — passing a keystore password
     * through a command line would put it in the process table.
     *
     * @return list<PlannedCommand>
     */
    public static function androidBuild(string $projectDir, bool $aab = false): array
    {
        $project = MobilePlatform::Android->projectPath($projectDir);

        return [
            new PlannedCommand(['./gradlew', self::androidGradleTask($aab ? 'bundle' : 'release')], $project, $aab ? 'Assemble the release AAB' : 'Assemble the release APK'),
        ];
    }

    /**
     * The workspace argument xcodebuild needs.
     *
     * CocoaPods generates `NativePHP.xcworkspace`; without pods the project's own implicit
     * workspace is used. Upstream picks between them by file existence and so does this.
     */
    public static function iosWorkspace(string $projectDir): string
    {
        $project = MobilePlatform::Ios->projectPath($projectDir);

        return is_file($project.'/NativePHP.xcworkspace/contents.xcworkspacedata')
            ? 'NativePHP.xcworkspace'
            : 'NativePHP.xcodeproj/project.xcworkspace';
    }

    public static function iosArchivePath(string $projectDir): string
    {
        return MobilePlatform::Ios->projectPath($projectDir).'/build/NativePHP.xcarchive';
    }

    public static function iosExportPath(string $projectDir): string
    {
        return MobilePlatform::Ios->projectPath($projectDir).'/build/ipa';
    }

    /** Where `simctl install` expects to find the built simulator app. */
    public static function iosSimulatorApp(string $projectDir): string
    {
        return MobilePlatform::Ios->projectPath($projectDir).'/build/Build/Products/Debug-iphonesimulator/NativePHP-simulator.app';
    }

    /**
     * Debug build for a simulator, then boot, install and launch.
     *
     * Simulator only. A physical device needs a provisioning profile and a signing
     * identity, and `devicectl` instead of `simctl`; that path is upstream's
     * `ManagesIosSigning` and is not reimplemented blind — a signing flow that cannot be
     * tested here would be a liability rather than a feature.
     *
     * @return list<PlannedCommand>
     */
    public static function iosRun(string $projectDir, string $appId, string $udid): array
    {
        $project = MobilePlatform::Ios->projectPath($projectDir);

        return [
            new PlannedCommand([
                'xcodebuild',
                '-scheme', self::IOS_SIMULATOR_SCHEME,
                '-workspace', self::iosWorkspace($projectDir),
                '-sdk', 'iphonesimulator',
                '-derivedDataPath', 'build',
                // Swift package plugins re-sign bundles during validation and break the
                // build; upstream skips validation for the same reason.
                '-skipPackagePluginValidation',
                '-destination', 'id='.$udid,
                'build',
            ], $project, 'Compile for the simulator'),
            new PlannedCommand(['xcrun', 'simctl', 'boot', $udid], $project, 'Boot the simulator', timeout: 300, tolerateFailure: true),
            new PlannedCommand(['xcrun', 'simctl', 'install', $udid, self::iosSimulatorApp($projectDir)], $project, 'Install the app', timeout: 600),
            new PlannedCommand(['xcrun', 'simctl', 'launch', $udid, $appId], $project, 'Launch the app', timeout: 120),
        ];
    }

    /** Available simulators and devices, for choosing a `--udid`. */
    public static function iosDevices(string $projectDir): PlannedCommand
    {
        return new PlannedCommand(['xcrun', 'simctl', 'list', 'devices', 'available'], MobilePlatform::Ios->projectPath($projectDir), 'List available simulators', timeout: 120);
    }

    /**
     * Archive for the device, and optionally export an .ipa.
     *
     * The export step needs an `exportOptions.plist` naming a signing style and, for
     * manual signing, a provisioning profile. {@see MobileBuilder::writeExportOptions()}
     * writes a minimal one; anything beyond `development` distribution needs a real Apple
     * Developer account, which cannot be exercised here at all.
     *
     * @return list<PlannedCommand>
     */
    public static function iosBuild(string $projectDir, ?string $exportOptionsPlist = null, bool $release = true): array
    {
        $project = MobilePlatform::Ios->projectPath($projectDir);

        $plan = [
            new PlannedCommand([
                'xcodebuild',
                '-scheme', self::IOS_SCHEME,
                '-workspace', self::iosWorkspace($projectDir),
                '-sdk', 'iphoneos',
                '-configuration', $release ? 'Release' : 'Debug',
                '-derivedDataPath', 'build',
                '-skipPackagePluginValidation',
                '-archivePath', self::iosArchivePath($projectDir),
                'archive',
            ], $project, 'Archive for the device'),
        ];

        if (null !== $exportOptionsPlist) {
            $plan[] = new PlannedCommand([
                'xcodebuild',
                '-exportArchive',
                '-archivePath', self::iosArchivePath($projectDir),
                '-exportPath', self::iosExportPath($projectDir),
                '-exportOptionsPlist', $exportOptionsPlist,
            ], $project, 'Export an .ipa from the archive');
        }

        return $plan;
    }
}
