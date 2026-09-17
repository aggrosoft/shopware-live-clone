#!/usr/bin/env bash
set -euo pipefail
# Never inherit xtrace when processing credentials.
set +x
umask 077

fail() { printf '%s\n' "$1" >&2; exit 64; }
[[ ${SOURCE_SSH_HOST:-} =~ ^[a-zA-Z0-9][a-zA-Z0-9.-]*$ ]] || fail 'Set SOURCE_SSH_HOST to the SSH hostname or IPv4 address.'
[[ ${SOURCE_SSH_USER:-} =~ ^[a-zA-Z0-9_][a-zA-Z0-9_.-]*$ ]] || fail 'Set SOURCE_SSH_USER to the hosting SSH user.'
port=${SOURCE_SSH_PORT:-22}
if ! [[ $port =~ ^[0-9]{1,5}$ ]] || ! (( 10#$port > 0 && 10#$port <= 65535 )); then
    fail 'Invalid SOURCE_SSH_PORT.'
fi
[[ ${SOURCE_SHOP_PATH:-} == /* && $SOURCE_SHOP_PATH != *$'\n'* ]] || fail 'SOURCE_SHOP_PATH must be an absolute shop directory.'
[[ -n ${SOURCE_SSH_PRIVATE_KEY:-} ]] || fail 'Set SOURCE_SSH_PRIVATE_KEY (multiline, unencrypted private key).'
[[ -n ${SOURCE_SSH_KNOWN_HOSTS:-} ]] || fail 'Set SOURCE_SSH_KNOWN_HOSTS to the verified SSH host key entry.'

detect_tmp=$(mktemp -d)
trap 'rm -f -- "$detect_tmp/key" "$detect_tmp/known_hosts" "$detect_tmp/result.json" "$detect_tmp/error"; rmdir -- "$detect_tmp"' EXIT
printf '%s\n' "$SOURCE_SSH_PRIVATE_KEY" > "$detect_tmp/key"
printf '%s\n' "$SOURCE_SSH_KNOWN_HOSTS" > "$detect_tmp/known_hosts"
unset SOURCE_SSH_PRIVATE_KEY SOURCE_SSH_KNOWN_HOSTS

script_dir=$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)
# Encode the path rather than interpolating it into remote shell syntax.
encoded_path=$(printf '%s' "$SOURCE_SHOP_PATH" | base64 -w0)
if ! timeout 90 ssh -F /dev/null -T -p "$port" -i "$detect_tmp/key" \
    -o BatchMode=yes -o IdentitiesOnly=yes -o IdentityAgent=none \
    -o StrictHostKeyChecking=yes -o "UserKnownHostsFile=$detect_tmp/known_hosts" \
    -o GlobalKnownHostsFile=/dev/null -o ConnectTimeout=15 \
    -o ServerAliveInterval=15 -o ServerAliveCountMax=2 -o LogLevel=ERROR \
    "$SOURCE_SSH_USER@$SOURCE_SSH_HOST" \
    "php -d display_errors=0 -d log_errors=0 -- '$encoded_path'" \
    < "$script_dir/detect-source.php" > "$detect_tmp/result.json" 2> "$detect_tmp/error"; then
    # Remote login scripts and PHP errors can contain secrets; do not echo stderr.
    fail 'Source inspection failed. Check SSH key, verified host key, path and PHP CLI (7.4+). No import was performed.'
fi

jq -e '.schema_version == 1 and .shopware.version != null' "$detect_tmp/result.json" >/dev/null 2>&1 \
    || fail 'Source did not return a valid Shopware report. Check the path and noninteractive SSH startup output.'
jq . "$detect_tmp/result.json"
