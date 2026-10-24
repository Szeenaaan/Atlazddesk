# Secure ticket uploads

Ticket uploads require `AMC_ATTACHMENTS_ENABLED=true` and a reachable private ClamAV service. Every supported file (PDF, PNG, JPEG, TXT, DOCX, XLSX; maximum 10 MB) is scanned synchronously before private storage, attachment records, audit events or notifications are created. This security decision must finish before the upload is accepted; it is not an email job.

Existing authenticated session, MFA, customer/assigned-technician ticket scopes, CSRF and write throttling remain in force. Uploads additionally allow six attempts per minute per real actor, including delegated workspaces. Downloads recheck current ticket access, force attachment delivery as binary content, prohibit caching and disable content sniffing. Uploaded files are not publicly served.

Only an explicit clean scanner verdict permits storage. Detection returns a sanitized 422. Connection failures, scan errors and timeouts return a sanitized 503 without storing the file. Encrypted documents and archives and excessive archive expansion/recursion are rejected. The scanner refuses to start with signatures more than seven days old; monitor signature-update failures during operation. Existing uploads are not retrospectively scanned.

The Compose scanner uses a digest-pinned official image, automatic signature updates, a persistent signature volume, bounded concurrency and a 4 GB memory limit. Port 3310 is never published. Budget sufficient host RAM for this service in addition to the application/database. Malware scanning reduces risk; it cannot guarantee that a document is safe.

## Production operator steps

These steps have not been run on production. Back up the current Compose file, application environment file and deployed release first. Preserve all existing secrets and data volumes. Deploy the matching source, `compose.yaml` and `infra/docker/clamd.conf` together.

From the repository root, inspect configuration without printing interpolated secrets:

```sh
docker compose --env-file installation/compose.env config --quiet
docker compose --env-file installation/compose.env pull clamav
docker compose --env-file installation/compose.env up -d clamav
docker compose --env-file installation/compose.env ps clamav
```

Wait until ClamAV is healthy; first startup may take several minutes. In `installation/app.env`, set only these upload settings, preserving the remaining configuration:

```dotenv
AMC_ATTACHMENTS_ENABLED=true
AMC_SCANNER_HOST=clamav
AMC_SCANNER_PORT=3310
```

Build and update the matching application release:

```sh
docker compose --env-file installation/compose.env build app
docker compose --env-file installation/compose.env up -d --wait --wait-timeout 180 app worker scheduler
docker compose --env-file installation/compose.env ps
```

Use your actual Compose env-file path if it differs. No database migration is required. Verify the public readiness endpoint and a small authorized document upload/download. Verify an unrelated customer cannot retrieve it. Do not disable scanning to work around a failed check. To stop accepting new uploads while investigating, set `AMC_ATTACHMENTS_ENABLED=false` and recreate app/worker/scheduler. Existing private downloads retain their authorization checks.

For future engine updates, review and test a supported official ClamAV release, then update the image digest deliberately. Signature updates do not require an application rebuild.

Protocol and image references: https://docs.clamav.net/manual/Usage/ClamdProtocol.html and https://docs.clamav.net/manual/Installing/Docker.html.
