#!/usr/bin/env bash
set -euo pipefail
image=$1
trap 'docker rm -f -v clone-ci-failure >/dev/null 2>&1 || true' EXIT
docker run -d --name clone-ci-failure --network none \
    -e SOURCE_URL=https://source.example.org -e CLONE_URL=https://clone.example.org \
    -e SOURCE_SSH_HOST=invalid/host "$image" > /dev/null
for ((attempt=0; attempt<30; attempt++)); do
    if docker exec clone-ci-failure test -f /var/lib/shopware-clone/startup-failed; then break; fi
    sleep 1
done
docker exec clone-ci-failure test -f /var/lib/shopware-clone/startup-failed
test "$(docker inspect --format '{{.State.Running}}' clone-ci-failure)" = true
docker logs clone-ci-failure 2>&1 | grep -q 'Container stays available for diagnosis'
docker exec clone-ci-failure bash -c '! pgrep -x apache2 && ! pgrep -x cron && ! pgrep -x supervisord'
docker stop --time 3 clone-ci-failure > /dev/null
test "$(docker inspect --format '{{.State.ExitCode}}' clone-ci-failure)" = 0
echo 'Failed startup stays accessible without shop services and stops cleanly: OK'
