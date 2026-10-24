<?php

namespace App\Http\Controllers\Api\V2;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\AmcAccess;
use App\Services\Auth\MfaService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\{Auth, RateLimiter, Hash};
use Illuminate\Validation\Rules\Password;
use App\Support\AmcAudit;
use Illuminate\Validation\ValidationException;
use Illuminate\Validation\Rule;
use Illuminate\Database\UniqueConstraintViolationException;

final class AuthController extends Controller
{
    public function __construct(private readonly MfaService $mfa) {}

    public function login(Request $request)
    {
        $data = $request->validate([
            'email' => ['required', 'email', 'max:255'],
            'password' => ['required', 'string', 'max:1024'],
        ]);
        $email = mb_strtolower(trim($data['email']));
        $key = 'amc:login:'.hash('sha256', $email.'|'.$request->ip());

        abort_if(RateLimiter::tooManyAttempts($key, 5), 429,
            'Too many sign-in attempts. Try again in '.max(1, RateLimiter::availableIn($key)).' seconds.',
            ['Retry-After' => max(1, RateLimiter::availableIn($key))]);
        // Password validation must not create a logged-in session.
        $valid = Auth::guard('web')->validate([
            'email' => $email, 'password' => $data['password'], 'status' => 'active',
        ]);
        if (! $valid) {
            RateLimiter::hit($key, 300);
            throw ValidationException::withMessages(['email' => ['Invalid credentials or inactive account.']]);
        }
        RateLimiter::clear($key);
        $user = User::query()->where('email', $email)->firstOrFail();
        if (! in_array($user->role, AmcAccess::ROLES, true)) {
            throw ValidationException::withMessages(['email' => ['Account role requires administrator review.']]);
        }

        app(\App\Services\Auth\AccountSession::class)->assertAvailable($request, $user);
        if ($previous = Auth::guard('web')->user()) {
            if ($previous->id !== $user->id) {
                app(\App\Services\Auth\AccountSession::class)->release($request, $previous);
            }
        }
        Auth::guard('web')->logout();
        $required = $this->mfa->requiredMethods($user);
        if ($required !== [] || $this->mfa->required($user)) {
            // Do not downgrade a required-role policy to password-only.
            abort_if($required === [], 503, 'Mandatory MFA is not configured for this account.');
            $this->mfa->begin($request, $user);
            return response()->json($this->mfa->challenge($request, $user), 202)
                ->header('Cache-Control', 'no-store');
        }

        return response()->json($this->mfa->finish($request, $user))
            ->header('Cache-Control', 'no-store');
    }

    public function me(Request $request)
    {
        if ($request->attributes->has('amc_workspace_actor')) {
            return response()->json(app(\App\Services\Auth\CustomerWorkspace::class)->payload($request, $request->user()));
        }
        return response()->json(['user' => $this->mfa->publicUser($request->user())]);
    }

    public function activity(Request $request)
    {
        abort_unless($request->hasSession()
            && $request->session()->has(Auth::guard('web')->getName()), 401, 'Sign in again.');
        app(\App\Services\Auth\AccountSession::class)->validateAndTouch($request, $request->user());
        return response()->noContent()->header('Cache-Control', 'no-store');
    }

    /** Only Super Admin may change their own email; role and customer remain unchanged. */
    public function updateProfile(Request $request)
    {
        $user = $request->user();
        if ($request->has('email') && is_string($request->input('email'))) {
            $request->merge(['email' => mb_strtolower(trim($request->input('email')))]);
        }
        $data = $request->validate([
            'name'=>['required','string','max:255'],
            'email'=>$user->role === 'super_admin'
                ? ['sometimes', 'required', 'email', 'max:255', Rule::unique('users', 'email')->ignore($user->id)]
                : ['prohibited'],
            'current_password'=>['nullable','required_with:new_password','string','max:1024'],
            'new_password'=>['nullable','string','max:1024','confirmed', Password::min(8)->mixedCase()->numbers()->symbols()],
        ]);
        $emailChanged = isset($data['email']) && $data['email'] !== $user->email;
        if (isset($data['new_password']) || $emailChanged) {
            abort_unless(Hash::check((string)($data['current_password'] ?? ''), $user->password), 422,
                'Current password is incorrect.');
        }
        if (isset($data['new_password'])) $user->password = $data['new_password'];
        if ($emailChanged) {
            $user->email = $data['email'];
            $user->email_verified_at = null;
        }
        $user->name = $data['name'];
        try {
            $user->save();
        } catch (UniqueConstraintViolationException $error) {
            if (! $emailChanged) throw $error;
            throw ValidationException::withMessages(['email' => ['This email address is already in use. Choose another email address.']]);
        }
        if (isset($data['new_password']) || $emailChanged) {
            $user->tokens()->delete();
            if ($request->hasSession()) $request->session()->regenerate();
        }
        AmcAudit::record($request, 'profile.updated', $user, ['name_updated'=>true, 'email_changed'=>$emailChanged, 'password_changed'=>isset($data['new_password'])]);
        return response()->json(['user'=>$this->mfa->publicUser($user)]);
    }

    public function logout(Request $request)
    {
        app(\App\Services\Auth\AccountSession::class)->release($request, $request->user());
        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();
        return response()->json(['message' => 'Logged out.']);
    }
}
