#!/bin/sh
# Verifies the generated PHP 7.4 build on a real 7.4 interpreter.
#
# Run from the repository root:
#   docker run --rm -v "$PWD:/app" -w /app php:7.4-cli sh tools/verify-php74.sh
#
# Parsing under the 7.4 grammar and behaving identically on 8.x are both good
# evidence, but neither is the same as running on 7.4. This is that check.
set -u

echo "interpreter: $(php -r 'echo PHP_VERSION;')"
echo

echo "--- lint ---"
total=0
fail=0
for f in $(find src examples snippets -name '*.php'); do
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

exit $((fail > 0))
