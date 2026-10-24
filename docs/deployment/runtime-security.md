# SupportDesk runtime security

Baseline verified on 23 October 2026: PHP **8.4.27** and Node.js **24.22.1**.
These are security releases: [PHP announcement](https://www.php.net/releases/8_4_27.php),
[Node.js advisory](https://nodejs.org/en/blog/vulnerability/october-2026-security-releases).
JavaScript is the language; Node.js is the runtime used to build this frontend.
The production web container serves compiled assets with Nginx and does not run Node.js.

Default local Docker builds pin these minimum versions. CI refreshes supported PHP 8.4/Node 24, records the exact resolved versions, and builds with those exact versions. Composer requires patched PHP in the 8.4 line;
npm requires patched Node.js in the 24.x line. Version checks reject older runtimes.
These minimums do not automatically track future fixes. Deployment automation is maintained on the deployment/docker-compose branch.
Do not bypass platform requirements or use `--ignore-platform-reqs` to install on an older PHP.

## Local development host

From the repository root, inspect versions without changing anything:

```sh
bash scripts/update-host-runtimes.sh --check
```

On an apt-based host with PHP 8.4 and Node.js 24 already installed:

```sh
sudo bash scripts/update-host-runtimes.sh --apply
bash scripts/update-host-runtimes.sh --check
```

The updater refreshes package metadata, verifies both repository candidates, simulates the
upgrade, backs up the installed package list and PHP configuration under
`/var/backups/supportdesk-runtimes/`, then upgrades installed runtime packages. It refuses
unpatched candidates and package removals. If repositories lack the fixed versions, use
the patched Docker images or obtain supported packages from the OS/runtime distributor.
It does not edit application secrets, regenerate keys, or run database commands.
If this host also serves PHP, restart its actual PHP service and application queue workers
during a maintenance window, and verify the serving runtime rather than CLI alone.

## Existing Docker deployment

Use the reviewed release checkout and the existing deployment's Compose file, environment
file, project name, TLS directory and volumes. Back up the database and private uploads
using the existing deployment procedure before replacing a release. Do not copy example
credentials into an existing installation or run clean-install setup commands.

For a root-Compose deployment, from that checkout:

```sh
docker compose --env-file .env config --quiet
docker compose --env-file .env build --pull app web
docker compose --env-file .env up -d --no-deps --wait app worker scheduler web
docker compose --env-file .env exec -T app php /opt/atlazddesk/check-php-version.php
docker compose --env-file .env ps
```

If the deployment uses another environment filename, substitute that existing filename
in every command. Rebuild/recreate all three PHP services so old queue workers do not
continue running the previous PHP image. Verify `/api/v2/health/ready` returns HTTP 200,
then check sign-in, MFA and email delivery on the deployed instance. Keep the previous
release images for rollback. Never use `down -v` or prune application volumes to update.

This runtime update procedure does not apply application migrations. Any pending schema
changes in a broader application release need their own reviewed migration procedure.
Source validation and local image builds do not prove that a hosted server was updated.

## Release checks

CI runs Composer/npm advisory audits, isolated Laravel tests using only
`amc_platform_test`, frontend type checking/tests/build, and both production image builds.
Advisory databases describe known issues; a clean audit is not a guarantee of security.
Keep the host OS, Docker Engine, browser, and other service images maintained separately.
