# SupportDesk testing

## Database safety

Backend tests must use the isolated MySQL database `amc_platform_test`. Several integration tests reset that test schema. Never point them at development or production data.

Before running tests, inspect effective Laravel configuration, including configuration cache and `DB_URL`. With the test environment below, verify `app.env=testing`, `database.default=mysql`, the configured and resolved database names are `amc_platform_test`, and no database URL overrides the connection. If configuration is cached, clear it before rechecking. Supply test database credentials locally; do not commit `.env.testing`.

## Automated checks

From `backend-overlay`:

```bash
APP_ENV=testing DB_CONNECTION=mysql DB_DATABASE=amc_platform_test DB_URL= php artisan test
```

Add `--filter=TestClassName` for focused changes. The shared test base rejects an incorrect environment, connection, or database name.

From `frontend`:

```bash
npm ci
node --test tests/*.test.mjs
npm run build
```

Use a Node.js version supporting the tests’ TypeScript imports. The build runs TypeScript checking and Vite production compilation. A bundle-size warning is distinct from a failed build.

## Browser and integration checks

- Open frontend and API using the same server hostname or LAN IP with ports 5173 and 8000 during development.
- Confirm preflight returns the exact trusted frontend origin and allows credentials; untrusted origins must not receive a usable allow-origin response.
- Confirm `/sanctum/csrf-cookie` sets `XSRF-TOKEN` and the session cookie. The following login POST must send cookies and `X-XSRF-TOKEN`; omitting CSRF must be rejected.
- Test valid/invalid login, required MFA, recovery, logout, and subsequent authenticated requests. Verify another LAN device separately.
- Check Super Admin cannot access business data; Contractor Admin cannot access technical tools; customers cannot cross tenant boundaries; technicians see assigned work only.
- Check customer-user asset assignments, ticket ownership, customer/category validation, and internal-comment privacy.
- Check work reports, reassignment, notification ownership, queue delivery, search/filter scopes, and CSV spreadsheet-formula protection.
- Verify production builds do not expose demo fixtures or secrets. Environment values prefixed `VITE_` are public build inputs.

## Latest local baseline

Before documentation consolidation: 136 backend tests passed (758 assertions), all five frontend test files passed, and the production build passed with a large-bundle warning. The stale contract test label was corrected to the existing `Contracts` navigation label. These results do not verify deployment or production browser behavior.

## Portable Compose release validation

The deployment host needs only Docker Engine/Desktop, BuildKit/buildx and Compose; PHP, Node.js and Python are not host installation requirements. The source commands above are for development environments.

Validate without printing resolved environment secrets:

```sh
docker compose --env-file .env.example config --quiet
docker compose --env-file .env.example -f infra/docker-compose.yml config --quiet
docker compose build app web
```

On an isolated Docker host/project, follow README clean installation steps using disposable volumes and a trusted test certificate. Verify service health, actual HTTPS CSRF cookies/login/MFA, role scopes, upload access checks, queue processing and scheduler heartbeat. Recreate the stack without deleting volumes and verify the database, uploads and original key persist. Rehearse an upgrade against restored test data; confirm the setup helper refuses existing configuration. Also test certificate renewal/reload and a full backup/restore.

Current Docker-preparation regression: 139 backend tests passed (781 assertions), all five frontend test files passed, and the production frontend build passed with a large-chunk warning. Both Compose entry points pass Docker Compose 2.39.4 validation; installer key/credential consistency, HTTPS origin validation and overwrite refusal pass; PHP helper and shell syntax checks pass. Full image builds were attempted but blocked because the workspace has no Docker daemon/buildx. No container runtime, Linux/Windows install, TLS login, or data persistence test is claimed complete. Run the build and isolated lifecycle checks before publishing this Docker release. Major dependency tags are version-line pinned; resolved image digests should be recorded after successful builds.

## UI consistency checks

The UI uses one stylesheet entry (`frontend/src/styles/index.css`), theme tokens,
shared primitives in `frontend/src/components/ui`, and the existing searchable
select, sortable table, drawer, and feedback components. Conventions are in
`AGENTS.md`. Thin borders/focus rings are exceptions to the 4px spacing grid.

