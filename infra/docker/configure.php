<?php
// Clean-install helper only. Never replace an existing installation's key or credentials.
declare(strict_types=1);

$origin = rtrim((string) getenv('APP_ORIGIN'), '/');
$url = parse_url($origin);
if (! $url || ($url['scheme'] ?? '') !== 'https' || empty($url['host'])
    || isset($url['user']) || isset($url['pass']) || isset($url['query']) || isset($url['fragment'])
    || ! empty($url['path']) || preg_match('/[\s\x00-\x1f\x7f\'"$]/', $origin)) {
    fwrite(STDERR, "APP_ORIGIN must be an HTTPS origin with no path or credentials.\n");
    exit(1);
}
$dir = $argv[1] ?? '/output';
$files = ['app.env', 'database.env', 'redis.env'];
foreach ($files as $file) {
    if (file_exists("$dir/$file")) {
        fwrite(STDERR, "Existing installation detected; no files changed. Preserve its APP_KEY and credentials.\n");
        exit(1);
    }
}
umask(0077);
$key = 'base64:'.base64_encode(random_bytes(32));
$password = bin2hex(random_bytes(32));
$rootPassword = bin2hex(random_bytes(32));
$redis = bin2hex(random_bytes(32));
$host = $url['host'].(isset($url['port']) ? ':'.$url['port'] : '');
$app = [
    'APP_NAME' => 'SupportDesk', 'APP_ENV' => 'production', 'APP_DEBUG' => 'false',
    'APP_KEY' => $key, 'APP_URL' => $origin,
    'LOG_CHANNEL' => 'stack', 'LOG_STACK' => 'daily', 'LOG_LEVEL' => 'warning',
    'DB_CONNECTION' => 'mysql', 'DB_HOST' => 'database', 'DB_PORT' => '3306',
    'DB_DATABASE' => 'amc_platform', 'DB_USERNAME' => 'amc_platform_user', 'DB_PASSWORD' => $password,
    'REDIS_CLIENT' => 'phpredis', 'REDIS_HOST' => 'redis', 'REDIS_PORT' => '6379', 'REDIS_PASSWORD' => $redis,
    'CACHE_STORE' => 'redis', 'SESSION_DRIVER' => 'redis', 'QUEUE_CONNECTION' => 'redis',
    'SESSION_COOKIE' => 'amc-platform-session', 'CACHE_PREFIX' => 'amc-platform-cache-',
    'REDIS_PREFIX' => 'amc-platform-database-', 'SESSION_DOMAIN' => 'null',
    'SESSION_SECURE_COOKIE' => 'true', 'SESSION_SAME_SITE' => 'lax',
    'CORS_ALLOWED_ORIGINS' => $origin, 'SANCTUM_STATEFUL_DOMAINS' => $host,
    'FILESYSTEM_DISK' => 'local', 'AMC_SEED_DEMO_USERS' => 'false',
    'MAIL_MAILER' => 'smtp', 'MAIL_HOST' => '', 'MAIL_PORT' => '587',
    'MAIL_USERNAME' => '', 'MAIL_PASSWORD' => '', 'MAIL_SCHEME' => 'smtp',
    'MAIL_FROM_ADDRESS' => '', 'MAIL_FROM_NAME' => 'SupportDesk',
];
$sets = [
    'app.env' => $app,
    'database.env' => ['MARIADB_DATABASE' => $app['DB_DATABASE'], 'MARIADB_USER' => $app['DB_USERNAME'],
        'MARIADB_PASSWORD' => $password, 'MARIADB_ROOT_PASSWORD' => $rootPassword],
    'redis.env' => ['REDIS_PASSWORD' => $redis],
];
foreach ($sets as $file => $values) {
    $handle = fopen("$dir/$file", 'x');
    if (! $handle) throw new RuntimeException('Cannot create installation configuration.');
    foreach ($values as $name => $value) fwrite($handle, "$name=$value\n");
    fclose($handle);
    chmod("$dir/$file", 0600);
}
echo "Created installation configuration with a new key and independent random credentials.\n";
echo "Keep these files private and backed up. Configure SMTP and TLS before starting the site.\n";
