#!/usr/bin/env bash
# Runs only inside an ephemeral CI container. No live credentials or network hosts.
set -euo pipefail
umask 077
fixture=$(mktemp -d /tmp/clone-integration.XXXXXXXX)
shop="$fixture/shop with spaces"
mkdir -p "$shop/bin" "$shop/vendor" "$shop/public/media" "$shop/custom/plugins/Example" "$shop/var/cache"
printf '%s\n' '{"packages":[{"name":"shopware/core","version":"v6.7.1.0","require":{"php":"~8.3.0"}}]}' > "$shop/composer.lock"
printf '%s\n' '<?php throw new Exception("NEVER EXECUTE SOURCE CODE");' > "$shop/bin/console"
cp "$shop/bin/console" "$shop/vendor/autoload.php"
printf '%s\n' 'AddHandler application/x-httpd-php83 .php' > "$shop/public/.htaccess"
printf '%s\n' 'plugin fixture' > "$shop/custom/plugins/Example/plugin.txt"
printf '%s\n' 'media fixture' > "$shop/public/media/test.txt"
printf '%s\n' 'do not copy cache' > "$shop/var/cache/excluded.txt"
printf '%s\n' "DATABASE_URL='mysql://root:root@127.0.0.1/live_fixture'" > "$shop/.env"

sudo install -d -o mysql -g mysql /var/run/mysqld
sudo service mysql start
export MYSQL_PWD=root
mysql --no-defaults --protocol=SOCKET -u root <<'SQL'
CREATE DATABASE live_fixture CHARACTER SET utf8mb4;
CREATE TABLE live_fixture.product (id INT PRIMARY KEY, name VARCHAR(100)) ENGINE=InnoDB;
INSERT INTO live_fixture.product VALUES (1, 'Original live product');
CREATE TABLE live_fixture.sales_channel_domain (id INT PRIMARY KEY, url VARCHAR(100)) ENGINE=InnoDB;
INSERT INTO live_fixture.sales_channel_domain VALUES (1, 'https://live.example.org');
CREATE TABLE live_fixture.system_config (id INT PRIMARY KEY, configuration_value TEXT) ENGINE=InnoDB;
INSERT INTO live_fixture.system_config VALUES (1, 'LIVE_SECRET_SENTINEL');
SQL
unset MYSQL_PWD

ssh-keygen -q -t ed25519 -N '' -f "$fixture/client_key"
ssh-keygen -q -t ed25519 -N '' -f "$fixture/host_key"
sudo install -d -m 0755 /run/sshd
sudo /usr/sbin/sshd -p 22222 -h "$fixture/host_key" \
    -o "PidFile=$fixture/sshd.pid" -o "AuthorizedKeysFile=$fixture/client_key.pub" \
    -o PasswordAuthentication=no -o StrictModes=no -o AllowUsers=dockware
export SOURCE_SSH_HOST=127.0.0.1 SOURCE_SSH_PORT=22222 SOURCE_SSH_USER=dockware
export SOURCE_SHOP_PATH="$shop"
SOURCE_SSH_PRIVATE_KEY=$(< "$fixture/client_key")
SOURCE_SSH_KNOWN_HOSTS="[127.0.0.1]:22222 $(< "$fixture/host_key.pub")"
export SOURCE_SSH_PRIVATE_KEY SOURCE_SSH_KNOWN_HOSTS

# A mismatched host key must fail before any data is imported.
ssh-keygen -q -t ed25519 -N '' -f "$fixture/wrong_key"
if SOURCE_SSH_KNOWN_HOSTS="[127.0.0.1]:22222 $(< "$fixture/wrong_key.pub")" \
    /opt/shopware-live-clone/import-source.sh > "$fixture/rejected.log" 2>&1; then
    printf '%s\n' 'Host-key mismatch was incorrectly accepted.' >&2; exit 1
fi
test ! -e /var/lib/shopware-clone/state.json

# A supplied, correct key still works with strict checking.
probe_source() (
    # shellcheck source=scripts/ssh-common.sh
    source /opt/shopware-live-clone/ssh-common.sh
    trap clone_ssh_cleanup EXIT
    clone_ssh_init
    clone_ssh true
)
probe_source

# First contact without a supplied key persists trust across new SSH sessions.
unset SOURCE_SSH_KNOWN_HOSTS
probe_source
trust=/var/lib/shopware-clone/ssh/known_hosts
test -s "$trust"
ssh-keygen -F '[127.0.0.1]:22222' -f "$trust" > /dev/null
test "$(stat -c %a "$trust")" = 600
cp "$trust" "$fixture/learned_hosts"
probe_source
cmp "$trust" "$fixture/learned_hosts"

# A different key for that already known endpoint must not be accepted/overwritten.
printf '[127.0.0.1]:22222 %s\n' "$(< "$fixture/wrong_key.pub")" > "$trust"
cp "$trust" "$fixture/mismatched_hosts"
if probe_source > "$fixture/changed-host.log" 2>&1; then
    printf '%s\n' 'Changed host key was incorrectly accepted.' >&2; exit 1
fi
grep -q 'REMOTE HOST IDENTIFICATION HAS CHANGED' "$fixture/changed-host.log"
cmp "$trust" "$fixture/mismatched_hosts"
cp "$fixture/learned_hosts" "$trust"

/opt/shopware-live-clone/import-source.sh > "$fixture/import.log" 2>&1 || { cat "$fixture/import.log"; exit 1; }
jq -e '.phase == "imported" and .ready == false' /var/lib/shopware-clone/state.json
cmp "$shop/custom/plugins/Example/plugin.txt" /var/lib/shopware-clone/source/custom/plugins/Example/plugin.txt
cmp "$shop/public/media/test.txt" /var/lib/shopware-clone/source/public/media/test.txt
test ! -e /var/lib/shopware-clone/source/var/cache/excluded.txt
test ! -e /var/lib/shopware-clone/database.sql.gz
export MYSQL_PWD=root
test "$(mysql --no-defaults -u root -N -B -e 'SELECT name FROM shopware_clone.product WHERE id=1')" = 'Original live product'
test "$(mysql --no-defaults -u root -N -B -e 'SELECT name FROM live_fixture.product WHERE id=1')" = 'Original live product'
mysql --no-defaults -u root -e "UPDATE shopware_clone.product SET name='Keep my test changes' WHERE id=1"
unset MYSQL_PWD
/opt/shopware-live-clone/import-source.sh >> "$fixture/import.log" 2>&1
export MYSQL_PWD=root
test "$(mysql --no-defaults -u root -N -B -e 'SELECT name FROM shopware_clone.product WHERE id=1')" = 'Keep my test changes'
unset MYSQL_PWD
if grep -q 'LIVE_SECRET_SENTINEL' "$fixture/import.log"; then exit 1; fi
if pgrep -x apache2 >/dev/null || pgrep -x cron >/dev/null || pgrep -x supervisord >/dev/null; then exit 1; fi
printf '%s\n' 'SSH file transfer, transactional dump, local restore and repeat-import protection: OK'
