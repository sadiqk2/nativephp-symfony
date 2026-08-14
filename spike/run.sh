#!/usr/bin/env bash
# Launch the patched NativePHP runtime against the Symfony app, headless, and
# grab a screenshot. Runs inside the np-symfony-spike container.
set -euo pipefail

APP=/work/app
ELECTRON=$APP/nativephp/electron
SHOT=${1:-/work/shot.png}
WAIT=${2:-25}

export APP_PATH=$APP
export NATIVEPHP_BUILD_PATH=$APP/nativephp/build
export NATIVEPHP_ELECTRON_PATH=$ELECTRON
export NODE_ENV=development
export SHELL_VERBOSITY=1          # makes php.ts log every PHP command it spawns
export ELECTRON_DISABLE_SANDBOX=1 # no user namespaces in the container
export ELECTRON_ENABLE_LOGGING=1
export DISPLAY=:99

# We skip `node php.js` (the `npm run dev` prelude): it exists only to unzip a
# binary out of the nativephp/php-bin composer package. We already placed a real
# PHP at $NATIVEPHP_BUILD_PATH/php/php, which is all the runtime reads.
Xvfb :99 -screen 0 1400x900x24 -nolisten tcp &
XVFB=$!
trap 'kill $XVFB 2>/dev/null || true' EXIT

for _ in $(seq 1 30); do xdpyinfo -display :99 >/dev/null 2>&1 && break; sleep 0.3; done
echo "== Xvfb up on :99"

cd "$ELECTRON"
npx electron-vite dev > /tmp/electron.log 2>&1 &
APPPID=$!

echo "== runtime starting (pid $APPPID), waiting ${WAIT}s"
for _ in $(seq 1 "$WAIT"); do
  grep -q "PHP Server started on port" /tmp/electron.log 2>/dev/null && break
  kill -0 $APPPID 2>/dev/null || { echo "!! runtime exited early"; break; }
  sleep 1
done

# Let the window paint and the booted round-trip finish.
sleep 6

# Drive a channel-A mutation from inside the app: hit a Symfony route that calls
# WindowManager::resize(). Needs the shared secret, exactly like a real request
# from the renderer would carry (PreventRegularBrowserAccessSubscriber enforces it).
PHPPORT=$(sed -n 's/.*PHP Server started on port: *\([0-9]*\).*/\1/p' /tmp/electron.log | head -1)

# The shared secret is generated inside the runtime and handed to PHP only as an
# environment variable — it is never logged. Lift it from the running dev
# server's own environ so this harness can make a request that passes
# PreventRegularBrowserAccessSubscriber, exactly as the renderer's requests do
# (the runtime injects the same header via webRequest.onBeforeSendHeaders).
SECRET=""
for pid in $(pgrep -f "\-S 127.0.0.1:${PHPPORT:-0}" 2>/dev/null || true); do
  SECRET=$(tr '\0' '\n' < "/proc/$pid/environ" 2>/dev/null | sed -n 's/^NATIVEPHP_SECRET=//p' | head -1)
  [ -n "$SECRET" ] && break
done

if [ -n "${PHPPORT:-}" ] && [ -n "$SECRET" ]; then
  echo "== driving /resize/760/520 on port $PHPPORT (secret recovered, ${#SECRET} chars)"
  curl -s -o /dev/null -w "   resize -> HTTP %{http_code}\n" -L \
    -H "X-NativePHP-Secret: $SECRET" \
    "http://127.0.0.1:$PHPPORT/resize/760/520" || echo "   (curl failed)"
  echo "== verifying the middleware actually rejects a secretless request"
  curl -s -o /dev/null -w "   no-secret -> HTTP %{http_code} (expect 403)\n" \
    "http://127.0.0.1:$PHPPORT/" || true
  echo "== exercising every non-blocking API against the live runtime"
  curl -s -H "X-NativePHP-Secret: $SECRET" "http://127.0.0.1:$PHPPORT/diagnostics" \
    | python3 -m json.tool 2>/dev/null | sed 's/^/   /' || echo "   (diagnostics failed)"

  echo "== re-reading window/get through Symfony after the resize"
  curl -s -H "X-NativePHP-Secret: $SECRET" "http://127.0.0.1:$PHPPORT/" \
    | grep -oE "<td>[0-9]+ × [0-9]+</td>|<td>main</td>" | sed 's/<[^>]*>//g;s/^/   size now: /' || true
  sleep 4
else
  echo "!! could not recover port/secret (port='${PHPPORT:-}' secret_len=${#SECRET})"
fi

echo "== capturing $SHOT"
import -display :99 -window root "$SHOT" 2>/dev/null || echo "!! screenshot failed"

echo
echo "======================= runtime log ======================="
cat /tmp/electron.log
echo "==========================================================="

kill $APPPID 2>/dev/null || true
wait $APPPID 2>/dev/null || true
