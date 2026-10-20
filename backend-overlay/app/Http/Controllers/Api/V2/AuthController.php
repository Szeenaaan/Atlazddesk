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

        abort_if(RateLimiter::tooManyAttempts($key, 5), 429, 'Too many attempts. Try again later.');
        // Password validation must not create a logged-in session.
        $valid = Auth::guard('web')->validate([
            'email' => $email, 'password' => $data['password'], 'status' => 'active',
        ]);
        if (! $valid) {
            RateLimiter::hit($key, 300);
            throw ValidationException::withMessages(['email' => ['Invalid credentials or inactive account.']]);
        }
        RateLimiter::clear($key);
        Auth::guard('web')->logout();
        $user = User::query()->where('email', $email)->firstOrFail();
        if (! in_array($user->role, AmcAccess::ROLES, true)) {
            throw ValidationException::withMessages(['email' => ['Account role requires administrator review.']]);
        }

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
        return response()->json(['user' => $this->mfa->publicUser($request->user())]);
    }

    /** A personal profile update never changes role, email or customer assignment. */
    public function updateProfile(Request $request)
    {
        $user = $request->user();
        $data = $request->validate([
            'name'=>['required','string','max:255'],
            'current_password'=>['nullable','required_with:new_password','string'],
            'new_password'=>['nullable','confirmed', Password::min(12)->mixedCase()->numbers()->symbols()],
        ]);
        if (isset($data['new_password'])) {
            abort_unless(Hash::check((string)($data['current_password'] ?? ''), $user->password), 422,
                'Current password is incorrect.');
            $user->password = $data['new_password'];
        }
        $user->name = $data['name'];
        $user->save();
        if (isset($data['new_password'])) {
            $user->tokens()->delete();
            $request->session()->regenerate();
        }
        AmcAudit::record($request, 'profile.updated', $user, ['name_updated'=>true, 'password_changed'=>isset($data['new_password'])]);
        return response()->json(['user'=>$this->mfa->publicUser($user)]);
    }

    public function logout(Request $request)
    {
        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();
        return response()->json(['message' => 'Logged out.']);
    }
}
