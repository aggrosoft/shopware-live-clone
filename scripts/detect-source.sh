#!/usr/bin/env bash
set -euo pipefail
set +x
script_dir=$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)
# shellcheck source=scripts/ssh-common.sh
source "$script_dir/ssh-common.sh"
trap clone_ssh_cleanup EXIT
clone_ssh_init
if ! timeout 90 ssh -F "$clone_tmp/config" -T clone-source \
    "php -d display_errors=0 -d log_errors=0 -- '$clone_encoded_path'" \
    < "$script_dir/detect-source.php" > "$clone_tmp/result.json" 2> "$clone_tmp/error"; then
    clone_fail 'Source inspection failed. Check SSH key, verified host key, path and PHP CLI (7.4+). No import was performed.'
fi
jq -e '.schema_version == 1 and .shopware.version != null' "$clone_tmp/result.json" >/dev/null 2>&1 \
    || clone_fail 'Source did not return a valid Shopware report.'
jq . "$clone_tmp/result.json"
