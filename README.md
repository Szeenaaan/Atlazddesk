# Atlazddesk

Atlazddesk is a portable, self-hosted support and maintenance workspace for contractors and their customers. It manages tickets, assets, AMC contracts, assignments, work reports, notifications, and account security.

Read [ROLES.md](ROLES.md) for permissions and [TESTING.md](TESTING.md) for validation. This source tree includes the complete application; old overlay installers are not required.

Read [Runtime security](docs/deployment/runtime-security.md) for patched runtime requirements and existing-server update instructions.

## Docker Compose release

`compose.yaml` runs seven services (six normally active):

| Service | Purpose | Persistence |
|---|---|---|
| `web` | Production React build and Nginx HTTPS, routing `/api/*` and `/sanctum/*` to PHP-FPM | External TLS certificate directory, read-only |
| `app` | Laravel/PHP-FPM | Private uploads and application logs |
| `worker` | Redis queue worker for notifications, maintenance, and default jobs | Same private upload/log volumes |
| `scheduler` | Laravel scheduler | Same private upload/log volumes |
| `database` | MariaDB 11.4 | `database_data` volume |
| `redis` | Redis cache, sessions, and queues with AOF persistence | `redis_data` volume |
| `setup` | Optional clean-install configuration generator (`tools` profile) | External `installation/` directory |

PHP 8.4, Composer, Node.js, and the production asset build are inside multi-stage images. Hosts do not need PHP, Node.js, Python, a PHP-FPM socket, or application systemd services. The app, worker, and scheduler share one release image. There are no code bind mounts or development servers.

MariaDB, Redis, and PHP-FPM have no host port mappings. The private backend network isolates data services; application containers also have an outbound network for mail and other configured integrations. Only the web ports are published. Private uploads are downloaded through authenticated Laravel endpoints, never served directly by Nginx.

Health checks cover MariaDB, Redis, PHP-FPM plus database/cache connectivity, the worker process plus dependencies, the scheduler heartbeat, and Nginx. The worker check does not prove that a particular job has completed; monitor failed jobs and delivery separately.

`infra/docker-compose.yml` is a compatibility include of the root release. Use root `docker compose` commands below. Older `infra/nginx/` and `infra/systemd/` files describe manual host deployments and are not used by this release.

## Supported host requirements

The release targets **Linux containers on x86-64**:

