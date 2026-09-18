#!/usr/bin/env bash
set -euo pipefail
set +x
umask 077
data=/var/lib/shopware-clone
root=$data/source
scripts=/opt/shopware-live-clone
# shellcheck source=scripts/progress.sh
source "$scripts/progress.sh"
trap clone_progress_stop EXIT
[[ -n ${CLONE_URL:-} && -n ${SOURCE_URL:-} ]] || { echo 'Set CLONE_URL (Coolify target URL) and SOURCE_URL.' >&2; exit 78; }
exec 8> "$data/startup.lock"
flock -n 8 || { echo 'A clone startup is already running.' >&2; exit 1; }
rm -f /var/www/container.launched

if [[ ! -f $data/state.json ]]; then
    "$scripts/import-source.sh"
fi
unset SOURCE_SSH_PRIVATE_KEY SOURCE_SSH_KNOWN_HOSTS
sudo install -d -o mysql -g mysql /var/run/mysqld
if ! pgrep -x mysqld > /dev/null; then sudo rm -f /var/run/mysqld/mysqld.sock.lock; fi
sudo service mysql start > /dev/null 2>&1
phase=$(jq -r '.phase' "$data/state.json")
if [[ $phase == imported ]]; then
    source_php=$(jq -r '.php.selected' "$data/source-report.json")
    [[ $source_php =~ ^8\.[2345]$ ]] || { echo 'Unsupported source PHP version.' >&2; exit 1; }
    clone_progress_start "[6/7] Configuring clone with PHP $source_php"
    "php$source_php" "$scripts/configure-clone.php"
    clone_progress_done
    phase=configured
fi
[[ $phase == configured || $phase == ready ]] || { echo 'Incomplete import/configuration. Inspect private clone logs; create fresh volumes to retry.' >&2; exit 1; }
[[ $(jq -r '.url' "$data/runtime.json") == "${CLONE_URL%/}" ]] || { echo 'CLONE_URL changed. Use a new clone for a different URL.' >&2; exit 1; }
php_version=$(jq -r '.php' "$data/runtime.json")
[[ $php_version =~ ^8\.[2345]$ ]] || { echo 'Unsupported PHP version.' >&2; exit 1; }

# Known local overrides only; original live credentials are never exported here.
# shellcheck disable=SC1091
source "$data/runtime.sh"
export PHP_VERSION=$php_version APP_ENV=prod APP_DEBUG=0
export APACHE_DOCROOT="$root/public"
# Domain changes are handled per channel by our configurator, not Dockware's bulk rewrite.
export SHOP_DOMAIN=localhost SW_TASKS_ENABLED=0 SUPERVISOR_ENABLED=1
export RECOVERY_MODE=0 FILEBEAT_ENABLED=0
unset DOCKWARE_CI

if [[ $phase == configured ]]; then
    printf '%s\n' 'Preparing copied Shopware (logs are stored privately)...'
    cd "$root"
    run_console() {
        clone_progress_start "[7/7] Shopware $1"
        if ! "php$php_version" bin/console "$@" --no-interaction >> "$data/setup.log" 2>&1; then
            printf 'Command %s failed. Details: /var/lib/shopware-clone/setup.log\n' "$1" >&2
            exit 1
        fi
        clone_progress_done
    }
    run_console cache:clear
    run_console assets:install
    run_console theme:compile
    run_console messenger:setup-transports
    if jq -e '.search or .admin_search' "$data/runtime.json" > /dev/null; then
        endpoint=$(jq -r '.search_endpoint' "$data/runtime.json")
        clone_progress_start "[7/7] Waiting for local search service"
        search_ready=0
        for ((attempt=0; attempt<90; attempt++)); do
            if curl --fail --silent --max-time 3 "$endpoint/_cluster/health?wait_for_status=yellow&timeout=1s" > /dev/null; then search_ready=1; break; fi
            sleep 2
        done
        [[ $search_ready == 1 ]] || { echo 'Local search service did not become ready.' >&2; exit 1; }
        clone_progress_done
        if jq -e '.search' "$data/runtime.json" > /dev/null; then
            run_console es:index --no-queue
            run_console es:create:alias
        fi
        if jq -e '.admin_search' "$data/runtime.json" > /dev/null; then run_console es:admin:index --no-queue; fi
    fi
    printf '%s\n' '{"schema_version":1,"phase":"ready","ready":true,"database":"shopware_clone"}' > "$data/state.json.next"
    mv "$data/state.json.next" "$data/state.json"
fi

# Provision process definitions on every container creation, without touching shop data.
sudo cp "$scripts/clone-worker.conf" /etc/supervisor/conf.d/clone-worker.conf
crontab "$scripts/clone-crontab"
cd "$root"
printf 'Clone setup complete after %ss of this startup. Starting Dockware, cron and queue workers; waiting for health check...\n' "$SECONDS"
exec /bin/bash /entrypoint.sh
