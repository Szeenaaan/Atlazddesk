<?php

namespace Tests\Unit;

use App\Services\Mail\{AuthenticatedSmtpTransport, SmtpCredentialVerifier};
use Illuminate\Validation\ValidationException;
use Tests\TestCase;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\Transport\Smtp\Auth\LoginAuthenticator;
use Symfony\Component\Mailer\Transport\Smtp\Stream\AbstractStream;

final class SmtpAuthenticationTest extends TestCase
{
    private function transport(array $responses, array &$commands, bool $tls = true): AuthenticatedSmtpTransport
    {
        $stream = new class($responses, $commands, $tls) extends AbstractStream {
            private array $commands;
            public function __construct(private array $responses, array &$commands, private bool $tls) { $this->commands = &$commands; }
            public function initialize(): void {}
            protected function getReadConnectionDescription(): string { return 'isolated SMTP fixture'; }
            public function isTLS(): bool { return $this->tls; }
            public function disableTls(): void {}
            public function setHost(string $host): void {}
            public function setPort(int $port): void {}
            public function readLine(): string { return array_shift($this->responses) ?? ''; }
            public function write(string $bytes, bool $debug = true): void { $this->commands[] = $bytes; }
        };
        $transport = new AuthenticatedSmtpTransport('smtp.example.test', 465, true, stream: $stream, authenticators: [new LoginAuthenticator()]);
        $transport->setUsername('account'); $transport->setPassword('test-secret'); $transport->setRequireTls(true);
        return $transport;
    }

    public function test_success_requires_the_smtp_authentication_exchange_without_sending_mail(): void
    {
        $commands = [];
        $transport = $this->transport(["220 ready\r\n", "250-server\r\n", "250 AUTH LOGIN\r\n",
            "334 username\r\n", "334 password\r\n", "235 authenticated\r\n", "221 bye\r\n"], $commands);
        try { $transport->start(); } finally { $transport->stop(); }
        $this->assertContains("AUTH LOGIN\r\n", $commands);
        $this->assertContains(base64_encode('account')."\r\n", $commands);
        $this->assertContains(base64_encode('test-secret')."\r\n", $commands);
        $this->assertFalse((bool) preg_grep('/^(MAIL FROM|RCPT TO|DATA)/', $commands));
    }

    public function test_rejected_username_or_password_fails_authentication(): void
    {
        $commands = [];
        $transport = $this->transport(["220 ready\r\n", "250-server\r\n", "250 AUTH LOGIN\r\n",
            "334 username\r\n", "334 password\r\n", "535 credentials rejected\r\n", "250 reset\r\n"], $commands);
        $this->expectException(TransportException::class);
        $transport->start();
    }

    public function test_anonymous_server_is_not_accepted_as_verified(): void
    {
        $commands = [];
        $transport = $this->transport(["220 ready\r\n", "250 hello\r\n", "221 bye\r\n"], $commands);
        $this->expectException(TransportException::class);
        try { $transport->start(); } finally { $transport->stop(); }
    }

    public function test_unencrypted_connection_is_rejected(): void
    {
        $commands = [];
        $transport = $this->transport(["220 ready\r\n", "250 hello\r\n"], $commands, false);
        $this->expectException(TransportException::class);
        $transport->start();
    }

    public function test_verification_sanitizes_auth_errors_and_disconnects(): void
    {
        $transport = $this->createMock(AuthenticatedSmtpTransport::class);
        $transport->expects($this->once())->method('start')->willThrowException(new TransportException('Failed to authenticate account test-secret private-host'));
        $transport->expects($this->atLeastOnce())->method('stop');
        $verifier = new class($transport) extends SmtpCredentialVerifier {
            public function __construct(private AuthenticatedSmtpTransport $smtp) {}
            protected function transport(array $credentials): AuthenticatedSmtpTransport { return $this->smtp; }
        };
        try {
            $verifier->verify(['host' => 'smtp.example.test', 'port' => 465, 'username' => 'account', 'password' => 'test-secret']);
            $this->fail('Rejected credentials were accepted.');
        } catch (ValidationException $error) {
            $message = json_encode($error->errors());
            $this->assertStringContainsString('rejected the SMTP username or password', $message);
            $this->assertStringNotContainsString('test-secret', $message);
            $this->assertStringNotContainsString('private-host', $message);
        }
    }
}
