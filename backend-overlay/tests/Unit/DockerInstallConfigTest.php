<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

final class DockerInstallConfigTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir().'/supportdesk-install-test-'.bin2hex(random_bytes(8));
        mkdir($this->directory, 0700);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->directory.'/*') as $path) unlink($path);
        rmdir($this->directory);
    }

    private function configure(string $origin = 'https://support.example.test:8443'): Process
    {
        $process = new Process([PHP_BINARY, dirname(__DIR__, 3).'/infra/docker/configure.php', $this->directory],
            null, ['APP_ORIGIN' => $origin]);
        $process->run();
        return $process;
    }

    public function test_clean_install_generates_matching_credentials_and_preserves_them_on_retry(): void
    {
        $process = $this->configure();
        self::assertTrue($process->isSuccessful(), $process->getErrorOutput());
        $originals = [];
        foreach (glob($this->directory.'/*') as $path) $originals[$path] = file_get_contents($path);
        self::assertCount(3, $originals);
        $app = parse_ini_file($this->directory.'/app.env', false, INI_SCANNER_RAW);
        $db = parse_ini_file($this->directory.'/database.env', false, INI_SCANNER_RAW);
        $redis = parse_ini_file($this->directory.'/redis.env', false, INI_SCANNER_RAW);
        // Boolean assertions prevent a failed assertion from printing secret values.
        self::assertTrue($app['DB_PASSWORD'] === $db['MARIADB_PASSWORD']);
        self::assertTrue($app['REDIS_PASSWORD'] === $redis['REDIS_PASSWORD']);
        self::assertTrue($app['DB_PASSWORD'] !== $app['REDIS_PASSWORD']);
        self::assertTrue(str_starts_with($app['APP_KEY'], 'base64:'));
        self::assertSame('support.example.test:8443', $app['SANCTUM_STATEFUL_DOMAINS']);
        self::assertSame('true', $app['SESSION_SECURE_COOKIE']);
        self::assertFalse($this->configure()->isSuccessful());
        foreach ($originals as $path => $contents) self::assertTrue(file_get_contents($path) === $contents);
    }

    public function test_existing_partial_configuration_is_never_overwritten(): void
    {
        file_put_contents($this->directory.'/app.env', 'APP_KEY=existing-key-to-preserve');
        self::assertFalse($this->configure()->isSuccessful());
        self::assertTrue(file_get_contents($this->directory.'/app.env') === 'APP_KEY=existing-key-to-preserve');
        self::assertFileDoesNotExist($this->directory.'/database.env');
    }

    public function test_non_https_or_non_origin_addresses_are_rejected_without_writing_files(): void
    {
        foreach (['http://example.test', 'https://user@example.test', 'https://example.test/path', 'https://example.test?x=1'] as $origin) {
            self::assertFalse($this->configure($origin)->isSuccessful());
            self::assertSame([], glob($this->directory.'/*'));
        }
    }
}
