<?php

namespace App\Services\Auth;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\{Cache, DB};

/** One browser session per account, coordinated across PHP workers by a user row lock. */
final class AccountSession
{
    private const TOKEN = 'amc_account_session_token';

    public function idleSeconds(User $user): int
    {
        return match ($user->role) {
            'super_admin' => 60,
            'admin' => 180,
            default => 600,
        };
    }

    private function key(User $user): string
    {
        return 'amc:account-session:'.$user->id.':'.(int) $user->mfa_security_version;
    }

    public function assertAvailable(Request $request, User $user): void
    {
        $owner = Cache::get($this->key($user));
        $token = $request->session()->get(self::TOKEN);
        abort_if($owner !== null && (! is_string($token) || ! hash_equals((string) $owner, hash('sha256', $token))),
            409, 'Someone is already signed in to your account. Sign out on the other device or wait for the session to expire.');
    }

    public function claim(Request $request, User $user): void
    {
        DB::transaction(function () use ($request, $user): void {
            $current = User::query()->lockForUpdate()->findOrFail($user->id);
            abort_unless($current->status === 'active' && $current->role === $user->role
                && (int) $current->mfa_security_version === (int) $user->mfa_security_version,
                401, 'Your account changed. Sign in again.');
            $this->assertAvailable($request, $current);
            $token = bin2hex(random_bytes(32));
            abort_unless(Cache::put($this->key($current), hash('sha256', $token),
                $this->idleSeconds($current)), 503);
            $request->session()->put(self::TOKEN, $token);
        });
    }

    public function validateAndTouch(Request $request, User $user, bool $activity = true): void
    {
        DB::transaction(function () use ($request, $user, $activity): void {
            $current = User::query()->lockForUpdate()->findOrFail($user->id);
            abort_unless($current->status === 'active' && $current->role === $user->role
                && (int) $current->mfa_security_version === (int) $user->mfa_security_version,
                401, 'Your account changed. Sign in again.');
            $token = $request->session()->get(self::TOKEN);
            if ($token === null) {
                // Existing sessions are enrolled on their first request after deployment.
                $this->claim($request, $user);
                return;
            }
            $owner = Cache::get($this->key($user));
            abort_unless(is_string($owner) && is_string($token) && hash_equals($owner, hash('sha256', $token)),
                401, 'Your session has expired. Sign in again.');
            if ($activity) {
                abort_unless(Cache::put($this->key($user), $owner, $this->idleSeconds($current)), 503);
            }
        });
    }

    public function release(Request $request, User $user): void
    {
        DB::transaction(function () use ($request, $user): void {
            User::query()->lockForUpdate()->findOrFail($user->id);
            $token = $request->session()->get(self::TOKEN);
            $owner = Cache::get($this->key($user));
            if (is_string($token) && is_string($owner) && hash_equals($owner, hash('sha256', $token))) {
                Cache::forget($this->key($user));
            }
            $request->session()->forget(self::TOKEN);
        });
    }
}
