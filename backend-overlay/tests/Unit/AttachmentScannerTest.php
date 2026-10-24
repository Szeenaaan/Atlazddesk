<?php

namespace Tests\Unit;

use App\Services\AttachmentScanner;
use Illuminate\Http\UploadedFile;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class AttachmentScannerTest extends TestCase
{
    public function test_protocol_accepts_only_explicit_clean_response_and_closes_connections(): void
    {
        foreach (['stream: OK' => 200, 'stream: Test-Signature FOUND' => 422,
            'stream: scan ERROR' => 503, 'garbage' => 503, '' => 503] as $reply => $expected) {
            [$client, $server] = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, 0);
            fwrite($server, $reply."\0");
            $scanner = new class($client) extends AttachmentScanner {
                public function __construct(public $socket) {}
                protected function connect() { return $this->socket; }
            };
            try {
                $scanner->scan(UploadedFile::fake()->createWithContent('report.txt', 'Report'));
                $this->assertSame(200, $expected);
            } catch (HttpException $error) {
                $this->assertSame($expected, $error->getStatusCode());
                $this->assertStringNotContainsString($reply ?: 'tcp://', $error->getMessage());
            }
            $this->assertFalse(is_resource($client));
            $this->assertSame("zINSTREAM\0".pack('N', 6).'Report'.pack('N', 0), stream_get_contents($server));
            fclose($server);
        }
    }

    public function test_connection_failure_is_sanitized_and_fails_closed(): void
    {
        $scanner = new class extends AttachmentScanner {
            protected function connect() { throw new \RuntimeException('private-scanner:3310'); }
        };
        try {
            $scanner->scan(UploadedFile::fake()->createWithContent('report.txt', 'Report'));
            $this->fail('Upload must be rejected');
        } catch (HttpException $error) {
            $this->assertSame(503, $error->getStatusCode());
            $this->assertStringNotContainsString('private-scanner', $error->getMessage());
        }
    }
}
