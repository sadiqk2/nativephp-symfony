# Documentation

Start here. This directory is the documentation for **using** NativePHP for Symfony; the
Markdown files at the repository root are mostly analysis, specification, or a record of how
the port was built.

## Using it

| | |
|---|---|
| **[Getting started — desktop](getting-started-desktop.md)** | `composer require` to a window on screen, including the two things that are not automatic |
| **[Getting started — mobile](getting-started-mobile.md)** | Both render paths, and what "verified by tests, not by a device" means |
| **[Desktop API reference](desktop-api.md)** | Windows, menus, dialogs, notifications, clipboard, settings, child processes, Messenger, events |
| **[Mobile API reference](mobile-api.md)** | The 16 bridge groups, and the native-UI element/component/style layers |
| **[Recipes](recipes.md)** | Multi-window apps, a Messenger worker, typed listeners, a stateful native screen, packaging |
| **[Testing](testing.md)** | `FakeRuntime`, `RuntimeExpectations`, `RuntimeEventSimulator`, `FakeBridge` |
| **[Troubleshooting](troubleshooting.md)** | Symptom → cause → fix. Read this first when something is *silently* wrong, which is this runtime's usual failure mode |

## A worked example

[`../demo/`](../demo/) is a Symfony 8 application using both bundles: a bootstrapper, a
multi-window inspector, a notes CRUD over the settings store, a Messenger-style job page, a
menu with routed events, a native-UI counter screen, and a `native_publish()` Twig function the
app owns rather than the bundle. Read it after the getting-started page for this project's
idea of idiomatic use. It is newer than these docs; where it disagrees, it is probably right.

## Reference — the protocol, not the guide

Current and authoritative. Consult these when a wrapper does not cover what you need, or when
you are checking a payload shape.

| | |
|---|---|
| [`../CONTRACT.md`](../CONTRACT.md) | The desktop wire protocol: all 116 endpoints with request and response shapes, all 44 events, the environment contract, and the seven things the runtime requires of any PHP app |
| [`../NATIVE-UI-CONTRACT.md`](../NATIVE-UI-CONTRACT.md) | The mobile native-UI wire format: node shapes, content hashes, id derivation, the callback protocol, the style vocabulary |
| [`../bundle/README.md`](../bundle/README.md) | Desktop package overview |
| [`../mobile-bundle/README.md`](../mobile-bundle/README.md) | Mobile package overview |

The two package READMEs are summaries and lag the code slightly — see
[known drift](#known-drift-in-older-documents) below. Where they disagree with these docs,
these docs were written against the source.

## Understanding it

| | |
|---|---|
| [`../ARCHITECTURE.md`](../ARCHITECTURE.md) | How desktop and mobile differ, what the two bundles share and deliberately do not, the three verification techniques, and a collected list of the traps. The best single document if you read only one |

## Working *on* the port

Not needed to build an application with it.

| | |
|---|---|
| [`../PLAN.md`](../PLAN.md) | What NativePHP is, where Laravel leaks, and the milestone roadmap |
| [`../ANALYSIS.md`](../ANALYSIS.md) | The deep dive: boot sequence, per-directory port map with LOC, the eight Laravel-isms with line numbers, the Symfony-specific design decisions, upstream bugs found |
| [`../MOBILE-ANALYSIS.md`](../MOBILE-ANALYSIS.md) | Why mobile splits in two, and a correction to an earlier conclusion that was wrong |
| [`../upstream-patches/README.md`](../upstream-patches/README.md) | Seven patches against `NativePHP/desktop`, ready to become PRs. **Not submitted.** Patch `0001` is the manifest change that would make the runtime framework-agnostic and retire the patcher |
| [`../spike/README.md`](../spike/README.md) | The reproduction harness — a container with PHP, Node, Electron and Xvfb that boots the runtime headlessly and screenshots it |

## Historical record

These are milestone write-ups. They are accurate about what was true when they were written,
and they are where the *evidence* for the current behaviour lives — but they are not the place
to learn how to use anything.

| | |
|---|---|
| [`../SPIKE-RESULTS.md`](../SPIKE-RESULTS.md) | M1: proving a Symfony app can run under the runtime at all. Five findings, including `APP_ENV=local` and the resize that emits no event |
| [`../M2-RESULTS.md`](../M2-RESULTS.md) | M2: the bundle. Seven findings, including `sendStatus` sending `"OK"`, the `NaN` zoom factor, and the dot-path settings key |
| [`../M3-RESULTS.md`](../M3-RESULTS.md) | M3: the build pipeline, and a packaged app that was built *and run*. Four findings, including the `APP_SECRET` collision and no opcache |

Every "worth knowing" in the pages above traces back to one of these three.

## Known drift in older documents

Everything the first pass of these docs found wrong has since been fixed in code, not
papered over — the list is kept because *how* each was found is the useful part.

| Was wrong | Now |
|---|---|
| `native_publish()` was documented and never registered | Registered; returns void, so `{% do native_publish(...) %}` is the spelling |
| Parsed styles never reached the wire — the documented `->layout($parser->parse(...))` put camelCase keys where the renderers read snake_case | `StyleApplier` dispatches them, or use the `class` option |
| Element props were camelCase (`fontSize`), `text_align` was a string, `font_size` an int | All match the wire; a test now compares an element *with* props, which is how these surfaced |
| `NativeScreenResponder` typed its renderer non-nullable while DI wired it `nullOnInvalid` — a `TypeError` for any app enabling the bundle | Nullable, with a guard naming the interface to implement |
| The install flow predated `native:manifest` | Documented, and patching is skipped when the runtime is manifest-aware |
| Test counts contradicted each other across three READMEs | Read from the suites |
| Nothing implemented `ScreenRendererInterface`, so a `#[NativeScreen]` component needed application glue — the two halves of the native-UI path did not join up | `ComponentScreenRenderer` ships, aliased to the interface; override the alias for a different renderer |
| The routes import was a manual step, and forgetting it gave an app that boots and shows nothing | `native:install` writes it, and warns rather than guessing when there is no `config/routes/` |
| The demo itself did `layout: $parser->parse(...)` on two pages — the very mistake row three above records | Both go through `StyleApplier` now; found by reading a published frame, not by a test |

Two caveats that are *not* drift and will not be fixed:

- **Mobile is verified by tests, not on a device.** There is no Xcode or Android SDK in
  the environment this was built in. Desktop's claims are backed by a running packaged
  app; mobile's are not, and no amount of test coverage changes that.
- **`SPIKE-RESULTS.md`, `M2-RESULTS.md` and `M3-RESULTS.md` are a journal.** They record
  what was true at a milestone, including conclusions later corrected. Read them for the
  reasoning, not the API.
