#!/usr/bin/env bash
# CI runner only: creates disposable containers; never connects to user shops.
set -euo pipefail
image=$1
fixture=$(mktemp -d)
cleanup() {
    local result=$?
    if [[ $result != 0 ]]; then
        df -h /
        docker logs clone-ci-db 2>&1 | tail -n 30 || true
        docker logs clone-ci-search 2>&1 | tail -n 30 || true
        docker logs clone-ci-target 2>&1 | tail -n 100 || true
        docker inspect --format '{{json .State.Health}}' clone-ci-target || true
        docker exec clone-ci-target sh -c 'sudo supervisorctl status; tail -n 40 /var/lib/shopware-clone/source/var/log/*.log /var/log/apache2/error.log 2>/dev/null' || true
        for log in setup.log configure-error.log anonymize-error.log import-error.log worker.log transfer-error.log; do
            if docker cp "clone-ci-target:/var/lib/shopware-clone/$log" "$fixture/$log" 2>/dev/null; then
                tail -n 100 "$fixture/$log"
                rm -f "$fixture/$log"
            fi
        done
    fi
    # Exact, task-owned ephemeral containers; no user resources exist on this CI runner.
    docker rm -f -v clone-ci-target clone-ci-source clone-ci-db clone-ci-redis clone-ci-search >/dev/null 2>&1 || true
    docker network rm clone-ci >/dev/null 2>&1 || true
    rm -f "$fixture/key" "$fixture/key.pub" "$fixture/target.env"
    rmdir "$fixture"
    return "$result"
}
trap cleanup EXIT
docker network create clone-ci
sudo sysctl -w vm.max_map_count=262144
docker run -d --name clone-ci-db --network clone-ci --network-alias database \
    -e MARIADB_ROOT_PASSWORD=root -e MARIADB_ROOT_HOST=% mariadb:11.4
docker run -d --name clone-ci-redis --network clone-ci --network-alias redis redis:7.4
docker run -d --name clone-ci-search --network clone-ci --network-alias opensearch \
    -e discovery.type=single-node -e DISABLE_SECURITY_PLUGIN=true -e DISABLE_INSTALL_DEMO_CONFIG=true \
    -e 'OPENSEARCH_JAVA_OPTS=-Xms512m -Xmx512m' opensearchproject/opensearch:2.19.4
docker run -d --name clone-ci-source --network clone-ci dockware/shopware:6.7.10.0
wait_healthy() {
    local name=$1
    local started_checks=0
    for ((attempt=0; attempt<180; attempt++)); do
        if [[ $(docker inspect --format '{{.State.Health.Status}}' "$name") == healthy ]]; then return; fi
        if [[ $(docker inspect --format '{{.State.Running}}' "$name") != true ]]; then docker logs "$name"; return 1; fi
        if docker exec "$name" test -f /var/lib/shopware-clone/startup-failed; then docker logs "$name"; return 1; fi
        if [[ $(docker inspect --format '{{.State.Health.Status}}' "$name") == unhealthy ]] && docker exec "$name" test -f /var/www/container.launched; then
            started_checks=$((started_checks + 1))
            # Startup may already have exhausted Docker's short CI grace period.
            # Give newly started FPM and supervisor workers a full minute to settle.
            if ((started_checks >= 12)); then return 1; fi
        fi
        sleep 5
    done
    docker logs "$name"
    return 1
}
wait_healthy clone-ci-source
for ((attempt=0; attempt<60; attempt++)); do
    if docker exec clone-ci-db healthcheck.sh --connect --innodb_initialized; then break; fi
    if ((attempt == 59)); then docker logs clone-ci-db; exit 1; fi
    sleep 2
done
ssh-keygen -q -t ed25519 -N '' -f "$fixture/key"
docker exec -i -u dockware clone-ci-source sh -c 'cat >> /var/www/.ssh/authorized_keys; chmod 600 /var/www/.ssh/authorized_keys' < "$fixture/key.pub"
docker exec clone-ci-source sudo chmod 755 /var/www
docker exec clone-ci-source sudo chmod 700 /var/www/.ssh
host_key=$(docker exec clone-ci-source cat /etc/ssh/ssh_host_ed25519_key.pub)
docker cp scripts/source-database.php clone-ci-source:/tmp/source-database.php
docker cp tests/prepare-real-source.php clone-ci-source:/tmp/prepare-real-source.php
docker exec clone-ci-source php /tmp/prepare-real-source.php
docker exec clone-ci-source php -r '$_SERVER["SCRIPT_FILENAME"] = "/tmp/ci-dump-check.php"; require "/tmp/source-database.php"; dumpSource("/var/www/html");' > /dev/null

