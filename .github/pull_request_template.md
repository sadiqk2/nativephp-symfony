<!--
Both packages are published from this repository by subtree split; the
native-symfony/* repositories are read-only mirrors. Pull requests belong here.
-->

## What this changes

<!-- One paragraph. What behaviour is different afterwards, and why. -->

## Why

<!--
If this fixes a defect, what did it do before? If the symptom was silence — an
app that opens a window and does nothing — say where the silence started.
-->

## How it was verified

<!--
A test that fails before the change is the strongest form. Say which one, and
what it reports on unpatched code.

If the change is one a test cannot reach — a build path, a device, a packaged
app — say what you ran instead and what you saw.
-->

- [ ] `tools/test.sh` passes with the upstream clones present (see CONTRIBUTING.md — without them the desktop suite silently loses ~115 tests)
- [ ] Ran unprivileged, so the permission tests ran rather than skipped

## Checklist

- [ ] `CONTRACT.md` updated, if the wire protocol changed
- [ ] The pinned counts in `ContractCoverageTest` / `EventPayloadShapeTest` / `PayloadKeyContractTest` were changed **deliberately**, and this PR says what moved upstream and why — not bumped to make the suite green
- [ ] The relevant `CHANGELOG.md` has an entry under `## [Unreleased]`
- [ ] Documentation updated, and `DOCS_ROOT="$PWD" node tools/build-docs.mjs` re-run if any `.md` changed
- [ ] No new way for a failure to be silent
