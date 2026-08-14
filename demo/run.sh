#!/usr/bin/env bash
#
# Boot the demo headlessly, drive it, and screenshot it.
#
# Runs *inside* the np-symfony-spike container (PHP 8.4, Node 22, Electron's system
# libraries, Xvfb, ImageMagick). See README.md for the docker invocation.
#
# What this does that a human at a desk does not have to:
#
#   - starts an X server, because Electron needs one and there is no display here;
#   - lifts the runtime's shared secret out of the running PHP server's own environ,
#     so it can make requests that pass the bundle's RuntimeAccessSubscriber exactly
#     as the renderer's requests do. The secret is generated inside the runtime and
#     handed to PHP as an environment variable — it is never logged, and there is no
#     other way to obtain it from outside;
#   - drives a menu-item event by POSTing to /_native/api/events, which is byte for
#     byte what the runtime does when a real menu item is clicked. That is how a menu
#     is verified without a mouse.
#
# Everything else — the window, the menu, the events, the child process — is the real
# runtime doing its real job.
set -euo pipefail

APP=${APP_DIR:-/np/demo}
ELECTRON=$APP/nativephp/electron
SHOT=${1:-$APP/shot.png}
WAIT=${2:-40}

export DISPLAY=:99
export ELECTRON_DISABLE_SANDBOX=1   # no user namespaces in the container
export ELECTRON_ENABLE_LOGGING=1
export SHELL_VERBOSITY=1            # makes the runtime log every PHP command it spawns

Xvfb :99 -screen 0 1400x900x24 -nolisten tcp &
XVFB=$!
trap 'kill $XVFB 2>/dev/null || true' EXIT

for _ in $(seq 1 30); do xdpyinfo -display :99 >/dev/null 2>&1 && break; sleep 0.3; done
echo "== Xvfb up on :99"

# native:run is the bundle's own dev command: it places the PHP binary and the CA
# bundle in nativephp/build/, exports the environment the Electron project reads, and
# runs `npx electron-vite dev`. Using it rather than reimplementing it means this
# script verifies the command a developer actually types.
cd "$APP"
php bin/console native:run -v > /tmp/electron.log 2>&1 &
APPPID=$!

echo "== runtime starting (pid $APPPID), waiting up to ${WAIT}s for the PHP server"
for _ in $(seq 1 "$WAIT"); do
  grep -q "PHP Server started on port" /tmp/electron.log 2>/dev/null && break
  kill -0 $APPPID 2>/dev/null || { echo "!! runtime exited early"; break; }
  sleep 1
done

# Let the window paint and the /booted round trip finish: the window only exists
# because Bootstrapper::boot() asked for it, and that happens after this line appears.
sleep 8

PORT=$(sed -n 's/.*PHP Server started on port: *\([0-9]*\).*/\1/p' /tmp/electron.log | head -1)

SECRET=""
for pid in $(pgrep -f "\-S 127.0.0.1:${PORT:-0}" 2>/dev/null || true); do
  SECRET=$(tr '\0' '\n' < "/proc/$pid/environ" 2>/dev/null | sed -n 's/^NATIVEPHP_SECRET=//p' | head -1)
  [ -n "$SECRET" ] && break
done

if [ -z "${PORT:-}" ] || [ -z "$SECRET" ]; then
  echo "!! could not recover the port or the secret (port='${PORT:-}' secret_len=${#SECRET})"
  echo "== capturing $SHOT anyway"
  import -display :99 -window root "$SHOT" 2>/dev/null || echo "!! screenshot failed"
  sed -n '1,120p' /tmp/electron.log
  kill $APPPID 2>/dev/null || true
  exit 1
fi

BASE="http://127.0.0.1:$PORT"
echo "== app on port $PORT (secret recovered, ${#SECRET} chars)"

sec() { curl -s -H "X-NativePHP-Secret: $SECRET" "$@"; }

echo
echo "-- the browser gate really is closed"
curl -s -o /dev/null -w "   GET / with no secret -> HTTP %{http_code} (expect 403)\n" "$BASE/" || true
sec -o /dev/null -w "   GET / with the secret  -> HTTP %{http_code} (expect 200)\n" "$BASE/" || true