export SOURCE_SSH_PRIVATE_KEY
SOURCE_SSH_PRIVATE_KEY=$(< "$fixture/key")
docker run -d --name clone-ci-target --network clone-ci \
    --health-start-period=10s --health-interval=10s \
    -e SOURCE_SSH_HOST=clone-ci-source -e SOURCE_SSH_USER=dockware \
    -e SOURCE_SSH_PRIVATE_KEY -e "SOURCE_SSH_KNOWN_HOSTS=clone-ci-source $host_key" \
    -e SOURCE_SHOP_PATH=/var/www/html -e SOURCE_URL=http://localhost -e CLONE_URL=http://clone.example.org \
    -e CLONE_DATABASE_HOST=database -e APP_ENV=dev -e APP_DEBUG=1 \
    "$image"
wait_healthy clone-ci-target
test "$(docker exec clone-ci-target php -r '$env = require "/var/lib/shopware-clone/source/.env.local.php"; echo ($env["APP_ENV"] ?? "") . ":" . ($env["APP_DEBUG"] ?? "");')" = 'dev:1'
test "$(docker exec clone-ci-target sh -c 'find /var/lib/shopware-clone/source -maxdepth 1 -type f -name ".env*" -printf "%f\n" | sort')" = '.env.local.php'
docker exec clone-ci-target test ! -e /var/lib/shopware-clone/original-config
docker exec clone-ci-target test -f /var/lib/shopware-clone/source/config/packages/dev/zzzz_clone.yaml
if docker exec clone-ci-target grep -Eq '^export APP_(ENV|DEBUG)=' /var/lib/shopware-clone/runtime.sh; then
    echo 'runtime.sh must not persist APP_ENV or APP_DEBUG' >&2
    exit 1
fi
docker exec clone-ci-target curl -fsS -o /dev/null -H 'Host: clone.example.org' http://127.0.0.1/
test "$(docker exec -e MYSQL_PWD=root clone-ci-target mysql --no-defaults --protocol=TCP -h database -u root -N -B shopware_clone -e "SELECT COUNT(*) FROM sales_channel_domain WHERE url='http://shop'")" = 1
docker exec clone-ci-target curl -fsS -o /dev/null -H 'Host: shop' http://127.0.0.1/
docker exec clone-ci-target sudo supervisorctl status 'clone-worker:*'
docker exec clone-ci-target crontab -l
test "$(docker exec -e MYSQL_PWD=root clone-ci-target mysql --no-defaults --protocol=TCP -h database -u root -N -B shopware_clone -e "SELECT COUNT(*) FROM messenger_messages WHERE body='LIVE_QUEUE_SENTINEL'")" = 0
test "$(docker exec -e MYSQL_PWD=root clone-ci-source mysql --no-defaults --protocol=TCP -h 127.0.0.1 -u root -N -B shopware -e "SELECT COUNT(*) FROM messenger_messages WHERE body='LIVE_QUEUE_SENTINEL'")" = 1
docker exec clone-ci-target /opt/shopware-live-clone/run-console.sh mailer:test clone-ci@example.org
docker exec clone-ci-target curl -fsS http://127.0.0.1:1080/messages | grep -q clone-ci@example.org
docker exec clone-ci-target sh -c 'printf retained > /var/lib/shopware-clone/source/clone-ci-marker'
# Emulate a persisted clone created by an older image that forced prod.
docker exec clone-ci-target sh -c "printf '%s\n' 'export APP_ENV=prod' 'export APP_DEBUG=0' >> /var/lib/shopware-clone/runtime.sh"
docker exec clone-ci-target php -r '$path = "/var/lib/shopware-clone/source/.env.local.php"; $env = require $path; $env["APP_ENV"] = "prod"; $env["APP_DEBUG"] = "0"; file_put_contents($path, "<?php\nreturn " . var_export($env, true) . ";\n");'
docker exec clone-ci-target rm -f /var/lib/shopware-clone/source/config/packages/dev/zzzz_clone.yaml
docker stop clone-ci-source
docker restart clone-ci-target
wait_healthy clone-ci-target
test "$(docker exec clone-ci-target cat /var/lib/shopware-clone/source/clone-ci-marker)" = retained
test "$(docker exec clone-ci-target php -r '$env = require "/var/lib/shopware-clone/source/.env.local.php"; echo ($env["APP_ENV"] ?? "") . ":" . ($env["APP_DEBUG"] ?? "");')" = 'dev:1'
docker exec clone-ci-target test -f /var/lib/shopware-clone/source/config/packages/dev/zzzz_clone.yaml
if docker exec clone-ci-target grep -Eq '^export APP_(ENV|DEBUG)=' /var/lib/shopware-clone/runtime.sh; then
    echo 'runtime.sh must not persist APP_ENV or APP_DEBUG' >&2
    exit 1
fi
docker exec clone-ci-target curl -fsS -o /dev/null -H 'Host: clone.example.org' http://127.0.0.1/
echo 'Real Shopware import, OpenSearch indexing, Redis, storefront, mail capture, workers and restart without source: OK'
