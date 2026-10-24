<?php

namespace App\Jobs;

use App\Models\{AmcMailProvider, User};
use App\Services\Auth\MfaSettings;
use App\Services\Mail\ContractorMailService;
use App\Services\System\TechnicalSecurityService;
use Illuminate\Contracts\Queue\{ShouldBeEncrypted, ShouldQueue};
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\{Cache, Log};

/** Encrypted queue payload; never serialize provider credentials or application passwords. */
final class SendAccountEmail implements ShouldQueue, ShouldBeEncrypted
{
    use Queueable;

    public int $tries = 3;
    public int $timeout = 25;
    public array $backoff = [10, 30, 60];

    public function __construct(
        public int $userId, public string $email, public string $role, public int $securityVersion,
        public string $kind, public ?int $providerId = null, public ?string $code = null,
        public ?int $expiresAt = null, public ?string $deliveryKey = null,
    ) {
        $this->onConnection(config('amc.account_mail_queue_connection', 'database'));
        $this->onQueue($kind === 'otp' ? 'security' : 'notifications');
    }

    public function handle(): void
    {
        $user = User::find($this->userId);
        if (! $user || $user->status !== 'active' || $user->email !== $this->email
            || $user->role !== $this->role || (int) $user->mfa_security_version !== $this->securityVersion
            || ($this->expiresAt !== null && now()->timestamp >= $this->expiresAt)) return;
        try {
            $mail = app(ContractorMailService::class);
            if ($this->kind === 'otp') {
                if (! $this->code || ! $this->deliveryKey || ! app(MfaSettings::class)->email($user)) return;
                // Atomically consume a per-browser delivery claim. Resends/verification cancel old claims.
                // Never replay a transport attempt whose acknowledgement may have been lost.
                $lock = Cache::lock($this->deliveryKey.':lock', 30);
                if (! $lock->get()) return;
                try {
                    if (! Cache::pull($this->deliveryKey, false)) return;
                    if ($user->role === 'super_admin') app(TechnicalSecurityService::class)->sendSecurityCode($user, $this->code);
                    else $mail->sendSecurityCode($user, $this->code);
                } finally { $lock->release(); }
            } elseif ($this->kind === 'technical_test' && $user->role === 'super_admin') {
                app(TechnicalSecurityService::class)->sendTestTo($user);
            } elseif ($this->kind === 'business_test' && $user->role === 'admin') {
                $provider = AmcMailProvider::whereKey($this->providerId)->where('is_active', true)->first();
                if ($provider) $mail->sendTest($provider, $user->email);
            }
        } catch (\Throwable) {
            Log::channel('amc_mail')->warning('account_mail.delivery_failed', [
                'user_id' => $this->userId, 'kind' => $this->kind, 'category' => 'delivery_unconfirmed',
            ]);
            throw new \RuntimeException('Account email delivery could not be confirmed.');
        }
    }
}
