<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;
use Symfony\Component\Console\Input\ArrayInput;

final class QueueWorkerTimeoutTest extends TestCase
{
    public function test_cli_timeout_is_an_integer_including_disabled_timeout(): void
    {
        $command = Artisan::all()['queue:work'];
        foreach (['60', '25', '0'] as $timeout) {
            $command->setInput(new ArrayInput(['--timeout' => $timeout], $command->getDefinition()));
            $options = (new \ReflectionMethod($command, 'gatherWorkerOptions'))->invoke($command);
            $this->assertSame((int) $timeout, $options->timeout);
        }
    }

    public function test_idle_worker_accepts_an_explicit_timeout_without_a_type_error(): void
    {
        if (! extension_loaded('pcntl')) {
            $this->markTestSkipped('The production worker requires pcntl.');
        }

        config(['queue.connections.timeout_test' => ['driver' => 'null'], 'cache.default' => 'array']);

        // A real daemon iteration with no jobs reproduces the string CLI timeout bug.
        $this->assertSame(0, Artisan::call('queue:work', [
            'connection' => 'timeout_test', '--timeout' => '60',
            '--sleep' => '0', '--stop-when-empty' => true,
        ]));
    }

    public function test_invalid_timeout_cannot_silently_disable_worker_protection(): void
    {
        $command = Artisan::all()['queue:work'];
        foreach (['invalid', '-1', '1.5'] as $timeout) {
            $command->setInput(new ArrayInput(['--timeout' => $timeout], $command->getDefinition()));
            try {
                (new \ReflectionMethod($command, 'gatherWorkerOptions'))->invoke($command);
                $this->fail('Invalid timeout was accepted.');
            } catch (\InvalidArgumentException $error) {
                $this->assertStringContainsString('--timeout', $error->getMessage());
            }
        }
    }
}
