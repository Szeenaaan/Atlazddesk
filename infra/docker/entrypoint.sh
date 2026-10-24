#!/bin/sh
set -eu
php /opt/atlazddesk/check-php-version.php
# Configuration is supplied at runtime, never cached into the image.
php -r 'foreach (["APP_KEY", "DB_PASSWORD", "REDIS_PASSWORD"] as $name) { if (!getenv($name)) { fwrite(STDERR, "Missing required runtime setting: ".$name.PHP_EOL); exit(1); } }'
php artisan config:cache --quiet
exec "$@"
