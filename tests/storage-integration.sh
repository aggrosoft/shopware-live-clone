#!/usr/bin/env bash
set -euo pipefail
fixture=$(mktemp -d)
export S3_TEST_LOG="$fixture/requests.log"
php -S 127.0.0.1:18887 /test/tests/s3-read-fixture.php > "$fixture/server.log" 2>&1 &
server=$!
trap 'kill "$server" 2>/dev/null || true; rm -rf "$fixture"' EXIT
for ((attempt=0; attempt<20; attempt++)); do
    if curl -fsS http://127.0.0.1:18887/fixture > /dev/null; then break; fi
    sleep 1
done
php /test/tests/storage-test.php "$fixture" || { cat "$fixture/storage-import.log"; exit 1; }
if grep -Ev '^(GET|HEAD) ' "$fixture/requests.log"; then echo 'Unexpected source write request.' >&2; exit 1; fi
echo 'S3 source received only GET/HEAD requests: OK'
