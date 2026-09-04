# Releasing

Two packages are published from this one repository:

| Package | Mirror repository |
|---|---|
| `native-symfony/desktop-bundle` | `sadiqk2/nativephp-symfony-desktop-bundle` |
| `native-symfony/mobile-bundle` | `sadiqk2/nativephp-symfony-mobile-bundle` |

## Why there are mirrors at all

**Packagist reads a `composer.json` at a repository root.** It has no notion of a
package living in a subdirectory, and no plan to acquire one — so a monorepo
holding two packages cannot be submitted as it stands. The answer every PHP
monorepo reaches, Symfony and Laravel included, is a *subtree split*: each
package directory is rewritten into its own repository, history and all, and
those repositories are what Packagist watches.

`.github/workflows/split.yml` does it on every push to `main` and every `v*` tag.
The mirrors are **read-only**: nothing is ever pushed to them by hand, and each
carries an issue-template redirect pointing contributors back here.

This means development is unaffected. `bundle/` and `mobile-bundle/` stay where
they are, one clone holds both, and a change touching both is still one commit
and one pull request.

## One-time setup

Everything below needs a GitHub account with rights over the `sadiqk2`
namespace; none of it can be done from a pull request.

### 1. Create the two mirror repositories

Empty — no README, no licence, no `.gitignore`. The first split push writes the
whole history, and an initial commit would collide with it.

- `sadiqk2/nativephp-symfony-desktop-bundle`
- `sadiqk2/nativephp-symfony-mobile-bundle`

Give each a description and a link back to this repository, since these are what
people will land on from Packagist.

### 2. Create the push token

The workflow's default `GITHUB_TOKEN` is scoped to *this* repository and cannot
push to another one. Create a **fine-grained personal access token**:

- Resource owner: `sadiqk2`
- Repository access: **only** the two mirrors
- Permissions: **Contents: read and write**, nothing else
- Expiry: set one, and put the renewal in a calendar — an expired token fails the
  split job, which is loud, but only on the next push

Add it to this repository as **Settings → Secrets and variables → Actions → New
repository secret**, named `SPLIT_TOKEN`.

### 3. Prime the mirrors

Push to `main`, or run the split workflow manually from **Actions → split → Run
workflow**. Both mirrors should end up with the full rewritten history of their
directory. Check that each mirror's root contains `composer.json`, `README.md`,
`LICENSE` and `src/` — that is what Packagist will read.

### 4. Submit to Packagist

At [packagist.org/packages/submit](https://packagist.org/packages/submit), submit
each **mirror** URL — never this repository:

```
https://github.com/sadiqk2/nativephp-symfony-desktop-bundle
https://github.com/sadiqk2/nativephp-symfony-mobile-bundle
```

Packagist reads the package name out of each `composer.json`, so they register as
`native-symfony/desktop-bundle` and `native-symfony/mobile-bundle`.

Then enable the **GitHub service hook** on each mirror so Packagist updates on
push rather than on its crawl schedule: Packagist shows the exact steps on the
package page after submission. Without it a new tag can take hours to appear.

## Cutting a release

1. **Move the `## [Unreleased]` entries** in `bundle/CHANGELOG.md` and
   `mobile-bundle/CHANGELOG.md` under a new version heading, with the date. Fix
   the two link definitions at the bottom of each file.

2. **Check the suites are green on the real upstream sources**, which is not the
   same as green locally on a stale clone:

   ```bash
   rm -rf upstream
   git clone --depth 1 https://github.com/NativePHP/desktop    upstream/np-desktop
   git clone --depth 1 https://github.com/NativePHP/mobile-air upstream/np-mobile
   tools/test.sh
   ```

   The contract tests parse NativePHP's own express routers, so this is where a
   new upstream endpoint shows up. A failure here is not a release blocker by
   itself — it is a decision to make deliberately, and `CONTRACT.md` and the
   pinned counts move with it.

3. **Validate both manifests**, exactly as CI does:

   ```bash
   (cd bundle        && composer validate --strict)
   (cd mobile-bundle && composer validate --strict)
   ```

4. **Rebuild the documentation site** if any Markdown changed:

   ```bash
   DOCS_ROOT="$PWD" node tools/build-docs.mjs
   ```

5. **Tag and push.** One tag covers both packages — they are versioned together
   because they are released together, and a consumer requiring one has no way to
   tell that the other moved:

   ```bash
   git tag -a v0.1.0 -m 'v0.1.0'
   git push origin main
   git push origin v0.1.0
   ```

6. **Watch the split job.** Actions → split. Two jobs, one per package. Each ends
   by pushing `refs/tags/v0.1.0` to its mirror, pointing at the split commit
   rather than at this repository's commit — the mirror has never seen that one.

7. **Confirm on Packagist.** Both packages should show the new version within a
   minute or two if the service hook is enabled. Then, from a scratch directory:

   ```bash
   composer require native-symfony/desktop-bundle:^0.1
   ```

   That is the only check that proves the whole chain — tag, split, mirror, hook,
   Packagist, resolver — actually joins up.

8. **Delete the three "not yet submitted to Packagist" notes**, which exist so the install
   instructions are not a lie in the meantime:

   - `README.md`, under *0. Install the packages*
   - `docs/getting-started-desktop.md`, under *1. Install the bundle*
   - `docs/getting-started-mobile.md`, under *1. Install*

   Then rebuild the documentation site, since all three are pages on it. Steps 1 to 4 above
   are one-time; this one goes with them.

   Same edit, same reason: the README's badge row deliberately carries no Packagist badges
   yet, because a badge for a package that does not exist renders as a broken image. Add
   them once the packages resolve:

   ```markdown
   [![desktop-bundle](https://img.shields.io/packagist/v/native-symfony/desktop-bundle)](https://packagist.org/packages/native-symfony/desktop-bundle)
   [![mobile-bundle](https://img.shields.io/packagist/v/native-symfony/mobile-bundle)](https://packagist.org/packages/native-symfony/mobile-bundle)
   ```

## Versioning

Both packages follow [SemVer](https://semver.org/), with the caveat every `0.x`
carries: **the minor number is where breaking changes live** until `1.0.0`.
`^0.1` therefore pins to `0.1.*`, which is the behaviour to want here rather than
a limitation to apologise for.

There is deliberately **no `version` field** in either `composer.json`. Composer
derives the version from the git tag; a hardcoded one goes stale the moment it
disagrees with a tag, and `composer validate` warns about it for that reason.

`extra.branch-alias` maps `dev-main` to `0.1.x-dev`, so someone tracking the
development branch resolves against the same constraint as a released `^0.1`.

## What `1.0.0` would need

Not a decision to take by tagging. The two things standing between here and a
stable promise:

- **Device verification for mobile.** No build produced by
  `native:mobile:build` has been opened by Xcode or Android Studio, and no screen
  has been rendered on a phone. Everything up to that boundary is tested;
  `--stage-only` stops exactly there. A `1.0.0` covering a package in that state
  would be a promise nobody has checked.
- **The manifest patch upstream.** `upstream-patches/0001` is what would make the
  runtime read its paths from `nativephp.json` instead of assuming Laravel, and
  retire `RuntimePatcher` entirely. Until it lands, every application patches its
  own copy of the runtime — which works, and is tested, but is not the shape this
  should settle into.

The vendor name is the other open question: `native-symfony` is free on Packagist
and does not claim NativePHP's brand, but the courtesy conversation with upstream
comes before `1.0.0`, not after.
