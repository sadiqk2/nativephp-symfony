<?php

declare(strict_types=1);

namespace Native\Symfony\Mobile\Build;

/**
 * Finds the Android and Xcode tooling, or explains precisely what is absent.
 *
 * **This environment has neither toolchain**, so nothing below has ever located a real
 * `adb` or `xcodebuild`. What *is* verified is the search itself: the tests build fake
 * SDK layouts and PATH entries out of temporary directories and assert what is found,
 * what is reported missing, and which hint is printed. The candidate locations are taken
 * from upstream's own resolvers (`Concerns/RunsAndroid.php`, `Concerns/LaunchesAndroidEmulator.php`,
 * `Concerns/RunsIos.php`), not invented.
 *
 * Environment is injected rather than read from `getenv()` so that detection is testable
 * without mutating the process environment — which would leak between tests.
 */
final class Toolchain
{
    /**
     * @param array<string, string> $env       Usually `getenv()`; PATH, HOME, ANDROID_HOME, JAVA_HOME are read
     * @param string                $osFamily  Usually PHP_OS_FAMILY; iOS work is gated on 'Darwin'
     */
    public function __construct(
        private readonly array $env,
        private readonly string $osFamily = \PHP_OS_FAMILY,
    ) {
    }

    public static function fromEnvironment(): self
    {
        /** @var array<string, string> $env */
        $env = getenv();

        return new self($env);
    }

    public function inspect(MobilePlatform $platform, string $projectDir): ToolchainReport
    {
        return new ToolchainReport($platform, match ($platform) {
            MobilePlatform::Android => $this->androidChecks($projectDir),
            MobilePlatform::Ios => $this->iosChecks($projectDir),
        });
    }

    /**
     * Locate an executable on PATH, plus any extra directories.
     *
     * Does the PATH walk in PHP rather than shelling out to `which`/`where`: this is
     * called before we know any toolchain exists, and `which` is itself absent on a bare
     * Windows host.
     *
     * @param list<string> $extraDirectories Searched before PATH — an SDK-relative hit is more
     *                                       trustworthy than whatever a shell profile put on PATH
     */
    public function find(string $binary, array $extraDirectories = []): ?string
    {
        $name = 'Windows' === $this->osFamily ? $binary.'.exe' : $binary;

        $dirs = [...$extraDirectories, ...explode('Windows' === $this->osFamily ? ';' : ':', $this->env['PATH'] ?? '')];

        foreach ($dirs as $dir) {
            if ('' === $dir) {
                continue;
            }

            $candidate = rtrim($dir, '/\\').\DIRECTORY_SEPARATOR.$name;

            if (is_file($candidate) && is_executable($candidate)) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * The Android SDK root, from the environment or the platform's conventional location.
     *
     * ANDROID_HOME first because that is what Android Studio itself exports; ANDROID_SDK_ROOT
     * is the older name and both are still in the wild.
     */
    public function androidSdkPath(): ?string
    {
        $candidates = [
            $this->env['ANDROID_HOME'] ?? null,
            $this->env['ANDROID_SDK_ROOT'] ?? null,
        ];

        $home = $this->env['HOME'] ?? null;

        if (null !== $home) {
            $candidates[] = 'Darwin' === $this->osFamily
                ? $home.'/Library/Android/sdk'
                : $home.'/Android/Sdk';
        }

        if (isset($this->env['LOCALAPPDATA'])) {
            $candidates[] = $this->env['LOCALAPPDATA'].'\\Android\\Sdk';
        }

        foreach ($candidates as $candidate) {
            if (\is_string($candidate) && '' !== $candidate && is_dir($candidate)) {
                return $candidate;
            }
        }

        return null;
    }

    /** @return list<ToolchainCheck> */
    private function androidChecks(string $projectDir): array
    {
        $project = MobilePlatform::Android->projectPath($projectDir);
        $wrapper = $project.'/gradlew';
        $sdk = $this->androidSdkPath();

        // platform-tools is very often not on PATH even on a working developer machine —
        // Android Studio does not add it — so the SDK-relative location is checked too.
        $adbDirs = null === $sdk ? [] : [$sdk.'/platform-tools'];

        $java = $this->find('java', isset($this->env['JAVA_HOME']) ? [$this->env['JAVA_HOME'].'/bin'] : []);

        return [
            new ToolchainCheck(
                'Android project',
                is_dir($project) ? $project : null,
                'Run `bin/console native:mobile:install --platform=android --source=…` first.',
            ),
            new ToolchainCheck(
                'gradlew',
                is_file($wrapper) ? $wrapper : null,
                'The Gradle wrapper is part of the copied Android project; a missing one means the copy is incomplete.',
            ),
            new ToolchainCheck(
                'Android SDK',
                $sdk,
                'Install the SDK (Android Studio, or the command-line tools) and export ANDROID_HOME=/path/to/sdk.',
            ),
            new ToolchainCheck(
                'adb',
                $this->find('adb', $adbDirs),
                'Install the SDK\'s platform-tools package: `sdkmanager platform-tools`. It does not need to be on PATH if ANDROID_HOME is set.',
            ),
            new ToolchainCheck(
                'JDK',
                $java,
                'Gradle needs a JDK 17 or newer. Install one and export JAVA_HOME, or put `java` on PATH.',
            ),
        ];
    }

    /** @return list<ToolchainCheck> */
    private function iosChecks(string $projectDir): array
    {
        $project = MobilePlatform::Ios->projectPath($projectDir);

        return [
            // First, and phrased as a fact rather than a remedy: on Linux or Windows there
            // is nothing to install. Apple ships no cross-platform iOS toolchain.
            new ToolchainCheck(
                'macOS host',
                'Darwin' === $this->osFamily ? $this->osFamily : null,
                sprintf('iOS apps can only be built on macOS; this is %s. Nothing to install — use a Mac or a macOS CI runner.', $this->osFamily),
            ),
            new ToolchainCheck(
                'iOS project',
                is_dir($project) ? $project : null,
                'Run `bin/console native:mobile:install --platform=ios --source=…` first.',
            ),
            new ToolchainCheck(
                'xcodebuild',
                $this->find('xcodebuild'),
                'Install Xcode from the App Store, then `sudo xcode-select --switch /Applications/Xcode.app`. The Command Line Tools alone are not enough to build an app.',
            ),
            new ToolchainCheck(
                'xcrun',
                $this->find('xcrun'),
                'Ships with the Xcode Command Line Tools: `xcode-select --install`.',
            ),
        ];
    }
}
