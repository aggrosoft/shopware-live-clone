#!/usr/bin/env bash
set -euo pipefail
data=/var/lib/shopware-clone
jq -e '.ready == true' "$data/state.json" > /dev/null
# shellcheck disable=SC1091
source "$data/runtime.sh"
php_version=$(jq -r '.php' "$data/runtime.json")
[[ $php_version =~ ^8\.[2345]$ ]] || exit 1
cd "$data/source"
exec "/usr/bin/php$php_version" bin/console "$@" --no-interaction
