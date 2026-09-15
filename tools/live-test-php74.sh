#!/bin/sh
# Runs preflight and the smoke test against the LIVE Trace-It API, on PHP 7.4,
# using the generated build. The last verification the 7.4 port needs.
#
# Credentials come from .env, which is gitignored and never printed here. The
# preflight output shows only the key's prefix, so its output is safe to share.
#
#   docker run --rm --env-file .env \
#     -v "$PWD:/app" -w /app traceit-php74-gd sh tools/live-test-php74.sh [postId]
#
# QUOTA: reusing an existing postId charges nothing (created: false). A new one
# charges a single unit. Pass an ID you have used before to spend nothing.
set -u

POST_ID="${1:-preflight-check}"
SRC="${SRC:-.}"

echo "interpreter : $(php -r 'echo PHP_VERSION;')"
echo "gd          : $(php -r 'echo extension_loaded("gd") ? "loaded" : "MISSING";')"
echo "build       : $SRC"
echo "post id     : $POST_ID"
echo "base url    : ${TRACEIT_BASE:-<unset>}"
echo "api key     : $(php -r 'echo substr(getenv("TRACEIT_API_KEY") ?: "", 0, 8) ?: "<unset>";')…"
echo

if [ -z "${TRACEIT_API_KEY:-}" ] || [ -z "${TRACEIT_BASE:-}" ]; then
    echo "TRACEIT_API_KEY and TRACEIT_BASE must both be set. Put them in .env."
    exit 2
fi

echo "=========== preflight ==========="
php "$SRC/examples/preflight.php" "$POST_ID" "${2:-}" "${3:-}"
pf=$?
echo "preflight exit: $pf"
echo

echo "=========== smoke test ==========="
php "$SRC/examples/smoke-test.php" "$POST_ID" "${2:-}" "${3:-}"
st=$?
echo "smoke exit: $st"

exit $((pf != 0 || st != 0))
