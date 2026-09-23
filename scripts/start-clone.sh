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
# Older Coolify templates combined an HTTPS scheme with the internal service port.
# Normalize that legacy value before configuring or validating a persisted clone.
if [[ $CLONE_URL =~ ^https://([^/:]+):80(/.*)?$ ]]; then
    CLONE_URL="https://${BASH_REMATCH[1]}${BASH_REMATCH[2]:-}"
    export CLONE_URL
fi
startup_exit() {
    local status=$?
    clone_progress_stop
    (( status != 0 && BASH_SUBSHELL == 0 )) || return "$status"
    trap - EXIT
    rm -f /var/www/container.launched
    printf 'Startup failed (exit %s). Container stays available for diagnosis; shop, cron and workers were not started.\n' "$status" >&2
    printf '%s\n' 'Open the Coolify terminal. Error logs: /var/lib/shopware-clone/*error.log and /var/lib/shopware-clone/setup.log' >&2
    printf '%s\n' "$status" > "$data/startup-failed"
    trap 'kill "$hold_pid" 2>/dev/null || true; exit 0' TERM INT
    sleep infinity &
    hold_pid=$!
    wait "$hold_pid"
}
trap startup_exit EXIT
exec 8> "$data/startup.lock"
flock -n 8 || { echo 'A clone startup is already running.' >&2; exit 1; }
rm -f "$data/startup-failed"
rm -f /var/www/container.launched

if [[ ! -f $data/state.json ]] || jq -e '.phase == "failed"' "$data/state.json" >/dev/null; then
    "$scripts/import-source.sh"
fi
unset SOURCE_SSH_PRIVATE_KEY SOURCE_SSH_KNOWN_HOSTS
clone_db_host=${CLONE_DATABASE_HOST:-127.0.0.1}
clone_db_port=${CLONE_DATABASE_PORT:-3306}
if [[ $clone_db_host == 127.0.0.1 || $clone_db_host == localhost ]]; then
    sudo install -d -o mysql -g mysql /var/run/mysqld
    if ! pgrep -x mysqld > /dev/null; then sudo rm -f /var/run/mysqld/mysqld.sock.lock; fi
    sudo service mysql start > /dev/null 2>&1
fi
database_ready=0
for ((attempt=0; attempt<60; attempt++)); do
    if MYSQL_PWD=root MYSQL_TEST_LOGIN_FILE=/dev/null mysql --no-defaults --protocol=TCP \
        --host="$clone_db_host" --port="$clone_db_port" --user=root --execute='SELECT 1' > /dev/null 2>&1; then
        database_ready=1
        break
    fi
    sleep 2
done
[[ $database_ready == 1 ]] || { echo 'Clone MariaDB did not become ready.' >&2; exit 1; }
php "$scripts/migrate-clone-url.php"
php "$scripts/ensure-internal-domains.php"
clone_progress_start '[6/7] Anonymizing customer and order contact details'
php "$scripts/anonymize-clone.php"
clone_progress_done
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

# Never reactivate copied live canonical-domain, alias or HTTPS redirect rules.
# This also upgrades already configured clone volumes when a new image starts.
install -m 0644 "$scripts/clone-public.htaccess" "$root/public/.htaccess"
printf '%s\n' '# Live hosting rules are disabled in the disposable clone.' > "$root/.htaccess"

# APP_ENV/APP_DEBUG belong to the container runtime. Older clone volumes persisted
# prod values in runtime.sh and only generated prod package overrides; migrate them
# on startup so an existing clone switches cleanly to dev after a redeploy.
runtime_app_env=${APP_ENV:-dev}
runtime_app_debug=${APP_DEBUG:-}
if [[ -z $runtime_app_debug ]]; then
    if [[ $runtime_app_env == dev ]]; then runtime_app_debug=1; else runtime_app_debug=0; fi
fi
runtime_env_migrated=0
if [[ -f $data/runtime.sh ]] && grep -Eq '^export APP_(ENV|DEBUG)=' "$data/runtime.sh"; then
    sed -i '/^export APP_ENV=/d; /^export APP_DEBUG=/d' "$data/runtime.sh"
    runtime_env_migrated=1
fi
if [[ -f $root/config/packages/prod/zzzz_clone.yaml && ! -f $root/config/packages/dev/zzzz_clone.yaml ]]; then
    mkdir -p "$root/config/packages/dev"
    cp "$root/config/packages/prod/zzzz_clone.yaml" "$root/config/packages/dev/zzzz_clone.yaml"
    runtime_env_migrated=1
fi

# Known local overrides only; original live credentials are never exported here.
# shellcheck disable=SC1091
source "$data/runtime.sh"
export PHP_VERSION="$php_version" APP_ENV="$runtime_app_env" APP_DEBUG="$runtime_app_debug"

# .env.local.php is copied from the live shop and used by web requests when the
# process environment is sanitized by Apache. Keep its runtime mode aligned with
# the container without changing any of the clone-specific connection rewrites.
if [[ -f $root/.env.local.php ]]; then
    "php$php_version" -r '
        $path = $argv[1];
        $env = require $path;
        if (!is_array($env)) { fwrite(STDERR, "Invalid compiled clone environment.\n"); exit(1); }
        $appEnv = getenv("APP_ENV");
        $appDebug = getenv("APP_DEBUG");
        if (($env["APP_ENV"] ?? null) === $appEnv && ($env["APP_DEBUG"] ?? null) === $appDebug) { exit(0); }
        $env["APP_ENV"] = $appEnv;
        $env["APP_DEBUG"] = $appDebug;
        $tmp = $path . ".clone-runtime";
        if (file_put_contents($tmp, "<?php\nreturn " . var_export($env, true) . ";\n") === false || !rename($tmp, $path)) { exit(1); }
    ' "$root/.env.local.php"
fi
export APACHE_DOCROOT="$root/public"
# Domain changes are handled per channel by our configurator, not Dockware's bulk rewrite.
export SHOP_DOMAIN=localhost SW_TASKS_ENABLED=0 SUPERVISOR_ENABLED=1
export RECOVERY_MODE=0 FILEBEAT_ENABLED=0
unset DOCKWARE_CI

if [[ -f $data/url-migrated || -f $data/internal-domains-migrated || $runtime_env_migrated == 1 ]]; then
    clone_progress_start '[7/7] Clearing cache after clone runtime migration'
    if ! "php$php_version" "$root/bin/console" cache:clear --no-interaction >> "$data/setup.log" 2>&1; then
        printf '%s\n' 'Cache clear after clone URL correction failed. Details: /var/lib/shopware-clone/setup.log' >&2
        exit 1
    fi
    rm -f "$data/url-migrated" "$data/internal-domains-migrated"
    clone_progress_done
fi

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
if [[ -n ${SSH_PASSWORD:-} ]]; then
    printf 'dockware:%s\n' "$SSH_PASSWORD" | sudo chpasswd
fi
cd "$root"
printf 'Clone setup complete after %ss of this startup. Starting Dockware, cron and queue workers; waiting for health check...\n' "$SECONDS"
exec /bin/bash /entrypoint.sh
