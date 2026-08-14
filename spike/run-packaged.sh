#!/usr/bin/env bash
# Launch the *packaged* app (dist/linux-unpacked) headless and screenshot it.
# This is the only real test of a build: the staged app runs under the static PHP
# binary from php-bin, with prod caches, no dev dependencies and a cleaned .env —
# none of which the dev loop exercises.
set -euo pipefail

APP=/work/app
PKG=$APP/nativephp/electron/dist/linux-unpacked
SHOT=${1:-/work/shot-packaged.png}
BIN=$(find "$PKG" -maxdepth 1 -type f -executable ! -name '*.so*' ! -name 'chrome*' | head -1)

export DISPLAY=:99
export ELECTRON_DISABLE_SANDBOX=1
export ELECTRON_ENABLE_LOGGING=1
export SHELL_VERBOSITY=1

Xvfb :99 -screen 0 1400x900x24 -nolisten tcp &
XVFB=$!
trap 'kill $XVFB 2>/dev/null || true' EXIT
for _ in $(seq 1 30); do xdpyinfo -display :99 >/dev/null 2>&1 && break; sleep 0.3; done

echo "== launching packaged binary: $BIN"
"$BIN" > /tmp/packaged.log 2>&1 &
APPPID=$!

for _ in $(seq 1 45); do
  grep -q "PHP Server started on port" /tmp/packaged.log 2>/dev/null && break
  kill -0 $APPPID 2>/dev/null || { echo "!! app exited early"; break; }
  sleep 1
done

sleep 8

PHPPORT=$(sed -n 's/.*PHP Server started on port: *\([0-9]*\).*/\1/p' /tmp/packaged.log | head -1)
if [ -n "${PHPPORT:-}" ]; then
  SECRET=""
  for pid in $(pgrep -f "\-S 127.0.0.1:${PHPPORT}" 2>/dev/null || true); do
    SECRET=$(tr '\0' '\n' < "/proc/$pid/environ" 2>/dev/null | sed -n 's/^NATIVEPHP_SECRET=//p' | head -1)
    [ -n "$SECRET" ] && break
  done
  echo "== packaged app is serving on $PHPPORT; asking it for its own state"
  curl -s -H "X-NativePHP-Secret: $SECRET" "http://127.0.0.1:$PHPPORT/" \
    | grep -oE "<td>[^<]*</td>" | sed 's/<[^>]*>//g' | paste - - 2>/dev/null | sed 's/^/   /' | head -12 || true
  echo "== diagnostics from the packaged app"
  curl -s -H "X-NativePHP-Secret: $SECRET" "http://127.0.0.1:$PHPPORT/diagnostics" \
    | python3 -c 'import json,sys; d=json.load(sys.stdin); [print(f"   {k}: {json.dumps(v)}") for k,v in d.items()]' 2>/dev/null || echo "   (no diagnostics)"
fi

import -display :99 -window root "$SHOT" 2>/dev/null || echo "!! screenshot failed"

echo
echo "==================== packaged app log ===================="
tail -30 /tmp/packaged.log
echo "========================================================="
kill $APPPID 2>/dev/null || true
