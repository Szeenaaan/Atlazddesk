# Shared-host performance review

This review targets avoidable work in dashboard reports, startup data, ticket operations, notification delivery/polling, application loading and PHP process management. It is not a production capacity or load-test certification.

## Verified changes

- Dashboard charts aggregate timestamps in two database queries for every timeframe, instead of two counts per bucket (60 queries for a monthly chart). Existing role/customer scopes, created-at report restrictions, zero-filled buckets and inclusive period-end behavior remain. Regression tests verify all six periods and resolved events exactly at period end.
- Startup still returns the latest 50 tickets, but no longer counts the entire result set for unused pagination metadata.
- Ticket attachment/work-log access checks no longer eagerly load six display relationships that these endpoints do not use.
- Notification jobs retrieve recipient IDs and roles rather than full user records and avoid loading an unused customer relationship.
- Contract-expiry selection compares the date column directly rather than wrapping it in a DATE function. Processing remains in bounded chunks.
- Notification polling pauses in hidden tabs, refreshes on visibility restoration, and avoids concurrent refresh requests in one component. Polling continues to respect session expiry and never renews idle time.
- Five administrative screens load on demand. The Vite initial JS chunk decreased from 877.81 KB (252.73 KB gzip) to 806.62 KB (235.08 KB gzip). This defers downloads; it does not remove those features or eliminate their code.
- PHP-FPM uses on-demand workers, a 10-second idle timeout, maximum five concurrent workers and recycling after 500 requests. These apply only when deploying the updated Docker image. Idle behavior does not imply a measured peak-memory reduction under load.

## Operator checks

No new database migration is required for these optimizations. Staging checks and source tests are separate from production verification. Before production rollout, retain the current release/environment backup and follow the existing release process. Rebuild both app and web images, then recreate app, worker, scheduler and web. Keep database, Redis and upload data volumes.

On a shared host, inspect actual sustained usage with `docker stats --no-stream` and host memory/CPU monitoring before increasing concurrency. The malware scanner is a separate significant memory consumer with a 4 GB container ceiling. Do not reduce scanner limits or disable scanning to mask memory pressure. Reserve capacity for the other applications, MariaDB, Redis and the operating system; an appropriate server size requires representative concurrent-user tests.

No shared caching of user-scoped ticket results, MFA state or permissions was introduced. Such caching could delay security changes or expose another customer's data. Existing CSV exports remain streamed/chunked and lists remain bounded/paginated.

## Local validation results

- Laravel: 258 tests, 1,836 assertions passed using the verified isolated `amc_platform_test` database.
- Frontend: typecheck, all 13 test files and production build passed.
- Mocked browser checks: notification polling pause/resume, four Client Security layout cases and 24 email-provider cases passed against rebuilt staging. These do not verify real authentication or SMTP delivery.
- Updated staging: all seven services healthy; readiness returned HTTP 200. PHP-FPM configuration validation passed.
- One local idle snapshot: app 49 MiB, worker 40 MiB, scheduler 35 MiB, web 11 MiB, database 171 MiB, Redis 20 MiB, scanner 952 MiB (roughly 1.25 GiB combined). This is a momentary local observation, not peak production usage or a hosting-size guarantee.
- Staging credentials/environment files remain ignored and untracked. No commit, push, production deployment or database migration was performed.
