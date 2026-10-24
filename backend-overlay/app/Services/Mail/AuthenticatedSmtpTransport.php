<?php

namespace App\Services\Mail;

use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\Transport\Smtp\EsmtpTransport;

/** Never treat an anonymous SMTP connection as successful credential verification. */
class AuthenticatedSmtpTransport extends EsmtpTransport
{
    public static function fromCredentials(#[\SensitiveParameter] array $credentials): self
    {
        $transport = new self($credentials['host'], (int) $credentials['port'],
            ($credentials['encryption'] ?? 'tls') === 'ssl');
        $transport->setUsername((string) ($credentials['username'] ?? ''));
        $transport->setPassword((string) ($credentials['password'] ?? ''));
        $transport->setRequireTls(true);
        $transport->getStream()->setTimeout(10);

        return $transport;
    }

    public function start(): void
    {
        if ($this->getUsername() === '' || $this->getPassword() === '') {
            throw new TransportException('SMTP credentials are required.');
        }
        parent::start();
        if (! array_key_exists('AUTH', $this->getCapabilities())) {
            throw new TransportException('The SMTP server does not support authentication.');
        }
    }
}
