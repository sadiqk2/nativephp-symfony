# Changelog

All notable changes to `native-symfony/mobile-bundle`.

The format is [Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and this
project follows [Semantic Versioning](https://semver.org/spec/v2.0.0.html) — with
the caveat every `0.x` carries: the minor number is where breaking changes live
until `1.0.0`.

## [Unreleased]

## [0.1.0] - 2026-09-08

First public release. Read the caveat under **Not verified** before shipping
anything with it.

### Added

- **All 54 bridge methods**, wrapped across 16 API groups: `Camera`, `Scanner`,
  `Geolocation`, `Biometric`, `SecureStorage`, `PushNotifications`, `Device`,
  `System`, `Network`, `Files`, `Dialog`, `Share`, `Browser`, `Microphone`,
  `Performance` and `MobileWallet`.
- **`FakeBridge`**, and it is what makes development possible at all.
  `nativephp_call()` is a compiled extension that exists only inside a packaged
  app, so off a device every call returns `null` and a working application is
  indistinguishable from a broken one. The fake records instead.
- **The SAPI shim and persistent runtime** — `MobileRuntime`,
  `ServerRequestFactory`, `ResponseEmitter` and the four bootstrap scripts the
  iOS and Android hosts load.
- **The full native-UI path**: element trees, the Tailwind-subset style parser,
  `#[NativeScreen]` routing, and a component lifecycle with callbacks. Byte-
  verified against upstream's own renderers wherever a comparison exists.
- **A Twig authoring layer** for element trees, so a native screen is written the
  way the rest of the application is.
- **Commands**: `native:mobile:install`, `native:mobile:manifest`,
  `native:mobile:doctor`, `native:mobile:run` and `native:mobile:build`, the last
  with `--dry-run`, `--stage-only`, `--aab` and iOS export options.

### Not verified

**No build produced by these commands has been opened by Xcode or Android
Studio, and no screen has been rendered on a phone.** There is no Xcode or
Android SDK in the environment this was built in. Everything up to that boundary
is tested and `--stage-only` stops exactly there. Device verification is the
single most valuable contribution anyone with a Mac or an Android SDK can make.

### Notes

- The WebView path is what most applications want, and it is reached by
  construction: an application that declares no `#[NativeScreen]` produces no
  native-route manifest, so both platforms' `BootPlanner` takes the WebView.
  Controllers and Twig templates are unchanged; the new thing is the device API.
- `native:mobile:install` prints a licensing caution deliberately. NativePHP
  Mobile is sold as a product, though the `mobile-air` repository itself is MIT.
  Read its terms rather than either summary.

[Unreleased]: https://github.com/sadiqk2/nativephp-symfony/compare/v0.1.0...HEAD
[0.1.0]: https://github.com/sadiqk2/nativephp-symfony/releases/tag/v0.1.0
