# Security policy

## Supported versions

| Version | Supported |
|---|---|
| `0.1.x` | ✅ |

This is a `0.x` project. Fixes land on the latest minor; there is no backport
branch yet.

## Reporting a vulnerability

**Do not open a public issue.** Use GitHub's private reporting —
[Security → Report a vulnerability](https://github.com/sadiqk2/nativephp-symfony/security/advisories/new)
on this repository.

Please include the bundle and version, what an attacker gains, and the smallest
reproduction you have. You will get an acknowledgement within a few days, and an
assessment with a fix or an explanation of why it is not one.

If the vulnerability is in NativePHP's own Electron runtime, the mobile hosts, or
Electron itself rather than in these bundles, report it to
[NativePHP](https://github.com/NativePHP/desktop/security) as well — this project
patches a copy of that runtime but does not maintain it.

## The trust boundary, so a report can be aimed correctly

**Desktop.** PHP and the runtime talk over **localhost HTTP** with a shared
secret in an `X-NativePHP-Secret` header. That secret is generated per run and
passed to the PHP process in its environment. Anything else on the machine that
can reach `127.0.0.1` and learn the secret can drive the whole runtime API — so
the secret is the boundary, and a leak of it is a serious report.

Two consequences worth knowing before filing:

- **`EventFactory` does not instantiate arbitrary classes.** Upstream's Laravel
  controller does `class_exists($event) ? new $event(...$payload)` on the request
  body, which turns a leaked secret into arbitrary object construction with
  attacker-chosen arguments. This port constructs only its known event map and
  explicitly allowlisted namespaces; everything else becomes an inert
  `NativeEvent`. Same behaviour for legitimate traffic, no gadget chain.
- **`WindowManager::get()` encodes the window id into the path**, because that id
  can arrive from outside the application — `detectId()` reads `_windowId` out of
  the `Referer`. Without encoding, `../app/quit` would address a different
  endpoint.

**Mobile.** There is no port and no secret: the mobile path is one process with
PHP compiled into it, and `nativephp_call()` is a direct call into the host. The
attack surface is whatever the application itself exposes.

**The runtime endpoints are unauthenticated by design, and gated by the secret.**
`RuntimeRoutesAccessMap` deliberately keeps an application's own `access_control`
off `/_native/api/` while that gate is enforcing — a firewall in front of those
two routes breaks the runtime's own callbacks rather than protecting anything.
That is a considered trade, not an oversight; if you think the gate is
insufficient, that is a report worth making, and please say what you can do with
it rather than that it exists.

## Out of scope

- **Anything requiring an attacker who already has code execution as the user.**
  A desktop application runs as its user; a local attacker with that privilege
  does not need this software.
- **The `demo/` application.** It is an integration test and a worked example,
  not a hardened application, and it is not published as a package.
- **`spike/`**, which is a reproduction harness that runs Electron under Xvfb
  with `ELECTRON_DISABLE_SANDBOX=1`, deliberately — there are no user namespaces
  in the container it was built for.