- Linux: a maintained distribution supported by [Docker Engine](https://docs.docker.com/engine/install/), Docker Engine 24+ with BuildKit/buildx and Docker Compose plugin 2.24+.
- Windows: a currently serviced Windows 11 x64 edition/build supported by [Docker Desktop](https://docs.docker.com/desktop/setup/install/windows-install/), Docker Desktop using the WSL 2 backend and **Linux containers**, virtualization enabled, and WSL meeting Docker’s current requirements. Windows hosts need at least Docker Desktop’s documented 8 GB RAM requirement.
- **Windows 7 cannot directly run current Docker Desktop.** Use a supported remote Linux host, or a separately maintained Linux VM with its own supported Docker Engine. Docker Desktop on Windows Server and native Windows containers are not supported by this release.
- ARM64, rootless engines, alternate runtimes, and multi-host orchestration have not been validated and are outside this release’s supported target.
- Suggested initial Linux capacity: 2 CPU cores, 4 GB RAM, and 20 GB available disk, plus space for uploads, database growth and backups; size from actual load. Windows needs additional host/WSL resources. These are planning figures, not benchmarked limits.
- Internet access for initial image/dependency downloads, stable disk for named volumes, a hostname reachable by clients, trusted HTTPS certificates for that hostname, and access to configured SMTP services.

A Docker-accessible user can read container environment secrets; restrict Docker access. Protect `installation/` and backup files with host permissions/ACLs. Compose env files keep secrets out of images and Git but are not a dedicated secrets vault.

## Linux installation

Install Engine, buildx, and the Compose plugin using Docker’s instructions for your actual distribution; no Ubuntu-specific installation is required. Obtain this repository/release, open its root, and verify:

```sh
docker version
docker compose version
docker buildx version
cp .env.example .env
mkdir -p installation/tls backups
chmod 700 installation backups
```

Edit the root `.env`: choose a unique `COMPOSE_PROJECT_NAME`, set `PUBLIC_HOST` to the hostname only, and `APP_ORIGIN` to its complete HTTPS origin. Set host binding/ports if the defaults conflict. For example, if exposing port 8443, include `:8443` in `APP_ORIGIN`. Keep the project name unchanged after initialization because it identifies the persistent volumes.

Validate and build; these commands need no host language tools:

```sh
docker compose config --quiet
docker compose build app web
docker compose run --rm --no-deps --user "$(id -u):$(id -g)" setup
```

The user override makes the generated private files readable by the Linux operator, rather than root-owned. The helper creates independent random credentials and a new application key **only for an empty installation**; it refuses to replace any existing installation env file. Do not run it during upgrades or when importing an existing installation.

Continue with the common configuration/start steps below.

## Windows installation (PowerShell)

Install Docker Desktop for a supported Windows version, enable WSL 2/Linux containers, and start Docker Desktop. Check its current requirements and license terms using the link above. Obtain the source as a checkout or release archive. In PowerShell at the repository root:

```powershell
docker version
docker compose version
docker buildx version
Copy-Item .env.example .env
New-Item -ItemType Directory -Force installation/tls, backups
```

Edit root `.env` as described for Linux. Use Windows ACLs to limit access to `installation` and `backups`. Then:

```powershell
docker compose config --quiet
docker compose build app web
docker compose run --rm --no-deps setup
```

No Bash, Python, Node.js, or PHP installation is needed on Windows. Use named volumes for database/uploads rather than Windows source-folder binds. The repository enforces LF endings for container shell scripts. Continue with the same common commands below, which work in PowerShell and Linux shells.

## Installation configuration and first start

The generated, Git-ignored files are:

- `installation/app.env`: stable `APP_KEY`, Laravel settings, database/Redis credentials, mail settings, exact CORS origin, and Sanctum host/port.
- `installation/database.env`: matching MariaDB bootstrap credentials.
- `installation/redis.env`: matching Redis credential.
- `installation/tls/fullchain.pem` and `privkey.pem`: supply a trusted certificate chain and matching key obtained through your certificate provider or an external ACME client/container. Certificates are not generated into the image or committed. Renew them externally and reload/recreate `web`.

Configure real SMTP in `app.env`. Contractor-managed notification/MFA providers are also configured in the application; environment SMTP alone does not replace those policies. If changing credentials, update all corresponding files; changing MariaDB bootstrap variables does **not** rotate users in an existing database.

Keep `APP_URL` and `CORS_ALLOWED_ORIGINS` equal to the complete HTTPS origin and `SANCTUM_STATEFUL_DOMAINS` equal to hostname plus any nondefault port, without a scheme. Keep host-only secure cookies (`SESSION_DOMAIN=null`, `SESSION_SECURE_COOKIE=true`, `SESSION_SAME_SITE=lax`). Clients all open the same HTTPS URL from any network; their individual IPs are not CORS origins. The production frontend uses same-origin `/api/v2` and `/sanctum/csrf-cookie`. Do not disable CSRF or use wildcard origins with credentials.

If adding env values containing `$`, spaces, or `#`, use Compose env-file quoting rules (single quotes for literal values) and never put secrets in `VITE_` settings. `docker compose config` without `--quiet` can reveal resolved secrets; do not share its output.

After installing TLS and reviewing the settings:

```sh
docker compose up -d --wait database redis
docker compose run --rm --no-deps app php artisan migrate --force
docker compose run --rm --no-deps app php artisan db:seed --force
docker compose run --rm --no-deps app php artisan amc:create-admin
docker compose up -d --wait --wait-timeout 240
docker compose ps
```

Run `db:seed` only on a clean installation to initialize catalogs, priorities, statuses, and service defaults. It is not an upgrade step: it can overwrite customized catalog values. Demo users remain disabled in production. Initial administrator creation is interactive; do not put its password in command arguments.

Open the configured HTTPS origin and complete account security setup. Check real login/CSRF/MFA, role boundaries, uploads, queued notifications and scheduler activity before admitting users. Application health/readiness endpoints are `/api/v2/health/live` and `/api/v2/health/ready`. Local configuration validation does not certify production operation.

## Existing installations and safe upgrades

Never regenerate an existing `APP_KEY`: encrypted mail settings, MFA secrets, and other encrypted values require the original key. Keep it with protected backups. Preserve database credentials, cookie/cache/Redis prefixes, and all required settings. The application entrypoint only validates configuration and builds a per-container config cache; it never generates keys, runs migrations, or seeds data.

For importing an existing non-Compose installation, take a verified backup, place its original Laravel settings/key into `installation/app.env`, and change only service endpoints to `DB_HOST=database` and `REDIS_HOST=redis`. Configure matching service env files. Restore the database and private uploads into the new named volumes before running migrations; account for pending queues/session state when moving Redis. Do not run the clean-install helper or reseed business data. This migration requires a planned maintenance window and restore rehearsal.

For an existing Compose installation:

1. Back up configuration/key, database, Redis state, private uploads, and any needed logs, and verify a restore. Record the current release and volume names.
2. Obtain and inspect the new source/release. Preserve the same root `.env`, `COMPOSE_PROJECT_NAME`, external installation files and TLS directory. Change `RELEASE` for the new image version; do not change the project name or volume mapping.
3. Run `docker compose config --quiet` and `docker compose build app web` before maintenance. Review migration compatibility.
4. Stop web traffic and background mutation: `docker compose stop web worker scheduler app`. Stop other import/write processes too. Take a final consistent backup if needed.
5. Keep the database/Redis available (`docker compose up -d --wait database redis`), then run `docker compose run --rm --no-deps app php artisan migrate --force`.
6. Run `docker compose up -d --wait --wait-timeout 240`, check health, and perform browser/queue/scheduler smoke checks. Recreated application containers pick up environment changes automatically.

Never use `migrate:fresh`, `db:wipe`, `docker compose down -v`, or volume pruning for upgrades. Do not upgrade database major versions blindly. MariaDB and Redis are pinned to compatible version lines; patch-tag contents can change, so promote verified image digests for reproducible distribution. An application rollback does not undo schema changes: restore the matching verified data backup when migrations are not backward compatible.

## Backup and restore

Quiesce all web/worker/scheduler writes for a consistent release backup, and keep backups outside the Docker host too. `docker compose down` without `-v` keeps volumes, but does not replace a backup. Example commands (Linux or PowerShell) avoid host-shell redirection of binary data:

```sh
docker compose exec database sh -ec 'MYSQL_PWD="$MARIADB_ROOT_PASSWORD" mariadb-dump --user=root --single-transaction --routines --events "$MARIADB_DATABASE" > /tmp/atlazddesk.sql'
docker compose cp database:/tmp/atlazddesk.sql ./backups/database.sql
docker compose run --rm --no-deps --entrypoint tar app -czf /var/www/html/storage/logs/uploads-backup.tgz -C /var/www/html/storage/app/private .
docker compose cp app:/var/www/html/storage/logs/uploads-backup.tgz ./backups/uploads.tgz
docker compose exec redis sh -ec 'REDISCLI_AUTH="$REDIS_PASSWORD" redis-cli SAVE'
docker compose cp redis:/data/dump.rdb ./backups/redis-snapshot.rdb
```

The upload archive is written to the shared logs volume so the `app` container can copy it out even though the temporary backup container exits. These filenames overwrite prior backups; use a new dated backup directory for each run. Remove temporary archives from container storage after securely copying them. Back up `installation/` and root `.env` using the host’s secure file backup process; do not print secrets into logs.

Restore into a separate project/volume set first: import the SQL using the MariaDB container client, restore uploads with a one-off app/tar container, and restore Redis using the database vendor’s documented procedure. A Redis RDB snapshot must not be blindly copied into a volume containing newer AOF files: Redis may replay AOF instead. Preserve or restore a consistent Redis AOF volume, or initialize a clean Redis volume from the snapshot with an explicit recovery plan. Verify encryption using the original APP_KEY and test pending jobs/session behavior. Never replace live volumes without a verified recovery procedure.

## Contract configuration

Contractor Admin can create contracts from Contracts and edit existing contracts from their detail drawer. Contract type is a frontend dropdown stored in the existing `contract_type` field; existing custom values remain selectable. Choose an SLA policy, inspect its response/resolution targets, and explicitly select covered services. The Contracts page and editor provide Manage SLA policies & covered services for creating and updating the existing catalogs. No new table is used for contract types.

Catalogs can be marked inactive to remove them from new selection while preserving existing links. No policy or service is automatically attached to a new contract. A contract without an SLA link uses the existing ticket-creation fallback policy. SLA targets use elapsed minutes, not business-hour calendars, and policy changes do not recalculate existing ticket deadlines. Contracts and catalog management remain Contractor Admin only.

## Source maintenance

- `backend-overlay/`: Laravel source, migrations, and tests.
- `frontend/`: React/TypeScript source, tests, npm lockfile and production build inputs.
- `infra/docker/`: container configuration and install helper.
- `Dockerfile`, `.dockerignore`, `compose.yaml`: release build/runtime definition.

Real env files, installation secrets/certificates, uploads, logs, caches, dependencies, and builds stay outside Git and the image context. See [TESTING.md](TESTING.md) for source tests and container validation status.

## Application maintenance screen

From `backend-overlay`, `php artisan down` blocks business requests and displays the frontend maintenance screen on every SPA entry URL. `php artisan up` restores access. Open browsers check availability every 15 seconds and on focus, and switch immediately on an API maintenance response. The public `/api/maintenance-status` endpoint exposes only a boolean and remains reachable while down. Do not use a prerendered `artisan down --render` response with this availability check.

This is separate from the optional scheduled-work Maintenance page, which appears for Contractor Admin when `AMC_MAINTENANCE_ENABLED=true` in the backend environment (run `php artisan config:clear` after changing it).

See [Secure ticket uploads](docs/deployment/ticket-upload-security.md) for scanner configuration, deployment checks and rollback.

See [Automatic application updates](docs/deployment/automatic-updates.md) for opt-in tested runtime/dependency updates and one-time server activation.

Browse the [documentation index](docs/README.md) for deployment, UI and verification guides. Local historical files are organized under ignored `notes/local/`.
