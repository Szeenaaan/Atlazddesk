<?php

namespace App\Services\Mail;

use App\Models\AmcMailProvider;
use App\Models\User;
use Illuminate\Support\Facades\{DB, Http, Log, Mail};
use Symfony\Component\HttpKernel\Exception\HttpException;

/** Per-installation mail routing. Never returns or logs credentials. */
final class ContractorMailService
{
    public function providerFor(?int $customerId): ?AmcMailProvider
    {
        $settings = DB::table('amc_mail_settings')->where('id', 1)->first();
        $override = $customerId
            ? DB::table('amc_client_mail_settings')->where('customer_id', $customerId)->first()
            : null;

        // An explicitly selected, now-disabled provider must fail closed, not silently fall back.
        $id = $override?->provider_id ?? $settings?->default_provider_id;
        return $id ? AmcMailProvider::query()->whereKey($id)->where('is_active', true)->first() : null;
    }

    public function hasProvider(?int $customerId): bool
    {
        return $this->providerFor($customerId) !== null;
    }

    public function notificationsEnabled(?int $customerId): bool
    {
        $settings = DB::table('amc_mail_settings')->where('id', 1)->first();
        $override = $customerId
            ? DB::table('amc_client_mail_settings')->where('customer_id', $customerId)->first()
            : null;
        return (bool) ($override?->notifications_enabled ?? $settings?->notifications_enabled ?? false);
    }

    /** Returns false when notifications are intentionally off; delivery errors fail explicitly. */
    public function sendNotification(User $recipient, string $subject, string $text): bool
    {
        if (! $this->notificationsEnabled($recipient->customer_id)) {
            return false;
        }
        $this->send($recipient, $subject, $text);
        return true;
    }

    /** MFA/security delivery is independent of marketing/incident notification switches. */
    public function sendSecurityCode(User $recipient, string $code): void
    {
        $this->send($recipient, 'Atlazddesk verification code',
            'Your Atlazddesk verification code is: '.$code.'. It expires in five minutes. Do not share it.');
    }

    /** Explicit provider dispatch for an isolated technical mail route. */
    public function sendWithProvider(AmcMailProvider $provider, string $to, string $subject, string $text): void
    {
        $this->deliver($provider, $to, $subject, $text);
    }

    public function sendTest(AmcMailProvider $provider, string $recipientEmail): void
    {
        $this->deliver($provider, $recipientEmail, 'Atlazddesk email delivery test',
            'This is a configuration test for this installation. No client data is included.');
    }

    private function send(User $recipient, string $subject, string $text): void
    {
        $provider = $this->providerFor($recipient->customer_id);
        if ($provider === null) {
            throw new HttpException(503, 'Email delivery is not configured for this account.');
        }
        $this->deliver($provider, $recipient->email, $subject, $text);
    }

    private function deliver(AmcMailProvider $provider, string $to, string $subject, string $text): void
    {
        $config = $provider->credentials;
        if (! is_array($config)) {
            throw new HttpException(503, 'Email delivery provider is not configured.');
        }

        try {
            if ($provider->driver === 'smtp') {
                $mailer = Mail::build([
                    'transport' => 'smtp',
                    'host' => $config['host'],
                    'port' => (int) $config['port'],
                    'username' => $config['username'] ?? null,
                    'password' => $config['password'] ?? null,
                    'encryption' => $config['encryption'] ?? 'tls',
                    'timeout' => 10,
                ]);
                $mailer->raw($text, fn ($message) => $message
                    ->to($to)->from($provider->from_email, $provider->from_name)->subject($subject));
            } elseif ($provider->driver === 'resend') {
                Http::withToken((string) $config['api_key'])->acceptJson()->timeout(10)
                    ->post('https://api.resend.com/emails', [
                        'from' => sprintf('%s <%s>', $provider->from_name, $provider->from_email),
                        'to' => [$to], 'subject' => $subject, 'text' => $text,
                    ])->throw();
            } elseif ($provider->driver === 'postmark') {
                Http::withHeaders(['X-Postmark-Server-Token' => (string) $config['api_key']])
                    ->acceptJson()->timeout(10)
                    ->post('https://api.postmarkapp.com/email', [
                        'From' => sprintf('%s <%s>', $provider->from_name, $provider->from_email),
                        'To' => $to, 'Subject' => $subject, 'TextBody' => $text,
                    ])->throw();
            } else {
                throw new \LogicException('Unsupported mail transport');
            }
        } catch (\Throwable $e) {
            // Never log the exception message; SMTP/API exceptions can contain addresses or secrets.
            Log::warning('amc.mail.delivery_failed', [
                'provider_id' => $provider->id,
                'driver' => $provider->driver,
                'exception_type' => $e::class,
            ]);
            throw new HttpException(503, 'Email delivery failed. Check the selected provider in Admin settings.');
        }
    }
}
