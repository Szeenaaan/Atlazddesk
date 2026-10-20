<?php

namespace App\Http\Controllers\Api\V2;

use App\Http\Controllers\Controller;
use App\Models\{Customer, User};
use App\Services\Auth\MfaSettings;
use App\Services\Mail\ContractorMailService;
use App\Support\AmcAudit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\{DB, Hash};

/** Commercial Admin controls MFA policy only for client companies and their users. */
final class ClientMfaPolicyController extends Controller
{
    private function authorizeAdmin(Request $request): void
    {
        abort_unless($request->user()?->role === 'admin', 403);
    }

    private function customerPolicy(Customer $customer): array
    {
        $row = DB::table('amc_client_mfa_policies')->where('customer_id', $customer->id)->first();
        if (! $row || $row->updated_by === null) {
            return app(MfaSettings::class)->all() + ['require_mfa' => false];
        }
        return ['totp_enabled'=>(bool)$row->totp_enabled,
            'email_enabled'=>(bool)$row->email_enabled,
            'require_mfa'=>(bool)$row->require_mfa];
    }

    public function customer(Request $request, Customer $customer)
    {
        $this->authorizeAdmin($request);
        return response()->json(['data'=>$this->customerPolicy($customer)])->header('Cache-Control','no-store');
    }

    public function saveCustomer(Request $request, Customer $customer)
    {
        $this->authorizeAdmin($request);
        $data = $this->validated($request);
        DB::transaction(function () use ($request, $customer, $data): void {
            DB::table('amc_client_mfa_policies')->updateOrInsert(['customer_id'=>$customer->id], [
                ...$data, 'updated_by'=>$request->user()->id, 'updated_at'=>now(),
            ]);
            AmcAudit::record($request, 'client.mfa_policy.updated', $customer, ['enabled_methods'=>array_keys(array_filter($data))]);
        });
        return $this->customer($request, $customer);
    }

    private function scopedUser(Request $request, User $user): void
    {
        $this->authorizeAdmin($request);
        abort_unless(in_array($user->role, ['customer_admin','customer_user'], true) && $user->customer_id, 403);
    }

    public function user(Request $request, User $user, MfaSettings $settings)
    {
        $this->scopedUser($request, $user);
        $override = DB::table('amc_user_mfa_policies')->where('user_id', $user->id)->first();
        return response()->json(['data'=>[
            'user'=> $user->only(['id','name','email','customer_id','role']),
            'override'=> $override ? [
                'totp_enabled'=>(bool)$override->totp_enabled,
                'email_enabled'=>(bool)$override->email_enabled,
                'require_mfa'=>(bool)$override->require_mfa,
            ] : null,
            'effective'=>$settings->forUser($user),
        ]])->header('Cache-Control','no-store');
    }

    public function saveUser(Request $request, User $user, MfaSettings $settings)
    {
        $this->scopedUser($request, $user);
        $data = $this->validated($request);
        DB::transaction(function () use ($request, $user, $data): void {
            DB::table('amc_user_mfa_policies')->updateOrInsert(['user_id'=>$user->id], [
                ...$data, 'updated_by'=>$request->user()->id, 'updated_at'=>now(),
            ]);
            AmcAudit::record($request, 'client.user_mfa_policy.updated', $user, ['enabled_methods'=>array_keys(array_filter($data))]);
        });
        return $this->user($request, $user, $settings);
    }

    public function clearUser(Request $request, User $user, MfaSettings $settings)
    {
        $this->scopedUser($request, $user);
        $this->confirmPassword($request);
        DB::table('amc_user_mfa_policies')->where('user_id',$user->id)->delete();
        AmcAudit::record($request,'client.user_mfa_policy.cleared',$user);
        return $this->user($request, $user, $settings);
    }

    private function confirmPassword(Request $request): void
    {
        $request->validate(['current_password'=>['required','string']]);
        abort_unless(Hash::check((string)$request->input('current_password'), $request->user()->password),422,'Current password is incorrect.');
    }

    private function validated(Request $request): array
    {
        $this->confirmPassword($request);
        $data=$request->validate([
            'totp_enabled'=>['required','boolean'],
            'email_enabled'=>['required','boolean'],
            'require_mfa'=>['required','boolean'],
        ]);
        abort_if($data['require_mfa'] && ! $data['totp_enabled'], 422,
            'Mandatory MFA requires Google Authenticator so users can enroll securely on first sign-in.');
        // A client policy cannot enable Email OTP before its outgoing delivery route is configured.
        if ($data['email_enabled']) {
            $customerId = $request->route('customer')?->id
                ?? $request->route('user')?->customer_id;
            abort_unless(app(ContractorMailService::class)->hasProvider($customerId), 422,
                'Configure an email provider for this client before enabling Email OTP.');
        }
        return $data;
    }
}
