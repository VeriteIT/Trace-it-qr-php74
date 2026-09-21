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
ARTICLE_URL="${2:-}"
IMAGE_URL="${3:-}"
SRC="${SRC:-.}"

# Pass the optional URLs only when they are actually set. Handing the examples an
# empty string is not the same as omitting it: preflight reads "" as a URL that is
# not https and reports FAIL, when the honest answer is SKIP.
# They are positional, so the image URL cannot be given without the article URL —
# it would silently be read as the article URL. Refuse rather than mislead.
if [ -n "$IMAGE_URL" ] && [ -z "$ARTICLE_URL" ]; then
    echo "An image URL needs an article URL before it: $0 <postId> <articleUrl> <imageUrl>"
    exit 2
fi

set -- "$POST_ID"
if [ -n "$ARTICLE_URL" ]; then set -- "$@" "$ARTICLE_URL"; fi
if [ -n "$IMAGE_URL" ]; then set -- "$@" "$IMAGE_URL"; fi

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
php "$SRC/examples/preflight.php" "$@"
pf=$?
echo "preflight exit: $pf"
echo

echo "=========== smoke test ==========="
php "$SRC/examples/smoke-test.php" "$@"
st=$?
echo "smoke exit: $st"

exit $((pf != 0 || st != 0))
