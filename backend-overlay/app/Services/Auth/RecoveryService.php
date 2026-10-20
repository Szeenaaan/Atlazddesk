<?php

namespace App\Services\Auth;

use App\Models\User;
use Illuminate\Support\Facades\{DB, Hash};

/** Recovery operations never disable an administrator's required MFA policy. */
final class RecoveryService
{
    /** Regenerate once; only hashes are persisted. Caller must enforce password + verified session. */
    public function regenerateCodes(User $user): array
    {
        return DB::transaction(function () use ($user): array {
            $account = User::query()->lockForUpdate()->findOrFail($user->id);
            abort_unless($account->mfa_totp_confirmed_at || $account->mfa_email_enabled,
                422, 'Enroll in MFA before generating recovery codes.');

            $codes = [];
            for ($i = 0; $i < 8; $i++) {
                $codes[] = strtoupper(bin2hex(random_bytes(10)));
            }
            $account->mfa_recovery_codes = array_map(
                static fn (string $code): string => Hash::make($code), $codes
            );
            $account->mfa_security_version = (int) $account->mfa_security_version + 1;
            $account->save();
            // Invalidate API tokens; existing authenticated browser sessions are
            // invalidated via the per-account MFA security version check.
            $account->tokens()->delete();

            return $codes;
        });
    }

    /** Erase lost authenticator material; the next password login must re-enroll. */
    public function resetAuthenticator(User $user): void
    {
        DB::transaction(function () use ($user): void {
            $account = User::query()->lockForUpdate()->findOrFail($user->id);
            $account->forceFill([
                'mfa_totp_secret' => null,
                'mfa_totp_pending_secret' => null,
                'mfa_totp_confirmed_at' => null,
                'mfa_last_totp_step' => null,
                'mfa_recovery_codes' => null,
                'mfa_security_version' => (int) $account->mfa_security_version + 1,
            ])->save();
            $account->tokens()->delete();
        });
    }
}
