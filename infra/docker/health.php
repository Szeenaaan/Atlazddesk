<?php
declare(strict_types=1);

try {
    $mode = $argv[1] ?? '';
    if ($mode === 'app') {
        $command = 'FCGI_ROLE=RESPONDER SCRIPT_NAME=/fpm-ping SCRIPT_FILENAME=/fpm-ping REQUEST_METHOD=GET cgi-fcgi -bind -connect 127.0.0.1:9000';
        exec($command, $lines, $status);
        if ($status !== 0 || ! str_contains(implode("\n", $lines), 'pong')) exit(1);
    } else {
        $needle = match ($mode) { 'worker' => 'queue:work', 'scheduler' => 'schedule:work', default => throw new RuntimeException('Unknown check') };
        $found = false;
        foreach (glob('/proc/[0-9]*/cmdline') as $path) {
            $command = @file_get_contents($path);
            if ($command !== false && str_contains($command, $needle)) $found = true;
        }
        if (! $found) exit(1);
    }
    require '/var/www/html/vendor/autoload.php';
    $app = require '/var/www/html/bootstrap/app.php';
    $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
    Illuminate\Support\Facades\DB::select('SELECT 1');
    Illuminate\Support\Facades\Redis::connection()->ping();
    if ($mode === 'scheduler') {
        $heartbeat = Illuminate\Support\Facades\Cache::get('amc:scheduler:heartbeat');
        if (! is_numeric($heartbeat) || time() - (int) $heartbeat > 150) exit(1);
    }
    exit(0);
} catch (Throwable) {
    // Do not expose connection strings, passwords, or customer data in health output.
    exit(1);
}