echo
echo "-- opening the second window from the menu item, then asking the runtime what exists"
sec -o /dev/null -w "   POST /_native/api/events -> HTTP %{http_code}\n" \
  -X POST -H 'Content-Type: application/json' \
  -d '{"event":"App\\Menu\\OpenInspector","payload":[]}' "$BASE/_native/api/events" || true
sleep 3

echo
echo "-- starting a child process from the UI's own route"
sec -o /dev/null -w "   POST /jobs/counter -> HTTP %{http_code}\n" -X POST "$BASE/jobs/counter" || true

echo
echo "-- the whole non-blocking surface, against the live runtime"
sec "$BASE/_smoke" | python3 -m json.tool 2>/dev/null | sed 's/^/   /' || echo "   (smoke failed)"

echo
echo "-- what the pages render inside the runtime"
for path in / /notes /jobs /mobile /mobile/profile "/mobile/counter?taps=4" /inspector; do
  code=$(sec -o /tmp/page.html -w '%{http_code}' "$BASE$path")
  echo "   $path -> HTTP $code, $(wc -c </tmp/page.html) bytes"
done

# Let the child process finish so its output lands in the event feed before the shot.
sleep 4

echo
echo "== capturing $SHOT"
import -display :99 -window root "$SHOT" 2>/dev/null || echo "!! screenshot failed"

# A second shot of the notes screen, where the persistence lives. Both events below
# are the ones the *menu* sends: Seed writes three notes and navigates the main window,
# which is how a menu item is shown to work without a mouse.
if [ -n "${SHOT2:-}" ]; then
  echo
  echo "-- closing the second window, so the notes screen is unobstructed"
  sec -o /dev/null -w "   POST /inspector/close -> HTTP %{http_code}\n" -X POST "$BASE/inspector/close" || true

  echo
  echo "-- driving the menu items that write notes and navigate the window"
  for name in 'App\\Menu\\Seed' 'App\\Menu\\CopyLatest'; do
    sec -o /dev/null -w "   $name -> HTTP %{http_code}\n" \
      -X POST -H 'Content-Type: application/json' \
      -d "{\"event\":\"$name\",\"payload\":[]}" "$BASE/_native/api/events" || true
  done
  sleep 5
  echo "== capturing $SHOT2"
  import -display :99 -window root "$SHOT2" 2>/dev/null || echo "!! second screenshot failed"
fi

# A shot of the mobile half, driven by the menu item that navigates there. The only
# visual evidence mobile gets — and it is evidence of the wire format, not of a device.
if [ -n "${SHOT4:-}" ]; then
  echo
  echo "-- navigating to the native-UI screen from the menu"
  sec -o /dev/null -w "   App\\Menu\\MobileScreens -> HTTP %{http_code}\n" \
    -X POST -H 'Content-Type: application/json' \
    -d '{"event":"App\\Menu\\MobileScreens","payload":[]}' "$BASE/_native/api/events" || true
  sleep 4
  echo "== capturing $SHOT4"
  import -display :99 -window root "$SHOT4" 2>/dev/null || echo "!! fourth screenshot failed"
fi

# A third shot of a *native dialog*, if asked for. This one is different in kind: the
# dialog blocks the runtime's main thread until it is answered, so the request never
# returns and the runtime is wedged from here on. Hence last, hence a short curl
# timeout, and hence a separate flag.
if [ -n "${SHOT3:-}" ]; then
  echo
  echo "-- opening the native save dialog (blocking; this wedges the runtime on purpose)"
  ID=$(sec "$BASE/notes" | sed -n 's#.*/notes/\([0-9a-f]\{8\}\)/export.*#\1#p' | head -1)
  if [ -n "$ID" ]; then
    sec -m 6 -o /dev/null -X POST "$BASE/notes/$ID/export" || echo "   (request still open, as expected)"
    sleep 3
    echo "== capturing $SHOT3"
    import -display :99 -window root "$SHOT3" 2>/dev/null || echo "!! third screenshot failed"
  else
    echo "   (no note to export)"
  fi
fi

echo
echo "-- what PHP logged from the reverse channel (typed listeners)"
grep -oE 'app\.(INFO|WARNING): runtime: .*' "$APP/var/log/dev.log" 2>/dev/null | tail -25 | sed 's/^/   /' || true

echo
echo "======================== runtime log ========================"
tail -60 /tmp/electron.log
echo "============================================================="

kill $APPPID 2>/dev/null || true
wait $APPPID 2>/dev/null || true
