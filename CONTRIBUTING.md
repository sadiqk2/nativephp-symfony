# Contributing

Two packages live here, and they share no code:

| | |
|---|---|
| [`bundle/`](bundle/) | `sadiqk2/nativephp-symfony-desktop-bundle` |
| [`mobile-bundle/`](mobile-bundle/) | `sadiqk2/nativephp-symfony-mobile-bundle` |

They are published from this repository by an automatic subtree split — the two
mirror repositories on GitHub are **read-only**. Every change, issue and pull
request belongs here. See [RELEASING.md](RELEASING.md) for how the
mirrors are produced.

## The thing to know before anything else

This runtime's failure mode is **silence**, not an error. A missing route, a
firewall rule, a stray `echo` during console boot — each of them produces an
application that starts, opens a window, and then does nothing forever, with the
explanation somewhere nobody would look. Almost every design decision in this
repository is downstream of that, which is why:

- `native:doctor` asks the router directly and exits non-zero.
- The contract tests parse NativePHP's *own* TypeScript rather than a snapshot.
- Counts are pinned exactly rather than as floors.

If a change makes a failure quieter, it is going the wrong way.

## Running the suites

You need PHP 8.3+ with `ext-zip`, and Composer.

```bash
git clone --depth 1 https://github.com/NativePHP/desktop    upstream/np-desktop
git clone --depth 1 https://github.com/NativePHP/mobile-air upstream/np-mobile

(cd bundle        && composer install)
(cd mobile-bundle && composer install)

tools/test.sh                      # both suites
tools/test.sh mobile-bundle        # one
EXTRA_ARGS="--filter Contract" tools/test.sh bundle
```

**The upstream clones are not optional.** The contract tests read the real
runtime sources; without them their data providers yield nothing and the desktop
suite silently shrinks by around 115 tests while still reporting OK. CI clones
them for the same reason. `tools/test.sh` falls back to Docker when the local PHP
has no `ext-zip`.

Run them unprivileged. A handful of tests assert that an unwritable file is an
error, and root ignores file permissions, so as root they skip instead of
running — which is why CI passes `--fail-on-skipped` and why the one skip you
will see locally as root is expected.

## What a change needs

**A test that fails before it.** Not as ceremony — several of the bugs recorded
in the milestone write-ups were found by writing the test first and watching it
pass, which meant it was testing the code rather than the fix.

**The pinned counts updated deliberately.** `ContractCoverageTest`,
`EventPayloadShapeTest` and `PayloadKeyContractTest` all assert exact counts, and
they are exact on purpose: a parser that stops recognising something has to land
somewhere visible. When one of them fails, read the new upstream code and decide
— do not bump the number to make it green. The commit that bumps it should say
what moved and why.

**`CONTRACT.md` updated when the wire changes.** It is the specification the
tests check the implementation against; a new endpoint that is not in it is a new
endpoint nobody can review.

## Documentation

Every Markdown file in this repository is also a page of the static site in
`docs/`. Change a document, rebuild:

```bash
npm install marked highlight.js
DOCS_ROOT="$PWD" node tools/build-docs.mjs
php -S 127.0.0.1:8000 -t docs
```

[`tools/README.md`](tools/README.md) has the link checker worth running after a
build, and the design decisions behind the site.

Where the documents disagree with each other, [`docs/README.md`](docs/README.md)
sorts them into current reference, historical record, and
for-people-working-on-the-port. Do not update the historical ones: `SPIKE-`,
`M2-` and `M3-RESULTS.md` are a journal, accurate about what was true when they
were written, and their value is the reasoning rather than the API.

## Upstream patches

[`upstream-patches/`](upstream-patches/README.md) holds fixes against
`NativePHP/desktop` and `NativePHP/mobile-air`. If you find a runtime bug:

1. Fix it upstream, as a patch file here with a regression test in *that* repo's
   idiom — Pest for `mobile-air`.
2. Check the test against unpatched `main` first. A regression test that passes
   before the fix is testing the code, not the fix.
3. Carry it in `RuntimePatcher` as a **non-strict** hunk if Symfony applications
   need it now. A target that has moved — usually because the fix landed —
   should be reported, never fatal.

## The most valuable contribution

Device verification for mobile. `native:mobile:build` stages, cleans and archives
an application and then hands off to Gradle or Xcode, and neither toolchain
exists in the environment this was built in. **No build produced by these
commands has been opened by one, and no screen has been rendered on a phone.**
Everything up to that boundary is tested; `--stage-only` stops exactly there.

If you have a Mac or an Android SDK, running `native:mobile:build` on the demo
and reporting what happens is worth more than any amount of additional test
coverage.

## Code style

Match what is around you. `declare(strict_types=1)` everywhere, `final` by
default, constructor property promotion, readonly where it holds.

Comments explain *why*, and specifically why something non-obvious is the way it
is — a runtime quirk, an upstream bug, a decision that looks wrong until you know
what it prevents. There are a lot of them in this codebase because there are a
lot of those. Do not add comments that restate the code.