Run the frontend checks and production build from the repository root:

```sh
node --experimental-strip-types --test frontend/tests/*.test.mjs
npm run build --prefix frontend
```

Optional browser regression runners are `frontend/tests/browser/ui-pages.cjs`
, `frontend/tests/browser/ui-interactions.cjs`, and
`frontend/tests/browser/ui-records.cjs`. They require Playwright with
Chromium installed in a separate tooling directory; set `PLAYWRIGHT_MODULE` to
that installation's `playwright` package path if it is not on Node's module path.
Start a **local-only** Vite production preview on port 4173, then run each script
with Node. `UI_BASE_URL` overrides the preview URL and `UI_REPORT_DIR` overrides
the output directory (the default is the OS temporary directory).

These runners intercept API traffic with explicit test fixtures. They test all
five role navigation sets at 1440×1000 and 390×844 in light/dark themes, plus
form selection/validation payloads, menu placement, dialog keyboard behavior,
System Support tabs, entity drawers, category forms, customer/category asset filtering,
and MFA/recovery layouts. They never authenticate against
or write records to the development database. Their fixture coverage does not
prove real SMTP, live MFA delivery, file persistence, or production deployment.
Disabled Maintenance is reviewed in source; the runners do not enable it.
Laravel regressions must still run separately against the verified
`amc_platform_test` database as specified above.

The UI consistency pass was validated with 12 frontend test files, the production
build, and 167 Laravel tests (1234 assertions). Mocked browser checks passed:
164 role/page layouts, 64 interactions, and 36 entity/form checks. Live SMTP,
real MFA delivery, file persistence, production deployment, and disabled
Maintenance runtime behavior were not exercised. Selected screenshots were
manually reviewed; automated visits do not imply every possible UI state was
visually inspected.

## Final review checks

From `frontend`, run `npm run typecheck`, `npm test`, and `npm run build`. Unused local/parameter checks are enabled. Browser runners are optional tooling; set `PLAYWRIGHT_MODULE` to an installed Playwright module and `UI_BASE_URL` to a local Vite/preview server. `tests/browser/ui-feedback-maintenance.cjs` checks toast dismissal, maintenance response races, timeouts and retry recovery. All browser runners intercept API traffic and must be reported separately from real backend regression results.

Notification regression tests verify delivery privacy and current-access checks after reassignment, including unread counts and mark-read. They also compare the previous count/list query pattern with the authorized list: both use two queries, with results bounded to 30 records. No latency improvement is asserted.

Before Laravel tests, verify effective `mysql` / `amc_platform_test`, empty `DB_URL`, and uncached configuration. If the development installation is down, isolate test maintenance state using `APP_MAINTENANCE_DRIVER=cache APP_MAINTENANCE_STORE=array`; do not run `artisan up` against the development installation just to run tests.

Run `vendor/bin/pint --test` from `backend-overlay` for the full formatting baseline. The final review found pre-existing repository-wide formatting differences; focused formatting checks pass for the changed notification model, service and test. Do not describe the full formatting check as passing.

## Isolated Docker staging verification

The local `installation/staging/` configuration is Git-ignored and uses project `supportdesk_staging`, loopback ports 8080/8443, independent credentials and named volumes. It does not use development data. Real production-image checks passed for six healthy services, fresh migrations/catalog initialization, HTTPS CSRF/login/session restoration for five roles, queued ticket notifications/internal-note isolation, and database backup restored to a separate staging restore-test database (5 users, 1 ticket). The local certificate was explicitly accepted by browser automation; public certificate trust, real SMTP delivery, MFA device enrollment, attachment scanning and full upload/restore coverage remain separate checks.

The build exposed two corrected issues: transient PECL download failures now have bounded retries in a separately cached layer; `.dockerignore` explicitly excludes host runtime caches/storage and dependencies even under allowed source directories. Never copy host `bootstrap/cache` manifests into a production image.
