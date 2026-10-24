<?php

namespace App\Services\Mail;

use Illuminate\Validation\ValidationException;

class SmtpCredentialVerifier
{
    public function verify(#[\SensitiveParameter] array $credentials): void
    {
        foreach (['host', 'port', 'username', 'password'] as $field) {
            if (! isset($credentials[$field]) || (string) $credentials[$field] === '') {
                throw ValidationException::withMessages(['credentials' => ['Enter the SMTP host, port, username and password before saving.']]);
            }
        }
        $transport = null;
        try {
            $transport = $this->transport($credentials);
            $transport->start();
        } catch (\Throwable $error) {
            // SMTP exceptions can contain usernames, secrets or server responses.
            $message = str_contains($error->getMessage(), 'Failed to authenticate')
                ? 'The email provider rejected the SMTP username or password. Check your credentials or app password and try again.'
                : 'SMTP verification failed. Check the host, port, TLS settings, username and password, then try again. Contact your email provider if the problem continues.';
            throw ValidationException::withMessages(['credentials' => [$message]]);
        } finally {
            if ($transport !== null) {
                try { $transport->stop(); } catch (\Throwable) { /* Do not expose SMTP responses during cleanup. */ }
            }
        }
    }

    protected function transport(#[\SensitiveParameter] array $credentials): AuthenticatedSmtpTransport
    {
        return AuthenticatedSmtpTransport::fromCredentials($credentials);
    }
}
