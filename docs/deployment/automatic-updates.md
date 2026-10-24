# Automatic application security updates

Prepared for Docker Compose deployments; **not activated on GitHub or any client server**. The update scripts and workflows must first be reviewed, pushed and merged. They never update host PHP/Node, other applications, database/Redis/scanner services, business data or application secrets.

## Update flow

1. Dependabot checks Composer and npm dependencies daily and opens update PRs, including compatible Laravel/React fixes. Major-version upgrades remain manual. Enable GitHub Dependabot alerts and security updates as well; security fixes are not always ordinary version updates.
2. Optional dependency auto-merge accepts only patch/minor PRs from Dependabot, targeting `main`, modifying only Composer/npm manifests and lockfiles. An active main ruleset must require all three CI checks and an up-to-date branch. The privileged metadata workflow never checks out or executes PR code.
3. CI also runs daily at 03:17 UTC. It explicitly refreshes PHP 8.4 and Node 24 to the latest available releases within those supported lines, checks minimum security versions, audits dependencies, and runs tests. Exact resolved PHP/Node versions are recorded and used as Docker build inputs. If a matching official image is not yet available, building fails rather than using an untested runtime. Node builds the React assets; it is not installed in the production web container.
4. After backend/frontend checks and both image builds succeed, an opt-in step publishes those exact locally built images to GHCR. It does not rebuild between validation and publishing, and does not upload large image archives to Actions artifact storage. It rejects an obsolete main revision. Published images carry matching release/revision, application-source and migration-manifest labels.
5. An optional server timer checks hourly, with up to ten minutes of jitter. It only updates app, worker, scheduler and web to an intact matching image pair from the configured repository. It pins immutable local image IDs for each operation. Application-source or schema changes, older release IDs, missing labels, and incomplete image publication stop the update. This permits runtime/dependency changes, while feature/UI/schema releases require a reviewed manual deployment and a new baseline.
6. Failed container startup or public HTTPS readiness triggers an attempted rollback to the saved image IDs. Previously rejected releases are not retried automatically. The updater never runs migrations, regenerates keys, flushes caches, pulls source code or prunes shared Docker resources.

This is not immediate protection when a vulnerability is discovered. A compatible fix must exist and pass validation; daily discovery/CI, PR processing and server polling introduce delay. If the fix needs a major upgrade, an unavailable runtime image or changes to application/schema code, automatic deployment stops for operator review. Enable workflow/security notifications and monitor failed timer executions. Passing tests does not prove the absence of every vulnerability.

## One-time GitHub setup

After this change is reviewed and merged into the default `main` branch:

- Enable Dependency graph, Dependabot alerts and Dependabot security updates in repository security settings.
- Keep an active main ruleset requiring a PR, up-to-date branch and `Laravel tests`, `Frontend tests and build`, `Docker release build`. Keep bypasses empty. Enable repository **Allow auto-merge** only if enabling the dependency auto-merge option.
- In Settings → Secrets and variables → Actions → Variables, set `SUPPORTDESK_AUTO_RELEASES=true`. Set `SUPPORTDESK_AUTO_DEPENDENCY_MERGE=true` only when you want compatible dependency PRs merged after required checks. Otherwise they await your review.
- Run the Tests workflow from `main`. Confirm all checks and the image-publishing step succeed. GHCR paths will be `ghcr.io/<lowercase-owner>/<lowercase-repository>/app:tested` and `/web:tested`.

The workflow needs package publishing permitted for `GITHUB_TOKEN`. Server access needs a separate read-only registry credential if packages are private. Authenticate Docker interactively on the server with a credential limited to reading these packages; do not place it in repository files or `updates.env`. Restrict package write access to trusted maintainers and CI: release labels are consistency guards, not cryptographic attestations.

## One-time server setup

Run from the reviewed release checkout on the **client Docker host**, using the deployment's existing project name, Compose file, environment file and data volumes. Back up the current release/configuration and data using the existing production backup procedure. No server commands below have been run by this agent.

