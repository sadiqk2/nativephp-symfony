# Releasing

Two packages are published from this one repository:

| Directory | Package, and the mirror repository it is published from |
|---|---|
| `bundle/` | `sadiqk2/nativephp-symfony-desktop-bundle` |
| `mobile-bundle/` | `sadiqk2/nativephp-symfony-mobile-bundle` |

The package name and the mirror repository name are the same on purpose: whoever
lands on one from Packagist should not have to work out which repository it is.

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

**Until the setup below is done, the workflow splits and skips the push**, with a
note on the run summary saying so, so an unconfigured repository keeps a green
default branch. A `v*` tag or a manual run fails outright instead — a release
that silently never reaches Packagist is worse than a red tick. An *expired*
token stays loud either way: the secret is still set, so the push gets a 403 and
the job fails.

This means development is unaffected. `bundle/` and `mobile-bundle/` stay where
they are, one clone holds both, and a change touching both is still one commit
and one pull request.

## One-time setup

**Done on 2026-09-08, with two parts still outstanding.** This repository is public,
both mirrors exist and carry the full split history, and `v0.1.0` is tagged on all
three. What follows is the record of what was configured — read it if a mirror has to
be rebuilt, if the token expires, or if the same shape is wanted for a third package.

**`v0.1.0` cannot be published, under any name.** Packagist reads `composer.json`
out of each *tag*, not only out of the default branch, and every `v0.1.0` tree says
`native-symfony/*` — the blocked name. A tag whose package name does not match the
registered package is discarded, silently, as one absent version rather than as an
error. So the rename needs a new tag: `v0.1.1` is the first version any of this can
serve, and `v0.1.0` stays in the history as the release that existed but never
shipped. Force-moving the old tag across three repositories was the alternative and
is worse — it rewrites a published ref to mean something else.

**The automatic push is not working yet, and step 2 is why.** `0.1.0`'s mirror
branches and tags were pushed by hand from a clone, which is safe to do because
`git subtree split` is deterministic: the same history over the same prefix produces
the same commit sha every time, so a local split and the workflow's split cannot
disagree. It was verified — the shas the workflow computed, `f36b592` and `c8b66a6`,
are the shas that were pushed. But it is a manual step, and the next release should
not need it. Until a valid token is in place the `split` job will **fail on every
push**, because the secret currently set is not a token at all (see step 2), so the
guard reads it as configured and then cannot authenticate.

By hand, for the record, from a clone of this repository:

```bash
git push https://github.com/sadiqk2/nativephp-symfony-desktop-bundle.git \
  "$(git subtree split --prefix=bundle HEAD)":refs/heads/main
```

and the same for `mobile-bundle`. For a tag, push that split sha to
`refs/tags/vX.Y.Z` on the mirror rather than pushing this repository's tag, which
points at a commit the mirror has never seen.

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

**The `SPLIT_TOKEN` currently set is not a token — delete it and follow the above.**
It was meant to be the `gh` CLI's own OAuth token, to save a browser detour. But the
`gh` on the machine it was taken from is 2.4.0, which has no `gh auth token`
subcommand: the command exited 1 and wrote its error text to stdout, and
`gh secret set`, which validates nothing, stored *that* as the secret. Three
consecutive `split` failures came out of it, each with a different and misleading
message — `credential url cannot be parsed` (the error text contained newlines),
then `Permission ... denied to github-actions[bot]` (a real second bug, fixed by
`persist-credentials: false`), then `Invalid username or token`. Two lessons worth
keeping:

- **A secret store accepts anything.** `gh secret set` will happily store a usage
  message. If a credential is piped in, check the producing command's exit status
  first — `set -o pipefail` would have caught this at the source.
- **A green `split` tick never meant publishing worked.** For three days the job
  skipped the push for want of a token, and a skipped step cannot fail. The first run
  that ever attempted a push was the first run that told the truth. This is the same
  asymmetry the workflow's own comments warn about for tags, one level up.

A fine-grained token as described above is also *less* privilege than the OAuth one
would have been, which carries `repo` across every repository this account owns.

### 3. Prime the mirrors

