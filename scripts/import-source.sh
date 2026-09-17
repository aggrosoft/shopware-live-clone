#!/usr/bin/env bash
set -euo pipefail
set +x
umask 077
script_dir=$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)
# shellcheck source=scripts/ssh-common.sh
source "$script_dir/ssh-common.sh"

# Fixed paths and local socket: source configuration never controls restore targets.
clone_data=/var/lib/shopware-clone
clone_source=$clone_data/source
clone_archive=$clone_data/database.sql.gz
clone_started=0
clone_success=0
cleanup() {
    local status=$?
    if [[ $clone_started == 1 && $clone_success != 1 ]]; then
        printf '%s\n' '{"schema_version":1,"phase":"failed","ready":false}' > "$clone_data/state.json"
        printf '%s\n' 'Import failed. No shop services were started. Use fresh clone and DB volumes before retrying.' >&2
    fi
    clone_ssh_cleanup
    return "$status"
}
trap cleanup EXIT

[[ -d $clone_data && ! -L $clone_data ]] || clone_fail 'Missing clone data directory from image.'
exec 9> "$clone_data/import.lock"
flock -n 9 || clone_fail 'An import is already running.'
if [[ -f $clone_data/state.json ]]; then
    if jq -e '.phase == "imported" and .ready == false' "$clone_data/state.json" >/dev/null; then
        printf '%s\n' 'Source already imported. Existing test data kept; local configuration is the next step.'
        exit 0
    fi
    clone_fail 'Existing or interrupted import found. Create fresh clone and DB volumes; no data was overwritten.'
fi
[[ ! -e $clone_source && ! -L $clone_source && ! -e $clone_archive ]] || clone_fail 'Clone target is not empty.'
# This command must run before Dockware starts services, never in a running shop.
for process in apache2 php-fpm cron supervisord; do
    if pgrep -f "^([^ ]*/)?${process}([ :]|$)" >/dev/null; then
        clone_fail 'Run import in a dedicated stopped-shop container, before Dockware startup.'
    fi
done
clone_ssh_init

printf '%s\n' 'Inspecting source...'
if ! clone_ssh "php -d display_errors=0 -d log_errors=0 -- '$clone_encoded_path'" \
    < "$script_dir/detect-source.php" > "$clone_tmp/result.json" 2> "$clone_tmp/error"; then
    cp "$clone_tmp/error" "$clone_data/transfer-error.log"
    clone_fail 'Source inspection failed; no data imported.'
fi
jq -e '.schema_version == 1 and .php.supported_by_image == true and .shopware.vendor_present == true' \
    "$clone_tmp/result.json" >/dev/null 2>&1 || clone_fail 'Source requires unsupported PHP, is missing vendor, or returned an invalid report.'

# Start only the isolated local database, never the Dockware web/worker entrypoint.
sudo install -d -o mysql -g mysql /var/run/mysqld
if ! sudo service mysql start > /dev/null 2>&1; then clone_fail 'Local MySQL could not start.'; fi
printf '%s\n' '[client]' 'user=root' 'password=root' 'protocol=SOCKET' 'socket=/var/run/mysqld/mysqld.sock' > "$clone_tmp/mysql.cnf"
local_mysql() { MYSQL_TEST_LOGIN_FILE=/dev/null mysql --defaults-file="$clone_tmp/mysql.cnf" --batch --skip-column-names "$@"; }
existing=$(local_mysql -e "SELECT COUNT(*) FROM information_schema.schemata WHERE schema_name='shopware_clone'" 2> "$clone_tmp/error") \
    || clone_fail 'Cannot connect to the isolated local MySQL socket.'
[[ $existing == 0 ]] || clone_fail 'Local shopware_clone database already exists; no data was overwritten.'

printf '%s\n' '{"schema_version":1,"phase":"importing","ready":false}' > "$clone_data/state.json"
clone_started=1
mkdir -m 700 "$clone_source"
cp "$clone_tmp/result.json" "$clone_data/source-report.json"
printf '%s\n' 'Copying shop files, plugins, vendor and media...'
# No --delete and no source-side writes. Internal relative symlinks are preserved.
if ! rsync --archive --no-owner --no-group --protect-args \
    --chmod=u+rwX,go-rwx --timeout=120 \
    --exclude='/.git/' --exclude='/var/cache/' --exclude='/var/log/' --exclude='/var/sessions/' \
    --exclude='/node_modules/' \
    -e "ssh -F $clone_tmp/config" \
    "clone-source:${SOURCE_SHOP_PATH%/}/" "$clone_source/" \
    > /dev/null 2> "$clone_tmp/error"; then
    cp "$clone_tmp/error" "$clone_data/transfer-error.log"
    clone_fail 'File transfer failed; check path, SSH/rsync and available disk space.'
fi
[[ -f $clone_source/composer.lock && -f $clone_source/vendor/autoload.php && -f $clone_source/bin/console ]] \
    || clone_fail 'Copied project is incomplete (possibly external symlinks).'
# Reject broken links and symlinks escaping the copied project instead of silently losing data.
if ! php "$script_dir/validate-copy.php" "$clone_source" > /dev/null 2> "$clone_tmp/error"; then
    clone_fail 'Copied project contains broken or external symlinks; use a self-contained source tree.'
fi

printf '%s\n' 'Creating a transactional source dump over SSH...'
if ! clone_ssh "php -d display_errors=0 -d log_errors=0 -- '$clone_encoded_path'" \
    < "$script_dir/source-database.php" 2> "$clone_tmp/error" | gzip -1 > "$clone_archive"; then
    cp "$clone_tmp/error" "$clone_data/transfer-error.log"
    clone_fail 'Source dump failed. Check DATABASE_URL, dump privileges, InnoDB tables and disk space.'
fi
gzip -t "$clone_archive" 2> "$clone_tmp/error" || clone_fail 'Incomplete compressed database dump.'
printf '%s\n' 'Restoring into the local shopware_clone database...'
local_mysql -e 'CREATE DATABASE shopware_clone CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci' \
    2> "$clone_tmp/error" || clone_fail 'Could not create the local clone database.'
# --binary-mode disables mysql client commands in the input dump.
if ! gzip -dc "$clone_archive" | local_mysql --binary-mode=1 shopware_clone > /dev/null 2> "$clone_tmp/error"; then
    clone_fail 'Local restore failed (for example an incompatible source collation). No shop services were started.'
fi
tables=$(local_mysql -e "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='shopware_clone' AND table_name IN ('product','sales_channel_domain','system_config')" 2> "$clone_tmp/error")
[[ $tables == 3 ]] || clone_fail 'Restored database does not contain the expected Shopware tables.'
rm -f -- "$clone_archive"
printf '%s\n' '{"schema_version":1,"phase":"imported","ready":false,"database":"shopware_clone"}' > "$clone_data/state.json.next"
mv "$clone_data/state.json.next" "$clone_data/state.json"
clone_success=1
printf '%s\n' 'Files and database imported. Shop remains stopped until local configuration is applied.'