```sh
sudo install -d -m 0755 /usr/local/libexec /etc/supportdesk
sudo install -m 0755 scripts/auto-update-containers.sh /usr/local/libexec/supportdesk-auto-update
sudo install -m 0644 infra/compose.auto-updates.yaml /etc/supportdesk/images.compose.yaml
sudo install -m 0600 infra/systemd/supportdesk-updates.env.example /etc/supportdesk/updates.env
sudoedit /etc/supportdesk/updates.env
```

Replace the example paths, repository and HTTPS origin with the actual values. `SUPPORTDESK_COMPOSE_ENV_FILE` means the existing Compose substitution file, not the Laravel application secrets file. No account/SMTP/database passwords belong in this configuration. `docker`, `curl`, GNU `sort`, and `flock` must be available. Environment variables are read by systemd; the updater does not execute a configuration file.

**Deploy the first labelled image pair as a reviewed manual baseline before enabling the timer.** Verify it matches the deployed application and schema, and perform the normal sign-in/MFA/ticket smoke tests. Existing locally built images without CI labels deliberately cannot bootstrap unattended updates. An ordinary manual release (or any changed source/schema manifest) needs the same reviewed baseline process. Use the additional `/etc/supportdesk/images.compose.yaml` override consistently for subsequent container deployments.

Validate configuration without pulling or changing containers:

```sh
sudo bash -c 'set -a; . /etc/supportdesk/updates.env; set +a; /usr/local/libexec/supportdesk-auto-update --check'
```

Once the reviewed labelled baseline is healthy and registry access works, install and activate the timer:

```sh
sudo install -m 0644 infra/systemd/supportdesk-auto-update.service /etc/systemd/system/
sudo install -m 0644 infra/systemd/supportdesk-auto-update.timer /etc/systemd/system/
sudo systemctl daemon-reload
sudo systemctl start supportdesk-auto-update.service
sudo systemctl enable --now supportdesk-auto-update.timer
sudo systemctl list-timers supportdesk-auto-update.timer
sudo journalctl -u supportdesk-auto-update.service -n 50 --no-pager
```

Run the first service command separately and check its exit status/logs. If it fails, investigate before running the timer enable command. Check the public `/api/v2/health/ready` endpoint and real sign-in/MFA/email behavior after activation. A single Compose deployment may briefly interrupt requests during recreation; this is not a zero-downtime release system. Health checks cannot detect every functional regression.

Stop future automatic changes with:

```sh
sudo systemctl disable --now supportdesk-auto-update.timer
```

If an update is already executing, allow its deployment/rollback to finish and inspect service status. Image maps and the last successful/rejected release are retained under `/var/lib/supportdesk-updates/`. Retained images and signature/database volumes must be included in normal disk monitoring. Do not run global prune or delete application volumes on a shared host. Rollback failure requires an operator; inspect the retained image map and service logs.

## Local verification

`python3 scripts/tests/test_auto_update_containers.py` uses fake Docker/curl executables and temporary files. It covers a read-only dry run, matching releases, rejection of validation/source/schema/release mismatches, scoped immutable-ID deployment and rollback on startup/readiness failures. These mocks do not prove a real production rollout or GHCR permission configuration.

References: [Dependabot updates](https://docs.github.com/en/code-security/how-tos/secure-your-supply-chain/secure-your-dependencies/configure-version-updates), [Node refresh behavior](https://github.com/actions/setup-node/blob/main/docs/advanced-usage.md), [PHP refresh behavior](https://github.com/shivammathur/setup-php/blob/main/README.md).

Validation completed locally: 258 Laravel tests (1,836 assertions) passed on `amc_platform_test`; four updater test methods covering nine mock scenarios passed; Bash/YAML and Compose-override validation passed. Timer units were checked using temporary copies pointing at the existing script; nothing was installed. Both staging images rebuilt and staging services updated; all seven services were healthy and readiness returned HTTP 200. GitHub-hosted execution, package publishing permissions and the client-server rollout remain unverified until the one-time setup is performed.
