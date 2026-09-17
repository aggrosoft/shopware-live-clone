#!/usr/bin/env bash
set -euo pipefail
test -f /var/www/container.launched
jq -e '.ready == true' /var/lib/shopware-clone/state.json > /dev/null
sudo supervisorctl status 'clone-worker:*' | awk '$2 != "RUNNING" {exit 1} END {if (NR != 2) exit 1}'
pgrep -x cron > /dev/null
target=$(jq -r '.url' /var/lib/shopware-clone/runtime.json)
host=${target#*://}
host=${host%%/*}
curl --fail --silent --max-time 10 -H "Host: $host" http://127.0.0.1/ > /dev/null