Run the split workflow from **Actions → split → Run workflow**, or push to
`main`. Both mirrors should end up with the full rewritten history of their
directory. Check that each mirror's root contains `composer.json`, `README.md`,
`LICENSE` and `src/` — that is what Packagist will read.

If the run reports *"Not mirrored"* on its summary, step 2 has not taken effect:
the secret is missing or misnamed. A manual run is the better check of the two
for exactly this reason — it fails on a missing token rather than skipping.

### 4. Submit to Packagist

At [packagist.org/packages/submit](https://packagist.org/packages/submit), submit
each **mirror** URL — never this repository:

```
https://github.com/sadiqk2/nativephp-symfony-desktop-bundle
https://github.com/sadiqk2/nativephp-symfony-mobile-bundle
```

Packagist reads the package name out of each `composer.json`, so they register as
`sadiqk2/nativephp-symfony-desktop-bundle` and `sadiqk2/nativephp-symfony-mobile-bundle`.

**Submitting is also where a vendor name is accepted or refused**, which is how the
first attempt ended:

> The vendor name native-symfony is blocked, if you think this is a mistake please
> get in touch with us.

Packagist keeps a blocklist of vendor names carrying a framework's trademark. The
vendor half of a package name is the part that says *who publishes this*, so
`native-symfony/*` reads as Symfony publishing it — which is exactly what a
blocklist exists to prevent, and no amount of good faith in the package changes how
the name reads to someone typing `composer require`. There was nothing to appeal.
An account namespace, `sadiqk2/*`, is the one thing no policy can withdraw.

Then enable the **GitHub service hook** on each mirror so Packagist updates on
push rather than on its crawl schedule: Packagist shows the exact steps on the
package page after submission. Without it a new tag can take hours to appear.

## Cutting a release

1. **Settle the changelogs.** In `bundle/CHANGELOG.md` and
   `mobile-bundle/CHANGELOG.md`, move whatever is under `## [Unreleased]` beneath
   a new version heading with today's date, and fix the two link definitions at
   the bottom of each file.

   **Write the date on the day you tag, not the day you draft.** A release dated
   before the tag that carries it is the one thing here nobody will notice and
   everybody can see. `0.1.0` was drafted 2026-09-05 and dated 2026-09-08 for
   exactly that reason — the release was gated on the setup above, which took days.

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
   composer require sadiqk2/nativephp-symfony-desktop-bundle:^0.1
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
   [![desktop-bundle](https://img.shields.io/packagist/v/sadiqk2/nativephp-symfony-desktop-bundle)](https://packagist.org/packages/sadiqk2/nativephp-symfony-desktop-bundle)
   [![mobile-bundle](https://img.shields.io/packagist/v/sadiqk2/nativephp-symfony-mobile-bundle)](https://packagist.org/packages/sadiqk2/nativephp-symfony-mobile-bundle)
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

**The cost lands on the path-repository route**, and it is worth knowing before
someone hits it. A `path` repository has no tag to read, so Composer infers
`dev-main` and the alias makes that `0.1.x-dev` — a *dev* stability. Under the
default `minimum-stability: stable` a plain `^0.1` then answers *"found
…[dev-main, 0.1.x-dev (alias of dev-main)] but it does not match your
minimum-stability"*. Requiring `^0.1@dev` flags that one constraint and resolves.
Both getting-started pages and the README say so, because until the packages are
on Packagist that route is the only one there is.

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

The vendor name is settled, the hard way. `native-symfony` was the name up to
0.1.0, and Packagist refused it: **the vendor name native-symfony is blocked.**
Packagist blocks vendor names carrying a framework's trademark, because the vendor
half of a package name is what says who publishes it, and `native-symfony/*` reads
as Symfony publishing it. Nothing about the code had to change — a Composer vendor
and a PHP namespace are separate things, and `Native\Symfony\...` stays as it is,
since Packagist has no say over namespaces. Only the two `composer.json` names
moved, to `sadiqk2/*`, an account namespace no policy can take away.

The courtesy conversation with upstream is still owed, and comes before `1.0.0`.
