#!/usr/bin/env bash
# CI runner only: creates disposable containers; never connects to user shops.
set -euo pipefail
image=$1
fixture=$(mktemp -d)
cleanup() {
    local result=$?
    if [[ $result != 0 ]]; then
        docker logs clone-ci-target 2>&1 | tail -n 100 || true
        for log in setup.log configure-error.log worker.log transfer-error.log; do
            if docker cp "clone-ci-target:/var/lib/shopware-clone/$log" "$fixture/$log" 2>/dev/null; then
                tail -n 100 "$fixture/$log"
                rm -f "$fixture/$log"
            fi
        done
    fi
    # Exact, task-owned ephemeral containers; no user resources exist on this CI runner.
    docker rm -f -v clone-ci-target clone-ci-source clone-ci-redis clone-ci-search >/dev/null 2>&1 || true
    docker network rm clone-ci >/dev/null 2>&1 || true
    rm -f "$fixture/key" "$fixture/key.pub" "$fixture/target.env"
    rmdir "$fixture"
    return "$result"
}
trap cleanup EXIT
docker network create clone-ci
sudo sysctl -w vm.max_map_count=262144
docker run -d --name clone-ci-redis --network clone-ci --network-alias redis redis:7.4
docker run -d --name clone-ci-search --network clone-ci --network-alias opensearch \
    -e discovery.type=single-node -e DISABLE_SECURITY_PLUGIN=true -e DISABLE_INSTALL_DEMO_CONFIG=true \
    -e 'OPENSEARCH_JAVA_OPTS=-Xms512m -Xmx512m' opensearchproject/opensearch:2.19.4
docker run -d --name clone-ci-source --network clone-ci dockware/shopware:6.7.10.0
wait_healthy() {
    local name=$1
    for ((attempt=0; attempt<180; attempt++)); do
        if [[ $(docker inspect --format '{{.State.Health.Status}}' "$name") == healthy ]]; then return; fi
        if [[ $(docker inspect --format '{{.State.Running}}' "$name") != true ]]; then docker logs "$name"; return 1; fi
        sleep 5
    done
    docker logs "$name"
    return 1
}
wait_healthy clone-ci-source
ssh-keygen -q -t ed25519 -N '' -f "$fixture/key"
docker exec -i -u dockware clone-ci-source sh -c 'cat >> /var/www/.ssh/authorized_keys; chmod 600 /var/www/.ssh/authorized_keys' < "$fixture/key.pub"
docker exec clone-ci-source sudo chmod 755 /var/www
docker exec clone-ci-source sudo chmod 700 /var/www/.ssh
host_key=$(docker exec clone-ci-source cat /etc/ssh/ssh_host_ed25519_key.pub)
docker cp scripts/source-database.php clone-ci-source:/tmp/source-database.php
docker cp tests/prepare-real-source.php clone-ci-source:/tmp/prepare-real-source.php
docker exec clone-ci-source php /tmp/prepare-real-source.php
docker exec clone-ci-source php -r 'require "/tmp/source-database.php"; dumpSource("/var/www/html");' > /dev/null

export SOURCE_SSH_PRIVATE_KEY
SOURCE_SSH_PRIVATE_KEY=$(< "$fixture/key")
docker run -d --name clone-ci-target --network clone-ci \
    --health-start-period=10s --health-interval=10s \
    -e SOURCE_SSH_HOST=clone-ci-source -e SOURCE_SSH_USER=dockware \
    -e SOURCE_SSH_PRIVATE_KEY -e "SOURCE_SSH_KNOWN_HOSTS=clone-ci-source $host_key" \
    -e SOURCE_SHOP_PATH=/var/www/html -e SOURCE_URL=http://localhost -e CLONE_URL=http://clone.example.org \
    "$image"
wait_healthy clone-ci-target
docker exec clone-ci-target curl -fsS -o /dev/null -H 'Host: clone.example.org' http://127.0.0.1/
docker exec clone-ci-target sudo supervisorctl status 'clone-worker:*'
docker exec clone-ci-target crontab -l
test "$(docker exec -e MYSQL_PWD=root clone-ci-target mysql --no-defaults -u root -N -B shopware_clone -e "SELECT COUNT(*) FROM messenger_messages WHERE body='LIVE_QUEUE_SENTINEL'")" = 0
test "$(docker exec -e MYSQL_PWD=root clone-ci-source mysql --no-defaults -u root -N -B shopware -e "SELECT COUNT(*) FROM messenger_messages WHERE body='LIVE_QUEUE_SENTINEL'")" = 1
docker exec clone-ci-target /opt/shopware-live-clone/run-console.sh mailer:test clone-ci@example.org
docker exec clone-ci-target curl -fsS http://127.0.0.1:1080/messages | grep -q clone-ci@example.org
docker exec clone-ci-target sh -c 'printf retained > /var/lib/shopware-clone/source/clone-ci-marker'
docker stop clone-ci-source
docker restart clone-ci-target
wait_healthy clone-ci-target
test "$(docker exec clone-ci-target cat /var/lib/shopware-clone/source/clone-ci-marker)" = retained
docker exec clone-ci-target curl -fsS -o /dev/null -H 'Host: clone.example.org' http://127.0.0.1/
echo 'Real Shopware import, OpenSearch indexing, Redis, storefront, mail capture, workers and restart without source: OK'
