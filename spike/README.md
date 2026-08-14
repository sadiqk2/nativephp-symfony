# M1 spike — reproduce

Proves a Symfony app can run under the unmodified-upstream NativePHP desktop runtime.
Results and findings: `../SPIKE-RESULTS.md`.

Everything runs in a container because the host has no PHP and only Node 12; the
runtime needs PHP 8.3+ and Node 22+.

## Layout

```
Dockerfile          PHP 8.4 + Node 22 + Electron's system libs + Xvfb + ImageMagick
overlay/            the Symfony-side code, copied over a fresh skeleton
patch-runtime.py    the whole runtime diff — 5 hunks, 2 files, idempotent
run.sh              boots the runtime headless, drives a resize, screenshots
app/                generated (gitignored-sized: ~600MB of node_modules)
shot*.png           captured screenshots
```

## Steps

```bash
docker build -t np-symfony-spike .

# 1. Symfony skeleton + the two components the adapter needs
docker run --rm -u $(id -u):$(id -g) -e HOME=/tmp -e COMPOSER_HOME=/tmp/composer \
  -v "$PWD":/work np-symfony-spike bash -lc '
    cd /work && rm -rf app &&
    composer create-project symfony/skeleton app --no-interaction &&
    cd app && composer require symfony/twig-bundle symfony/http-client symfony/monolog-bundle --no-interaction'

# 2. The adapter code
cp -r overlay/src/*        app/src/
cp    overlay/templates/*  app/templates/
cp    overlay/public/nativephp-router.php app/public/

# 3. The runtime, from the upstream clone, then patched
mkdir -p app/nativephp
cp -r ../upstream/np-desktop/resources/electron app/nativephp/electron
python3 patch-runtime.py app/nativephp/electron

# 4. The build dir the runtime reads: icon, CA bundle, PHP binary
#    (this replaces `node php.js`, which only unzips a binary out of nativephp/php-bin)
mkdir -p app/nativephp/build/php
cp ../upstream/np-desktop/resources/build/*.png app/nativephp/build/

# 5. npm install + rebuild the plugin from the patched sources
docker run --rm -u $(id -u):$(id -g) -e HOME=/tmp -e npm_config_cache=/tmp/npmcache \
  -v "$PWD":/work np-symfony-spike bash -lc '
    cp /usr/local/bin/php /work/app/nativephp/build/php/php
    chmod +x /work/app/nativephp/build/php/php
    cp /etc/ssl/certs/ca-certificates.crt /work/app/nativephp/build/cacert.pem
    cd /work/app/nativephp/electron && npm install --no-audit --no-fund && npm run plugin:build'

# 6. Run it
docker run --rm -u $(id -u):$(id -g) -e HOME=/tmp -v "$PWD":/work \
  np-symfony-spike bash /work/run.sh /work/shot.png 30
```

Expect in the output:

```
== driving /resize/760/520 on port 8100 (secret recovered, 32 chars)
   resize -> HTTP 200
== verifying the middleware actually rejects a secretless request
   no-secret -> HTTP 403 (expect 403)
== re-reading window/get through Symfony after the resize
   size now: 760 × 520
```

and in `app/var/log/dev.log`:

```
app.INFO: native-event Native\Desktop\Events\Windows\WindowShown  {"payload":["main"]}
app.INFO: native-event Native\Desktop\Events\Windows\WindowFocused {"payload":["main"]}
```

## The Symfony side, in full

| File | Role |
|---|---|
| `src/Native/Client.php` | Channel A transport on `HttpClientInterface` — base URI + secret header |
| `src/Native/WindowManager.php` | `open` / `resize` / `title` / `get`, plus the `_windowId` detection |
| `src/Native/NativeEvent.php` | Carrier for caller-named events with no dedicated class |
| `src/Native/PreventRegularBrowserAccessSubscriber.php` | The 403 gate — cookie or header must match |
| `src/Native/Command/NativeConfigCommand.php` | Contract requirement 2 — the five keys the runtime reads |
| `src/Native/Command/NativePhpIniCommand.php` | Contract requirement 3 |
| `src/Native/Command/ScheduleRunCommand.php` | No-op stub; the runtime calls it every 60s regardless |
| `src/Controller/NativeApiController.php` | Requirements 5 and 6 — `/booted` and `/events` |
| `src/Controller/HomeController.php` + `templates/home.html.twig` | The demo page |
| `public/nativephp-router.php` | Requirement 4 — `php -S` router |

## Cleanup

```bash
rm -rf app shot*.png && docker rmi np-symfony-spike
```
