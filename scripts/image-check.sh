#!/usr/bin/env bash
set -euo pipefail

for executable in bash ssh rsync mysql mysqldump jq gzip flock sudo; do
    command -v "$executable" >/dev/null || {
        printf 'Missing runtime tool: %s\n' "$executable" >&2
        exit 1
    }
done

test -r /entrypoint.sh
test -r /var/www/makefile
test ! -e /var/www/html/shopware.tar.zst
test ! -e /var/www/html/bin/console

for version in 8.2 8.3 8.4 8.5; do
    command -v "php${version}" >/dev/null
    # PHP variables must reach PHP unchanged, without shell expansion.
    # shellcheck disable=SC2016
    "php${version}" -r 'foreach (["pdo_mysql", "curl", "intl", "mbstring", "xml", "zip"] as $extension) { if (!extension_loaded($extension)) { fwrite(STDERR, "Missing PHP extension: " . $extension . PHP_EOL); exit(1); } }'
    printf 'PHP %s: available\n' "$version"
done

printf 'Clone image prerequisites: OK\n'
