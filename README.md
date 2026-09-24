# Shopware Live Clone

Create disposable Shopware 6 test environments from existing installations over SSH.

The project packages the source files, database, plugins, media, runtime configuration,
workers, cron jobs, Redis, and search into an isolated Coolify deployment. The resulting
environment runs on [Dockware](https://github.com/dockware/shopware) and uses the public
image `ghcr.io/aggrosoft/shopware-live-clone:main`.

> [!WARNING]
> This is not a security sandbox. Copied plugins and custom integrations can still contain
> production credentials or call production APIs. Protect every clone with an access
> control layer or VPN and review integrations before triggering application workflows.

## Supported sources

| Component | Supported configuration |
|---|---|
| Shopware | 6.6 and 6.7 |
| PHP | 8.2 through 8.5 |
| Source database | MariaDB with InnoDB tables |
| Source storage | Local filesystem or standard Shopware `amazon-s3` Flysystem configuration |
| Search | Shopware search configuration redirected to OpenSearch 2.19 |
| Target platform | Linux/AMD64 |

The source project must contain `composer.lock`, `bin/console`, and `vendor/`. The importer
does not install Composer dependencies or update Shopware and its plugins.

## Services

| Service | Purpose |
|---|---|
| `shop` | Dockware runtime, importer, Apache, PHP, cron, queue workers, and MailCatcher |
| `database` | MariaDB 11.4 database named `shopware_clone` |
| `redis` | Redis 7.4 for copied Redis connections |
| `opensearch` | OpenSearch 2.19.4 for storefront and administration search |

MariaDB, Redis, and OpenSearch are only attached to the internal Compose network and do
not publish host ports. The shop addresses them through clone-specific network aliases so
service names from another clone cannot collide. OpenSearch uses a 512 MiB Java heap. The
Docker host must provide `vm.max_map_count >= 262144`.

> [!IMPORTANT]
> Keep Coolify's **Connect To Predefined Network** option disabled for this stack. When it
> is enabled, Coolify attaches every Compose service to the shared destination network after
> startup, including MariaDB, Redis, and OpenSearch. The proxy reaches the public `shop`
> service through the resource-specific network and does not require this option.

## Deploying with Coolify

### 1. Prepare source access

The SSH user on the source server needs read access to the complete Shopware project and
database credentials referenced by its environment. The source host must provide:

- SSH
- PHP CLI 7.4 or newer
- `rsync`
- `mariadb` or `mysql`
- `mariadb-dump` or `mysqldump`

Create a dedicated SSH key on the Coolify host and add its public key to the source
hosting account. Store the private key only in the Coolify environment configuration.

### 2. Create the resource

1. Create a Docker Compose resource in Coolify.
2. Use the repository's [`compose.yaml`](compose.yaml).
3. Under **Configuration > Advanced**, leave **Connect To Predefined Network** disabled.
4. Assign an HTTPS domain to service `shop` on container port `80`.
5. Ensure the server-level Traefik middleware `authentik-forward-auth@file` exists; the Compose file applies it to the public clone route by default.
6. Configure the required environment variables below.
7. Deploy and follow the `shop` service logs.

The GHCR image is public. No registry credentials are required.

### 3. Configure the environment

| Variable | Required | Description |
|---|---:|---|
| `SOURCE_SSH_HOST` | yes | SSH hostname of the source server |
| `SOURCE_SSH_PORT` | no | SSH port; defaults to `22` |
| `SOURCE_SSH_USER` | yes | SSH user on the source server |
| `SOURCE_SSH_PRIVATE_KEY` | yes | Multiline private key without an interactive passphrase |
| `SOURCE_SSH_KNOWN_HOSTS` | no | Verified OpenSSH host-key entry; use `[host]:port` for non-standard ports |
| `SOURCE_SHOP_PATH` | yes | Absolute Shopware project root, not its `public/` directory |
| `SOURCE_URL` | yes | Exact primary sales-channel URL, including scheme and optional path |
| `SSH_PASSWORD` | yes | Password assigned to the clone's `dockware` user |

`CLONE_URL`, `SERVICE_FQDN_SHOP`, and `SERVICE_FQDN_SHOP_80` are populated by Coolify and
must not be copied from the live environment. The clone URL must differ from `SOURCE_URL`.

If `SOURCE_SSH_KNOWN_HOSTS` is omitted, the importer uses OpenSSH's `accept-new` policy and
stores the first observed host key in the `clone_data` volume. Later key changes are
rejected. Supplying `SOURCE_SSH_KNOWN_HOSTS` enables strict verification from the first
connection.

## Import lifecycle

The first container start performs the following phases:

1. Inspect the source version, PHP configuration, runtime dependencies, and service usage.
2. Copy the project with `rsync`, materializing valid symlink targets.
3. Validate the copied project.
4. Create a consistent, single-transaction database dump on the source host.
5. Restore the unmodified dump into the local MariaDB service.
6. Anonymize standard customer data and rewrite the runtime configuration.
7. Clear caches, install assets, compile themes, prepare queues, and build search indexes.

Only after all phases succeed does the container start Dockware, cron, and two queue
workers for the `async` and `low_priority` transports. The failure transport is not
processed automatically.

Progress is written to the container log. File transfer includes byte counts, throughput,
percentage, and an estimate for that phase. Long-running database and Shopware operations
emit a heartbeat every 15 seconds. Detailed command output is kept in private files under
`/var/lib/shopware-clone`.

Subsequent container starts reuse the imported files and database. They do not contact the
source, replace domains, clear queues, or rebuild indexes unless a pending migration
requires it.

## Clone isolation

The configurator applies the following changes to the copied environment:

| Area | Clone behavior |
|---|---|
| Database | All standard Doctrine configuration points to local MariaDB |
| Domains | The primary source URL becomes `CLONE_URL`; other domains receive paths below `/__clone/`. Every clone domain is also mirrored onto the internal `http://shop` origin for Docker-network browser checks. |
| Redirects | Copied root and public `.htaccess` redirect rules are replaced |
| Redis | Redis DSNs are mapped to isolated logical databases on local Redis |
| Search | Enabled storefront and administration search use local OpenSearch |
| Mail | Standard Symfony and Shopware mail configuration uses local MailCatcher |
| Messenger | Transports use the local Doctrine queue; copied pending messages are removed |
| Scheduled tasks | `queued` and `running` tasks are reset to `scheduled` |
| Storage and CDN | Standard Flysystem storage becomes local; CDN URLs and the standard Fastly key are removed |

Original configuration files are retained under
`/var/lib/shopware-clone/original-config` for diagnosis. They may contain production
secrets and must not be exposed or copied into support tickets without review.

These rewrites cover standard Shopware and Symfony configuration. Plugin-specific database
connections, HTTP clients, payment providers, ERP integrations, mail clients, CDN purge
hooks, and other custom services are not guaranteed to be isolated.

## S3 and CDN media

For standard `amazon-s3` Flysystem storage, the importer resolves the bucket, region,
endpoint, prefix, and credentials from the copied Shopware configuration. It uses
`rclone copy` to import:

- `media/` and `thumbnail/` from public storage
- the complete private storage

The source operation uses LIST, HEAD, and GET requests. It does not sync, move, overwrite,
or delete source objects. Temporary S3 credentials are removed after the copy.

The `public`, `private`, `temp`, `theme`, `asset`, and `sitemap` filesystems are then
configured as local storage. A CDN backed by files already present on the source server
does not require an additional download; its public URL is removed and assets are rebuilt
locally.

Unsupported storage adapters or plugin-owned S3 clients stop automatic configuration
rather than retaining a known live Flysystem target.

## Data anonymization

Anonymization runs once per clone against the local database. It changes standard fields
in `customer`, `customer_address`, `order_customer`, and `order_address`, including guest
orders and versioned order records:

- email addresses become unique `kunde-<id>@example.invalid` values
- names and postal addresses receive deterministic test values
- company, phone, title, department, address additions, VAT IDs, birthdays, and customer
  IP addresses are cleared where present

IDs, associations, countries, states, line items, and monetary values remain unchanged.
The marker `/var/lib/shopware-clone/anonymized-v1.json` prevents a restart from replacing
data created during testing.

This is targeted anonymization, not a complete privacy scrub. Documents, generated PDFs,
custom fields, free text, logs, media, and plugin tables may still contain personal data.

## SSH access

The Compose service includes labels for an existing
[SSH Piper](https://github.com/tg123/sshpiper) deployment:

```text
sshpiper.username=${SERVICE_FQDN_SHOP}
sshpiper.container_username=dockware
sshpiper.port=22
sshpiper.network=${COMPOSE_PROJECT_NAME}
```

With an SSH Piper endpoint at `sftp.dev.example.com:2222`, connect using the clone FQDN as
the external username:

```bash
ssh <clone-fqdn>@sftp.dev.example.com -p 2222
```

Authentication uses `SSH_PASSWORD`. The `${COMPOSE_PROJECT_NAME}` label selects Coolify's
resource network when the container is attached to multiple networks.

## Operations

### Health and status

The shop becomes healthy only after the import and configuration are complete, Dockware
has started, both queue workers are running, cron is active, and Apache answers locally.

```bash
docker compose ps -a
docker compose logs -f shop
docker compose exec shop jq . /var/lib/shopware-clone/state.json
docker compose exec shop /opt/shopware-live-clone/healthcheck.sh
```

MailCatcher is available at `/mailcatcher`. Dockware also exposes `/adminer` and `/logs`.
Apply the same external access control to the entire clone domain, including these paths.

### Failures

If import or preparation fails, the `shop` container remains running for inspection while
Apache, cron, and workers remain stopped. Coolify will report it as unhealthy or degraded.

```bash
docker compose exec shop bash
docker compose exec shop sh -c \
  'cat /var/lib/shopware-clone/*error.log /var/lib/shopware-clone/setup.log 2>/dev/null'
```

Relevant files include:

| Path | Contents |
|---|---|
| `source-report.json` | Redacted source inspection result |
| `state.json` | Current import/configuration state |
| `runtime.json` | Selected PHP version, clone URL, and search state |
| `domain-map.json` | Source-to-clone sales-channel URL mapping |
| `import-error.log` | File or database import failure |
| `configure-error.log` | Configuration rewrite failure |
| `storage-import.log` | S3 copy output |
| `setup.log` | Cache, asset, theme, queue, and index commands |
| `worker.log` | Queue worker output |
| `scheduler.log` | Scheduled-task output |

A failed file copy can resume while no dump or local clone database exists. An interrupted
or failed database restore is never overwritten automatically. Create a new Coolify
resource, or remove the failed disposable resource and all of its volumes, before retrying
such an import.

### Updating the image

For image-only updates, pull and recreate the shop service:

```bash
docker compose pull shop
docker compose up -d --force-recreate shop
```

Changes to `compose.yaml` require updating the Coolify Compose definition and redeploying
the full resource. Never reuse a MySQL 8 `clone_db` volume with the current MariaDB service.

### Deleting a clone

Each resource owns four volumes:

| Volume | Contents |
|---|---|
| `clone_data` | Copied shop, configuration backups, state, and logs |
| `clone_db` | MariaDB data |
| `clone_redis` | Redis data |
| `clone_opensearch` | Search indexes |

Delete these volumes together with the disposable resource. Do not configure fixed external
volume names or share clone volumes between resources.

## Import constraints

- Files and database are copied sequentially and do not form one atomic snapshot. Avoid
  deployments and schema changes on the source during the first import.
- All source tables must use InnoDB. Non-transactional tables are rejected instead of
  locking production tables.
- The compressed SQL dump is stored temporarily in `clone_data`. Provision enough space
  for the source files, compressed dump, and restored database.
- `.git`, `node_modules`, Shopware caches, logs, and sessions are excluded from file copy.
- Valid symlink targets are copied as files or directories. Broken source links are skipped
  with a warning; unreadable files remain fatal.
- The database dump is restored without SQL substitutions or compatibility rewrites.
  Unsupported collations and incompatible SQL fail visibly.
- Only one standard Doctrine database connection is imported automatically.
- PHP selection is inferred from a Hetzner-style `.htaccess` handler where possible and
  otherwise falls back to source CLI PHP. Hosting-panel overrides cannot be detected.
- Literal dotenv values, simple variable references, and literal Symfony
  `.env.local.php` arrays are supported. Arbitrary PHP configuration is never executed.
- Changing `CLONE_URL` for an existing clone is not supported. Create a new clone instead.

## Image entry points

The image accepts the following commands:

| Command | Behavior |
|---|---|
| no argument | Run the complete import or start an existing clone |
| `--check-image` | Validate required tools and supported PHP runtimes |
| `--detect-source` | Produce the redacted source report without importing data |
| `--import-source` | Import files and database without starting Shopware |

Example image validation:

```bash
docker run --rm ghcr.io/aggrosoft/shopware-live-clone:main --check-image
```

## Development

Build and validate the image locally:

```bash
docker build -t shopware-live-clone:dev .
docker run --rm shopware-live-clone:dev --check-image
```

Static and unit checks:

```bash
for script in scripts/*.sh tests/*.sh; do bash -n "$script"; done
shellcheck -x scripts/*.sh tests/*.sh

php tests/detect-source-test.php
php tests/database-config-test.php
php tests/clone-config-test.php
php tests/anonymize-test.php
php tests/storage-test.php
php tests/clone-url-test.php
python3 tests/progress-test.py
```

GitHub Actions additionally validates the Compose file, runs synthetic SSH/import and
failure-mode tests, and boots an official Shopware 6.7.10.0 fixture with MariaDB, Redis,
OpenSearch, MailCatcher, workers, and source-independent restart coverage. Images are
published only after the complete workflow succeeds.

Published tags:

- `main`
- `sha-<commit>`
- `v*` release tags
