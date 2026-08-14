# NativePHP for Symfony

Bringing [NativePHP](https://nativephp.com)'s desktop runtime to Symfony: build desktop
applications with Symfony and PHP, on the same Electron runtime the Laravel version uses.

Working, and proven end-to-end — a Symfony 8 app in a native window, driving the runtime's
full API, packaged into a distributable app that has been built *and run*.

![The packaged app](spike/shot-packaged.png)

## Where things are

| | |
|---|---|
| **[`bundle/`](bundle/README.md)** | `native-symfony/desktop-bundle` — the adapter. All 116 runtime endpoints, all 44 events, 235 tests. |
| [`spike/`](spike/README.md) | The reproduction harness: a container with PHP 8.4 + Node 22 + Electron, the runtime patch, and headless runners that screenshot the result. |
| `upstream/` | Shallow reference clones of `NativePHP/desktop` and `NativePHP/mobile-air` (gitignored; clone on demand). |

## The documents, in reading order

1. **[PLAN.md](PLAN.md)** — what NativePHP actually is, where Laravel leaks, and the
   roadmap. Start here.
2. **[ANALYSIS.md](ANALYSIS.md)** — the deep dive: the exact boot sequence, a per-directory
   port map with LOC, the eight Laravel-isms in the runtime's TypeScript with line numbers,
   the Symfony-specific design decisions, and the upstream bugs found along the way.
3. **[CONTRACT.md](CONTRACT.md)** — the wire protocol. All 116 endpoints with request and
   response shapes, all 44 events with payload shapes, the environment contract, and the
   seven things the runtime requires of any PHP app.
4. **[SPIKE-RESULTS.md](SPIKE-RESULTS.md)** — M1: proving it possible at all.
5. **[M2-RESULTS.md](M2-RESULTS.md)** — the bundle.
6. **[M3-RESULTS.md](M3-RESULTS.md)** — the build pipeline.

## The short version

The Laravel coupling in NativePHP's desktop runtime turned out to be **eight hardcoded
string literals in one TypeScript file**, not an architecture. The PHP↔runtime boundary is
already framework-neutral: localhost HTTP and JSON, with a shared-secret header.

So the adapter needs no fork. `native:install --publish` upstream already mirrors the whole
Electron project into the application's own directory, and the runtime prefers that copy —
so each app patches its own, idempotently, and the proper fix upstream is a small
behaviour-preserving manifest PR.

Mobile is a different story and deliberately out of scope: its UI is a native element tree
driven from Blade, and that rendering engine is 17,316 LOC — 32% of the mobile codebase —
with 32 of its 92 files importing Blade directly. Desktop needed ~6,000 LOC and **zero** UI
work, because a Symfony app in a WebView is already a Symfony app.

## Status

M0 (contract), M1 (spike), M2 (bundle) and M3 (build pipeline) are done.

Left: proposing the manifest change upstream, along with nine independent bug fixes found
while building this — each worth submitting on its own merits. Prior art is
[NativePHP discussion #504](https://github.com/NativePHP/laravel/discussions/504).

## Licence

MIT, matching `nativephp/desktop`. The `native-symfony` vendor name is provisional —
`nativephp/*` is someone else's brand, and asking comes before claiming it.
