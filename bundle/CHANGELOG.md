# Changelog

All notable changes to `native-symfony/desktop-bundle`.

The format is [Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and this
project follows [Semantic Versioning](https://semver.org/spec/v2.0.0.html) — with
the caveat every `0.x` carries: the minor number is where breaking changes live
until `1.0.0`.

## [Unreleased]

## [0.1.0] - 2026-09-05

First public release. The desktop half of the port is complete and has been
verified by a packaged application that was built *and run*, not only by tests.

### Added

- **All 118 runtime endpoints**, wrapped by area: `WindowManager` (22),
  `AppManager`, `SystemManager` (11), `MenuManager`, `MenuBarManager`,
  `DialogManager`, `NotificationManager`, `ClipboardManager`, `SettingsManager`,
  `ScreenManager`, `ShellManager`, `PowerMonitorManager`, `DockManager`,
  `GlobalShortcutManager`, `ChildProcessManager`, `ProgressBar`, `RuntimeInfo`,
  `DebugLogger` and `UpdaterManager`. `ClientInterface` is the escape hatch for
  anything a wrapper does not cover.
- **All 46 pushed events**, as typed classes dispatched through Symfony's own
  dispatcher. Unknown names still dispatch, as a `NativeEvent` — apps name their
  own events for shortcuts, menu items and notifications, so that path is
  load-bearing rather than a fallback.
- **`AppBootstrapper`**, autoconfigured and aliased by a compiler pass: implement
  it and whatever it does on `/booted` is your application's startup.
- **A testing kit** — `FakeRuntime`, `RuntimeExpectations` and
  `RuntimeEventSimulator` — that records against the real wire rather than
  stubbing a manager, so a test cannot assert a payload the runtime never sends.
- **Commands**: `native:install`, `native:manifest`, `native:doctor`,
  `native:run`, `native:build`, `native:config`, `native:php-ini` and
  `native:schedule-tick`.
- **`RuntimePatcher`**, which retargets the runtime's hardcoded Laravel strings at
  your application in the copy `native:install --publish` writes, and carries five
  runtime bug fixes with it. Non-strict hunks: a target that has moved — usually
  because the fix landed upstream — is reported, not fatal.
- **`RuntimeRoutesAccessMap`**, which keeps an application's own `access_control`
  off `/_native/api/` while the shared-secret gate is enforcing. Without it,
  `access_control: ^/` makes `POST /_native/api/booted` a 401, the runtime
  discards it, and the app opens a window that does nothing forever.
- **`native:doctor`**, which asks the router directly whether the two runtime
  endpoints resolve to this bundle's controllers and exits non-zero when they do
  not. This runtime's failure mode is silence, so the check is not ceremony.

### Notes

- The bundle deliberately does **not** vendor the Electron runtime. Requiring
  `nativephp/desktop` would pull `illuminate/contracts` and `laravel/prompts` into
  a Symfony application; `native:install --source=` copies it instead.
- `native:config` and `native:php-ini` must print nothing but JSON. The runtime
  runs them before its API server exists and `JSON.parse`s their stdout, so
  anything an application echoes during console boot breaks the parse silently.

[Unreleased]: https://github.com/sadiqk2/nativephp-symfony/compare/v0.1.0...HEAD
[0.1.0]: https://github.com/sadiqk2/nativephp-symfony/releases/tag/v0.1.0
