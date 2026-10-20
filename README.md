# Atlazddesk

Atlazddesk is a self-hosted support and maintenance workspace for service contractors and their customers. It manages tickets, assets, AMC contracts, technician assignments, work reports, notifications, and account security.

## Source layout

- `backend-overlay/`: Laravel 12/PHP API, authentication, jobs, migrations, and tests.
- `frontend/`: React, TypeScript, and Vite application.
- `infra/`: example Nginx, systemd, and local container configuration.
- `scripts/`: maintenance and validation tooling. Historical patch installers and duplicate payloads are excluded from Git; current application source is authoritative.

Read [ROLES.md](ROLES.md) for permissions and [TESTING.md](TESTING.md) for validation.

## Local setup

Requires PHP 8.2 or newer with the extensions needed by Laravel and MySQL, Composer, a current Node.js version supporting TypeScript test imports, npm, and MariaDB/MySQL. Redis is needed when selected for queues, cache, or sessions.

1. In `backend-overlay`, run `composer install`, copy `.env.example` to `.env` if no local environment exists, and run `php artisan key:generate` for a new installation only.
2. Configure `DB_CONNECTION=mysql`, database host/name, and credentials in `.env`. Create a dedicated application database and a separate `amc_platform_test` database for tests. Do not commit credentials.
3. Review migrations, back up existing installations, then run `php artisan migrate`. Never run `migrate:fresh` against business data. Demo fixtures are opt-in and must stay disabled in production.
4. Provision the initial technical administrator with `php artisan amc:create-admin your-admin@example.com`. The technical administrator provisions the contractor administrator; see [ROLES.md](ROLES.md).
5. In `frontend`, run `npm ci` and copy `.env.example` to `.env` if needed. Keep `VITE_DEMO_MODE=false` for real API use.

Start the development servers in separate terminals:

```bash
cd backend-overlay
php artisan serve --host=0.0.0.0 --port=8000
```

```bash
cd frontend
npm run dev
```

Open `http://<server-host>:5173`. In development, the Axios client uses the browser’s protocol and hostname with port `8000` and `/api/v2`. Production uses the website’s own origin with `/api/v2`. Use the same hostname or LAN IP for the frontend and API. The development API must listen on an interface accessible to the client.

## Authentication and LAN access

Authentication uses Sanctum session cookies, not browser-stored bearer tokens. Axios enables credentials and XSRF handling, and requests `/sanctum/csrf-cookie` before posting `/api/v2/auth/login`. Login may require further MFA verification before creating an authenticated session.

Set `CORS_ALLOWED_ORIGINS` to exact frontend origins, including scheme and port, with no trailing slash. For LAN development, add `http://<server-LAN-IP>:5173`. Trusted CORS origins are also added to Sanctum’s stateful hosts; additional `SANCTUM_STATEFUL_DOMAINS` entries use host and port without a scheme.

For same-host HTTP development, use host-only cookies (`SESSION_DOMAIN=null`), `SESSION_SECURE_COOKIE=false`, and `SESSION_SAME_SITE=lax`. Production must use HTTPS, secure cookies, and an explicit production origin allowlist. Never disable CSRF or use wildcard origins with credentials. After environment changes, run `php artisan config:clear` and restart long-running processes as needed.

## Deployment

Deployment is separate from local test success. Review `infra/nginx/` and `infra/systemd/` examples and adapt paths, hostnames, PHP-FPM version, queue configuration, and TLS settings. Production uses one HTTPS origin: `https://support.example.com` serves React, `/api/*` reaches Laravel, and `/sanctum/*` reaches Laravel’s CSRF endpoint. The Nginx example routes these paths to PHP-FPM; no public port 8000 is needed. Vite development proxies do not configure the production build.

Point the contractor’s domain to the VPS and configure its TLS certificate. Customers open that same domain from any network; do not add individual client IP addresses to CORS. Set `APP_URL=https://support.example.com`, `CORS_ALLOWED_ORIGINS=https://support.example.com`, `SANCTUM_STATEFUL_DOMAINS=support.example.com`, `SESSION_DOMAIN=null`, `SESSION_SECURE_COOKIE=true`, and `SESSION_SAME_SITE=lax`, replacing the example domain consistently. Same-origin browser requests do not require cross-origin permission, while Sanctum still needs the trusted frontend host. Rebuild frontend assets and refresh Laravel’s configuration cache after applying deployment settings.

Before release:

- Run the checks in [TESTING.md](TESTING.md), including real-browser login/MFA and role isolation.
- Install production Composer dependencies with the lockfile and build frontend assets with `npm ci` and `npm run build`.
- Configure production environment values, `APP_DEBUG=false`, TLS, explicit CORS origins, secure sessions, and real mail delivery. Keep the application key stable on existing installations.
- Back up the database and uploaded files; review and apply migrations without resetting data.
- Give the PHP process write access to Laravel storage and bootstrap cache; serve only intended public files.
- Run configured queue workers, including notification jobs, and Laravel’s scheduler. Restart workers after code changes.
- Verify health/readiness endpoints, SMTP, uploads, backups and restoration, and tenant/role boundaries in the deployed environment.

Deployment examples are starting points, not proof of production readiness. SMS delivery and upload malware scanning require separate operational review. Existing authenticator labels and stored organization/sender names may differ from the product default.

## Repository hygiene

Commit source, tests, migrations, assets, lockfiles, and sanitized environment examples. Keep real environment files, credentials, uploaded files, runtime storage, dependencies, builds, archives, backups, and machine-specific settings local. `.gitignore` lists superseded documentation individually; those local files remain available but are not part of the published documentation set.
