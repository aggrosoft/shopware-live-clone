#!/usr/bin/env bash
set -euo pipefail
set +x
umask 077
script_dir=$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)
# shellcheck source=scripts/ssh-common.sh
source "$script_dir/ssh-common.sh"
# shellcheck source=scripts/progress.sh
source "$script_dir/progress.sh"

# Fixed target connection: source configuration never controls restore targets.
clone_data=/var/lib/shopware-clone
clone_source=$clone_data/source
clone_archive=$clone_data/database.sql.gz
clone_db_host=${CLONE_DATABASE_HOST:-127.0.0.1}
clone_db_port=${CLONE_DATABASE_PORT:-3306}
clone_started=0
clone_success=0
clone_resume=0
cleanup() {
    local status=$?
    (( BASH_SUBSHELL == 0 )) || return "$status"
    clone_progress_stop
    if (( status != 0 )) && [[ -n ${clone_tmp:-} && -s $clone_tmp/error ]]; then
        cp "$clone_tmp/error" "$clone_data/import-error.log"
        printf '%s\n' 'Error details saved: /var/lib/shopware-clone/import-error.log' >&2
    fi
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
    if jq -e '.phase == "failed"' "$clone_data/state.json" >/dev/null &&
        [[ -d $clone_source && ! -L $clone_source && ! -e $clone_archive && ! -L $clone_archive ]]; then
        clone_resume=1
        printf '%s\n' 'Retrying file copy after early import failure; existing files will be reused. Local DB must still be empty.'
    else
        clone_fail 'Existing or interrupted import found. Create fresh clone and DB volumes; no data was overwritten.'
    fi
fi
[[ $clone_resume == 1 || ( ! -e $clone_source && ! -L $clone_source && ! -e $clone_archive ) ]] || clone_fail 'Clone target is not empty.'
# This command must run before Dockware starts services, never in a running shop.
for process in apache2 php-fpm cron supervisord; do
    if pgrep -f "^([^ ]*/)?${process}([ :]|$)" >/dev/null; then
        clone_fail 'Run import in a dedicated stopped-shop container, before Dockware startup.'
    fi
done
clone_ssh_init

clone_progress_start '[1/7] Inspecting source'
if ! clone_ssh "php -d display_errors=0 -d log_errors=0 -- '$clone_encoded_path'" \
    < "$script_dir/detect-source.php" > "$clone_tmp/result.json" 2> "$clone_tmp/error"; then
    cp "$clone_tmp/error" "$clone_data/transfer-error.log"
    clone_fail 'Source inspection failed; no data imported.'
fi
jq -e '.schema_version == 1 and .php.supported_by_image == true and .shopware.vendor_present == true' \
    "$clone_tmp/result.json" >/dev/null 2>&1 || clone_fail 'Source requires unsupported PHP, is missing vendor, or returned an invalid report.'

clone_progress_done

[[ $clone_db_host =~ ^[A-Za-z0-9][A-Za-z0-9.-]*$ && $clone_db_port =~ ^[0-9]+$ && $clone_db_port -ge 1 && $clone_db_port -le 65535 ]] \
    || clone_fail 'Invalid clone database endpoint.'
# CI can still exercise the image standalone. Coolify uses the dedicated MariaDB service.
clone_progress_start "[1/7] Waiting for clone MariaDB"
if [[ $clone_db_host == 127.0.0.1 || $clone_db_host == localhost ]]; then
    sudo install -d -o mysql -g mysql /var/run/mysqld
    if ! sudo service mysql start > /dev/null 2>&1; then clone_fail 'Bundled database could not start.'; fi
fi
printf '%s\n' '[client]' 'user=root' 'password=root' 'protocol=TCP' "host=$clone_db_host" "port=$clone_db_port" > "$clone_tmp/mysql.cnf"
local_mysql() { MYSQL_TEST_LOGIN_FILE=/dev/null mysql --defaults-file="$clone_tmp/mysql.cnf" --batch --skip-column-names "$@"; }
database_ready=0
for ((attempt=0; attempt<60; attempt++)); do
    if local_mysql -e 'SELECT 1' > /dev/null 2> "$clone_tmp/error"; then database_ready=1; break; fi
    sleep 2
done
[[ $database_ready == 1 ]] || clone_fail 'Clone MariaDB did not become ready.'
existing=$(local_mysql -e "SELECT COUNT(*) FROM information_schema.schemata WHERE schema_name='shopware_clone'" 2> "$clone_tmp/error") \
    || clone_fail 'Cannot connect to clone MariaDB.'
[[ $existing == 0 ]] || clone_fail 'Local shopware_clone database already exists; no data was overwritten.'
clone_progress_done

printf '%s\n' '{"schema_version":1,"phase":"importing","ready":false}' > "$clone_data/state.json"
clone_started=1
mkdir -p "$clone_source"
chmod 700 "$clone_source"
cp "$clone_tmp/result.json" "$clone_data/source-report.json"
printf '%s\n' '[2/7] Copying files: scanning file list, then transferring (ETA covers this phase only)...'
# Materialize source links so absolute hosting paths work in the clone.
copy_status=0
LC_ALL=C rsync --copy-links --rsync-path="LC_ALL=C rsync" --info=progress2,name0 --no-inc-recursive --outbuf=L --archive --no-owner --no-group --protect-args \
    --chmod=u+rwX,go-rwx --timeout=120 \
    --exclude='/.git/' --exclude='/var/cache/' --exclude='/var/log/' --exclude='/var/sessions/' \
    --exclude='/node_modules/' \
    -e "ssh -F $clone_tmp/config" \
    "clone-source:${SOURCE_SHOP_PATH%/}/" "$clone_source/" \
    2> "$clone_tmp/error" | php "$script_dir/progress.php" rsync "[2/7] Copying files" || copy_status=$?
if [[ $copy_status != 0 ]] && ! php "$script_dir/check-rsync-error.php" "$copy_status" "$clone_tmp/error"; then
    cp "$clone_tmp/error" "$clone_data/transfer-error.log"
    clone_fail 'File transfer failed; check path, SSH/rsync and available disk space.'
fi
[[ -f $clone_source/composer.lock && -f $clone_source/vendor/autoload.php && -f $clone_source/bin/console ]] \
    || clone_fail 'Copied project is incomplete (possibly external symlinks).'
clone_progress_start '[3/7] Validating copied files'
# Links should now be materialized; remove leftover broken links from an earlier attempt.
if ! php "$script_dir/validate-copy.php" "$clone_source"; then
    clone_fail 'Copied project validation failed.'
fi

clone_progress_done
clone_progress_start '[4/7] Creating source database dump'
if ! clone_ssh "php -d display_errors=0 -d log_errors=0 -- '$clone_encoded_path'" \
    < "$script_dir/source-database.php" 2> "$clone_tmp/error" | gzip -1 > "$clone_archive"; then
    cp "$clone_tmp/error" "$clone_data/transfer-error.log"
    clone_fail 'Source dump failed. Check DATABASE_URL, dump privileges, InnoDB tables and disk space.'
fi
clone_progress_done
# gzip validates its checksum while streaming the restore; no second full read.
printf '%s\n' '[5/7] Restoring clone database into MariaDB...'
local_mysql -e 'CREATE DATABASE shopware_clone CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci' \
    2> "$clone_tmp/error" || clone_fail 'Could not create the local clone database.'
# --binary-mode disables mysql client commands in the input dump.
clone_progress_start "[5/7] Executing SQL in local database"
if ! { gzip -dc "$clone_archive" | local_mysql --binary-mode=1 shopware_clone > /dev/null; } 2> "$clone_tmp/error"; then
    clone_fail 'MariaDB restore failed (for example an incompatible source collation). No shop services were started.'
fi
clone_progress_done
tables=$(local_mysql -e "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='shopware_clone' AND table_name IN ('product','sales_channel_domain','system_config')" 2> "$clone_tmp/error")
[[ $tables == 3 ]] || clone_fail 'Restored database does not contain the expected Shopware tables.'
rm -f -- "$clone_archive"
printf '%s\n' '{"schema_version":1,"phase":"imported","ready":false,"database":"shopware_clone"}' > "$clone_data/state.json.next"
mv "$clone_data/state.json.next" "$clone_data/state.json"
clone_success=1
printf '%s\n' 'Files and database imported. Shop remains stopped until local configuration is applied.'
