#!/usr/bin/env bash
# Draait alle losse PHP-tests. Geen phpunit, geen composer — net als fase 1.
#
#   tests/run.sh
#
set -u

cd "$(dirname "$0")/.."

failed=0
total=0

for file in tests/*/test-*.php; do
    total=$((total + 1))
    output=$(php "$file" 2>&1)
    if [ $? -eq 0 ] && echo "$output" | grep -q "ALL PASS"; then
        count=$(echo "$output" | grep -c '^ok: ')
        printf '  \033[32m✓\033[0m %-46s %s checks\n' "$(basename "$file")" "$count"
    else
        failed=$((failed + 1))
        printf '  \033[31m✗\033[0m %s\n' "$(basename "$file")"
        echo "$output" | sed 's/^/      /'
    fi
done

echo
if [ "$failed" -eq 0 ]; then
    printf '\033[32m%s testbestanden groen\033[0m\n' "$total"
    exit 0
fi
printf '\033[31m%s van %s testbestanden gefaald\033[0m\n' "$failed" "$total"
exit 1
