#!/usr/bin/env bash
# Run the bundle test suites. Uses a local php if it has ext-zip, otherwise Docker.
# Usage: tools/test.sh [suite ...]        e.g. tools/test.sh mobile-bundle
# Extra phpunit arguments come from EXTRA_ARGS, e.g. EXTRA_ARGS="--filter Foo" tools/test.sh
set -euo pipefail

root="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
if [ $# -gt 0 ]; then suites=("$@"); else suites=(bundle mobile-bundle); fi
read -r -a extra <<< "${EXTRA_ARGS:-}"

php_ok() { command -v php >/dev/null 2>&1 && php -m 2>/dev/null | grep -qx zip; }

run() {
    if php_ok; then
        (cd "$root/$1" && php vendor/bin/phpunit --no-coverage "${extra[@]}")
    else
        docker run --rm --user "$(id -u):$(id -g)" -e HOME=/tmp \
            -v "$root:/repo" -w "/repo/$1" "${TEST_IMAGE:-nativephp-test:8.4}" \
            php vendor/bin/phpunit --no-coverage "${extra[@]}"
    fi
}

status=0
for suite in "${suites[@]}"; do
    echo "==> $suite"
    run "$suite" || status=1
done
exit $status
