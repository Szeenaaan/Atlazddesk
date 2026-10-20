<?php

namespace App\Http\Controllers\Api\V2;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Auth\RecoveryService;
use App\Support\AmcAudit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\{DB, Hash};

final class RecoveryController extends Controller
{
    public function __construct(private readonly RecoveryService $recovery) {}

    /** Available only after the current session passed the full MFA policy. */
    public function regenerate(Request $request)
    {
        $input = $request->validate(['password' => ['required', 'string', 'max:1024']]);
        $account = $request->user();
        abort_unless($request->hasSession() &&
            (int) $request->session()->get('amc_mfa_verified_user_id', 0) === (int) $account->id,
            403, 'A completed browser sign-in is required.');
        abort_unless(Hash::check($input['password'], $account->password), 422,
            'Current password is incorrect.');

        $codes = DB::transaction(function () use ($request, $account): array {
            $issued = $this->recovery->regenerateCodes($account);
            AmcAudit::record($request, 'security.recovery_codes_regenerated', $account,
                ['code_count' => count($issued)]);
            return $issued;
        });
        $request->session()->put('amc_mfa_verified_security_version',
            (int) $account->fresh()->mfa_security_version);

        return response()->json([
            'message' => 'New recovery codes generated. All previous codes are invalid.',
            'recovery_codes' => $codes,
        ])->header('Cache-Control', 'no-store, private');
    }

    /** No cross-tenant reset, no Super Admin reset through a web API. */
    public function resetAuthenticator(Request $request, int $id)
    {
        $actor = $request->user();
        $input = $request->validate([
            'current_password' => ['required', 'string', 'max:1024'],
            'reason' => ['required', 'string', 'min:15', 'max:500'],
        ]);
        abort_unless(in_array($actor->role, ['admin', 'customer_admin'], true), 403);
        abort_unless($request->hasSession() &&
            (int) $request->session()->get('amc_mfa_verified_user_id', 0) === (int) $actor->id,
            403, 'A completed browser sign-in is required.');
        abort_unless(Hash::check($input['current_password'], $actor->password), 422,
            'Your administrator password is incorrect.');
        abort_if((int) $actor->id === $id, 403, 'Self-service MFA reset is not allowed.');

        $targetQuery = User::query();
        if ($actor->role === 'admin') {
            $targetQuery->whereIn('role', ['technician', 'customer_admin', 'customer_user']);
        } else {
            abort_unless($actor->customer_id, 403);
            $targetQuery->where('role', 'customer_user')
                ->where('customer_id', $actor->customer_id);
        }
        $target = $targetQuery->findOrFail($id);
        abort_unless($target->mfa_totp_confirmed_at || $target->mfa_totp_pending_secret,
            422, 'This account has no authenticator enrollment to reset.');

        DB::transaction(function () use ($request, $actor, $target, $input): void {
            $this->recovery->resetAuthenticator($target);
            AmcAudit::record($request, 'security.authenticator_reset_by_admin', $target, [
                'actor_role' => $actor->role,
                'reason' => $input['reason'],
                'preserved_email_mfa' => true,
            ]);
        });

        return response()->json([
            'message' => 'Authenticator reset. Existing sessions are invalidated. The user may enroll a new authenticator in My Security after completing any required Email OTP.',
        ])->header('Cache-Control', 'no-store, private');
    }
}
