# Local working notes

`local/` holds historical guides, superseded patch installers, backups and generated release evidence. It is ignored by Git, excluded from Docker builds and private to the local filesystem user. The organization manifest in that directory records original and current paths for restoring or locating archived files.

Do not run archived installers against the current application. Current source is authoritative. Do not copy credentials, local evidence or archives into tracked documentation.

Current guides are indexed in [docs/README.md](../docs/README.md). Deployment secrets, live uploads and database volumes remain in their existing locations; this cleanup does not move them.
