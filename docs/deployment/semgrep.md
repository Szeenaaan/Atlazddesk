# PHP security scanning

The `Semgrep` workflow runs the `PHP security (Semgrep CE)` check on pull requests
targeting `main`, pushes to `main`, Mondays at 04:43 UTC, and manual dispatch.
Scheduled runs and manual dispatch become available after the workflow reaches
the repository's default branch. This workflow does not deploy the application
or change branch protection.

## Coverage

Semgrep Community Edition 1.184.0 scans the full `backend-overlay` tree for PHP
files using the maintained public `p/php` registry ruleset, including its Laravel
security rules. Controllers, services, models, routes, configuration, migrations,
PHP views remain in scope. Semgrep's default exclusions and the repository's
`.gitignore` exclude dependencies, generated assets, runtime storage and caches.
The default Semgrep exclusions also skip tests. No dependencies are installed
and no application code is executed.

The scanner image is pinned by version and digest; registry rules are fetched on
each run and may change independently. Network access to the registry is required. No Semgrep
account, subscription or token is required. Metrics and version checks are off.
The GitHub token has only `contents: read`, and checkout does not persist it.
Fork contributions run through `pull_request` without repository secrets.

This is pattern and local dataflow analysis, not a complete Laravel security
audit. CE does not provide Pro cross-file or cross-function analysis. Blade
templates are not fully understood as Laravel templates. The scan does not
cover the React frontend, installed dependencies, runtime configuration,
authorization behavior or a running deployment. Keep dependency audits and
Laravel regression tests. A passing scan does not guarantee security.

## Results and failures

Open **Actions → Semgrep → a workflow run → PHP security (Semgrep CE)** and read
the **Scan PHP and Laravel source** log. Pull requests also show the check in
their checks list. Results are not uploaded to GitHub code scanning or Semgrep's
platform, so no `security-events: write` permission is required.

All rule severities fail the check. Exit code `1` indicates security findings;
other nonzero codes indicate execution, configuration or parsing failures.
`--strict` makes parser warnings fail rather than silently accepting incomplete
coverage. No baseline, automatic fixes, failure suppression or existing-finding
exemptions are configured. Review findings for false positives and actual impact.

## Local reproduction

From the repository root, with Docker available:

```bash
docker run --rm -v "$PWD:/src:ro" -w /src \
  semgrep/semgrep:1.184.0@sha256:cc1b68266f267878b39739373ea9985b9a78e5d64497dd875c8de100b22695ed \
  semgrep scan --config p/php --oss-only --error --strict --metrics off \
  --disable-version-check --max-target-bytes 0 --timeout 0 \
  --include '*.php' backend-overlay
```

No application image rebuild or staging deployment is needed for these CI files.
After reviewing a successful GitHub run, an administrator can separately choose
to require the check in the repository ruleset.

## Initial validation

On 10 November 2026, actionlint 1.7.12 accepted the workflow. The pinned Semgrep
image reported version 1.184.0. The initial local scan ran 23 rules across 271
PHP files, including 62 test files, with no scanner errors. After removing the
redundant `.semgrepignore`, the final scan ran the same 23 rules across 209 PHP
source files and exited with code `0`, with no findings or scanner errors.
No dependency, runtime storage,
bootstrap cache or generated build paths appeared in the scanned-file list.

The initial scan reported two unsafe-validator findings in `AuthController` and
`UsersController`. Review traced both ignored IDs to trusted models: the
authenticated user and a user loaded through the authorized, locked target query.
These were not confirmed SQL injection vulnerabilities. Both rules now use
Laravel's explicit `ignoreModel()` API rather than passing a scalar ID. The
subsequent scan exited with code `0`: zero findings and zero scanner errors.
No rules were disabled and no findings were suppressed.

Configuration follows the official [CE CI guide](https://docs.semgrep.dev/deployment/oss-deployment),
[CLI reference](https://docs.semgrep.dev/cli-reference) and
[ignore documentation](https://docs.semgrep.dev/ignoring-files-folders-code).
The fixed version was verified against the official
[1.184.0 release](https://github.com/semgrep/semgrep/releases/tag/v1.184.0).
