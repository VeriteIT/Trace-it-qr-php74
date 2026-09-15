#!/bin/sh
# Verifies the generated PHP 7.4 build on a real 7.4 interpreter.
#
# It exercises the compositing path, so it needs ext-gd and the stock php:7.4-cli
# image will not do. Build the local one once:
#   docker build -t traceit-php74-gd -f tools/php74-gd.Dockerfile .
#
# Produce the two 8.1 references on the host, then run this from the repository
# root with them mounted where it looks for them:
#   php tools/equivalence-dump.php ../Trace-it-Composer-Package/src > /tmp/dump-81.txt
#   php tools/gd-dump.php ../Trace-it-Composer-Package/src 8101      > /tmp/gd-81.txt
#   docker run --rm -v "$PWD:/app" -v /tmp:/ref -w /app traceit-php74-gd \
#     sh -c "cp /ref/dump-81.txt /ref/gd-81.txt /tmp/ && sh tools/verify-php74.sh"
#
# Without those references it still lints and still runs both dumps; it just cannot
# tell you they match, only that they did not crash.
#
# Checking the output against the 7.4 grammar and watching it behave identically on
# 8.x are both good evidence, but neither is the same as running on 7.4. This is that
# check, and it has caught what the other two could not.
set -u

echo "interpreter: $(php -r 'echo PHP_VERSION;')"
echo

echo "--- lint ---"
total=0
fail=0
# Most of tools/ runs on the dev machine's PHP 8. These two run in here, on 7.4,
# so they are held to the same standard as the package itself.
for f in $(find src examples snippets -name '*.php') tools/equivalence-dump.php tools/gd-dump.php; do
    total=$((total + 1))
    if ! php -l "$f" > /dev/null 2>&1; then
        fail=$((fail + 1))
        echo "  FAIL $f"
        php -l "$f" 2>&1 | sed 's/^/    /'
    fi
done
if [ "$fail" -eq 0 ]; then
    echo "  $total files lint clean"
fi
echo

echo "--- behaviour ---"
php tools/equivalence-dump.php src > /tmp/dump-74-real.txt 2>&1
status=$?
if [ $status -ne 0 ]; then
    echo "  the dump did not run on 7.4:"
    sed 's/^/    /' /tmp/dump-74-real.txt | head -20
    exit 1
fi
echo "  dump produced $(wc -l < /tmp/dump-74-real.txt) lines"

if [ -f /tmp/dump-81.txt ]; then
    if diff -q /tmp/dump-81.txt /tmp/dump-74-real.txt > /dev/null; then
        echo "  IDENTICAL to the 8.1 build"
    else
        echo "  DIFFERS from the 8.1 build:"
        diff /tmp/dump-81.txt /tmp/dump-74-real.txt | head -30
        exit 1
    fi
else
    echo "  (no 8.1 reference mounted at /tmp/dump-81.txt — compare on the host)"
fi

echo "--- compositing (gd) ---"
php tools/gd-dump.php src > /tmp/gd-74-real.txt 2>&1
status=$?
if [ $status -ne 0 ]; then
    echo "  the gd dump did not run on 7.4:"
    sed 's/^/    /' /tmp/gd-74-real.txt | head -20
    exit 1
fi
echo "  dump produced $(wc -l < /tmp/gd-74-real.txt) lines"

if [ -f /tmp/gd-81.txt ]; then
    if diff -q /tmp/gd-81.txt /tmp/gd-74-real.txt > /dev/null; then
        echo "  IDENTICAL to the 8.1 build"
    else
        echo "  DIFFERS from the 8.1 build:"
        diff /tmp/gd-81.txt /tmp/gd-74-real.txt | head -30
        exit 1
    fi
else
    echo "  (no 8.1 reference mounted at /tmp/gd-81.txt — compare on the host)"
fi
echo

exit $((fail > 0))
