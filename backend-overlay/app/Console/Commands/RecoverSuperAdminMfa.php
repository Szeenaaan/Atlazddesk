<?php

namespace App\Console\Commands;

use App\Models\{ActivityLog, User};
use App\Services\Auth\RecoveryService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/** Emergency break-glass: never exposed as an HTTP route. */
final class RecoverSuperAdminMfa extends Command
{
    protected $signature = 'amc:security:recover-admin
        {email : Exact existing Super Admin email}
        {--incident= : Required approved incident or support reference}';

    protected $description = 'Interactively reset a lost Super Admin authenticator on THIS installation';

    public function handle(RecoveryService $recovery): int
    {
        if (! $this->input->isInteractive()) {
            $this->error('Recovery must be performed interactively from an authorized VPS shell.');
            return self::FAILURE;
        }
        $incident = trim((string) $this->option('incident'));
        if (mb_strlen($incident) < 6 || mb_strlen($incident) > 120) {
            $this->error('Provide a real incident reference (6–120 characters).');
            return self::FAILURE;
        }
        $email = trim((string) $this->argument('email'));
        $account = User::query()->where('role', 'super_admin')->where('email', $email)->first();
        if (! $account) {
            $this->error('No matching technical Super Admin account on this installation.');
            return self::FAILURE;
        }
        if (! $account->mfa_totp_confirmed_at && ! $account->mfa_totp_pending_secret) {
            $this->error('The account has no authenticator enrollment to reset. No changes made.');
            return self::FAILURE;
        }

        $this->warn('This is an emergency account-recovery operation, not an MFA bypass.');
        $this->warn('Existing sessions/tokens and previous authenticator/recovery codes will be invalidated.');
        $this->warn('Technical email OTP remains mandatory if configured; fix delivery first if necessary.');
        $confirmation = 'RESET '.$account->email;
        if ($this->ask('Type '.$confirmation.' to authorize the reset') !== $confirmation) {
            $this->error('Confirmation did not match. No changes made.');
            return self::FAILURE;
        }

        DB::transaction(function () use ($recovery, $account, $incident): void {
            $recovery->resetAuthenticator($account);
            ActivityLog::create([
            'user_id' => null,
            'customer_id' => null,
            'event' => 'security.super_admin_emergency_recovery',
            'subject_type' => User::class,
            'subject_id' => $account->id,
            'request_id' => null,
            'ip_address' => null,
            'user_agent' => null,
            'properties' => [
                'incident' => $incident,
                'source' => 'authorized_vps_console',
                'reset' => 'authenticator_only',
                'email_mfa_preserved' => true,
            ],
            ]);
        });

        $this->info('Authenticator reset. Sign in with the existing password and enroll a NEW authenticator.');
        return self::SUCCESS;
    }
}
