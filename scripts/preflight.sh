#!/usr/bin/env bash
set -euo pipefail
cd "$(dirname "$0")/../backend-overlay"
if [[ ! -f artisan ]]; then echo 'Laravel skeleton missing: install Laravel 12 into backend-overlay first.' >&2; exit 2; fi
php artisan config:clear
php artisan route:list --path=api/v2
php artisan migrate:status
php artisan schedule:list
php artisan queue:failed
php artisan about
cd ../frontend
npm run build
printf '\nPreflight commands completed. This does not replace authorization tests, SMTP/SMS tests, TLS checks, backups or a penetration test.\n'
