#!/usr/bin/env bash
# Shared by detection and import. Callers own the EXIT trap.
clone_fail() { printf '%s\n' "$1" >&2; exit 64; }

clone_ssh_init() {
    set +x
    umask 077
    [[ ${SOURCE_SSH_HOST:-} =~ ^[a-zA-Z0-9][a-zA-Z0-9.-]*$ ]] || clone_fail 'Invalid SOURCE_SSH_HOST.'
    [[ ${SOURCE_SSH_USER:-} =~ ^[a-zA-Z0-9_][a-zA-Z0-9_.-]*$ ]] || clone_fail 'Invalid SOURCE_SSH_USER.'
    local port=${SOURCE_SSH_PORT:-22}
    if ! [[ $port =~ ^[0-9]{1,5}$ ]] || ! (( 10#$port > 0 && 10#$port <= 65535 )); then
        clone_fail 'Invalid SOURCE_SSH_PORT.'
    fi
    [[ ${SOURCE_SHOP_PATH:-} == /* && $SOURCE_SHOP_PATH != *$'\n'* && $SOURCE_SHOP_PATH != *$'\r'* ]] || clone_fail 'SOURCE_SHOP_PATH must be an absolute project directory.'
    [[ -n ${SOURCE_SSH_PRIVATE_KEY:-} ]] || clone_fail 'Missing SOURCE_SSH_PRIVATE_KEY.'
    clone_tmp=$(mktemp -d /tmp/shopware-clone.XXXXXXXX)
    printf '%s\n' "$SOURCE_SSH_PRIVATE_KEY" > "$clone_tmp/key"
    local known_hosts strict_host_key_checking
    if [[ -n ${SOURCE_SSH_KNOWN_HOSTS:-} ]]; then
        known_hosts=$clone_tmp/known_hosts
        strict_host_key_checking=yes
        printf '%s\n' "$SOURCE_SSH_KNOWN_HOSTS" > "$known_hosts"
    else
        # Keep first-contact trust in the clone volume, outside the copied shop.
        local trust_dir=/var/lib/shopware-clone/ssh
        [[ -d /var/lib/shopware-clone && ! -L /var/lib/shopware-clone ]] || clone_fail 'Missing clone data directory.'
        [[ ! -L $trust_dir ]] || clone_fail 'Invalid SSH trust directory.'
        mkdir -p -m 700 "$trust_dir"
        chmod 700 "$trust_dir"
        known_hosts=$trust_dir/known_hosts
        [[ ! -L $known_hosts && ( ! -e $known_hosts || -f $known_hosts ) ]] || clone_fail 'Invalid SSH known_hosts file.'
        touch "$known_hosts"
        chmod 600 "$known_hosts"
        strict_host_key_checking=accept-new
    fi
    unset SOURCE_SSH_PRIVATE_KEY SOURCE_SSH_KNOWN_HOSTS
    printf '%s\n' 'Host clone-source' \
        "HostName $SOURCE_SSH_HOST" "Port $port" "User $SOURCE_SSH_USER" \
        "IdentityFile $clone_tmp/key" "UserKnownHostsFile $known_hosts" \
        'GlobalKnownHostsFile /dev/null' "StrictHostKeyChecking $strict_host_key_checking" \
        'UpdateHostKeys no' \
        'BatchMode yes' 'IdentitiesOnly yes' 'IdentityAgent none' \
        'ConnectTimeout 15' 'ServerAliveInterval 15' 'ServerAliveCountMax 2' \
        'LogLevel ERROR' > "$clone_tmp/config"
    # Used by the scripts sourcing this library.
    # shellcheck disable=SC2034
    clone_encoded_path=$(printf '%s' "$SOURCE_SHOP_PATH" | base64 -w0)
}

clone_ssh() { ssh -F "$clone_tmp/config" -T clone-source "$@"; }

clone_ssh_cleanup() {
    if [[ -n ${clone_tmp:-} ]]; then
        rm -f -- "$clone_tmp/key" "$clone_tmp/known_hosts" "$clone_tmp/config" \
            "$clone_tmp/result.json" "$clone_tmp/error" "$clone_tmp/mysql.cnf"
        rmdir -- "$clone_tmp"
    fi
}
