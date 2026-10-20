<?php

namespace App\Services\Auth;

use Illuminate\Support\Facades\DB;
use App\Models\User;

/** Instance-wide policy. Two persisted flags; never use frontend flags to enforce MFA. */
final class MfaSettings
{
    private ?array $flags = null;

    public function all(): array
    {
        if ($this->flags === null) {
            $values = DB::table('amc_security_settings')
                ->whereIn('key', ['mfa_totp_enabled', 'mfa_email_enabled'])
                ->pluck('enabled', 'key');
            $this->flags = [
                'totp_enabled' => (bool) ($values['mfa_totp_enabled'] ?? false),
                'email_enabled' => (bool) ($values['mfa_email_enabled'] ?? false),
            ];
        }
        return $this->flags;
    }

    /** Global technical capabilities apply to staff; Admin manages client/user policy separately. */
    public function forUser(User $user): array
    {
        if ($user->role === 'super_admin') {
            return app(\App\Services\System\TechnicalSecurityService::class)->flags();
        }
        if (! in_array($user->role, ['customer_admin', 'customer_user'], true)) {
            return $this->all() + ['require_mfa' => false];
        }
        if (! $user->customer_id) return ['totp_enabled'=>false,'email_enabled'=>false,'require_mfa'=>false];
        $customer = DB::table('amc_client_mfa_policies')->where('customer_id', $user->customer_id)->first();
        // Untouched/seeder-created policies inherit the Admin's instance-wide availability.
        // Saving a client policy (updated_by set) makes it an explicit override.
        $flags = ! $customer || $customer->updated_by === null
            ? $this->all() + ['require_mfa' => false]
            : [
                'totp_enabled' => (bool) $customer->totp_enabled,
                'email_enabled' => (bool) $customer->email_enabled,
                'require_mfa' => (bool) $customer->require_mfa,
            ];
        $override = DB::table('amc_user_mfa_policies')->where('user_id', $user->id)->first();
        if ($override) {
            $flags = [
                'totp_enabled' => (bool) $override->totp_enabled,
                'email_enabled' => (bool) $override->email_enabled,
                'require_mfa' => (bool) $override->require_mfa,
            ];
        }
        // A client/user override may RESTRICT but must never enable a method
        // explicitly switched off by the Contractor Admin for this installation.
        $global = $this->all();
        $flags['totp_enabled'] = (bool) $flags['totp_enabled'] && $global['totp_enabled'];
        $flags['email_enabled'] = (bool) $flags['email_enabled'] && $global['email_enabled'];
        return $flags;
    }

    public function totp(?User $user = null): bool
    {
        return ($user ? $this->forUser($user) : $this->all())['totp_enabled'];
    }
    public function email(?User $user = null): bool
    {
        return ($user ? $this->forUser($user) : $this->all())['email_enabled'];
    }
    public function requiredFor(User $user): bool
    {
        return (bool) $this->forUser($user)['require_mfa'];
    }
    public function invalidate(): void { $this->flags = null; }
}
