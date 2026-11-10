<?php

namespace App\Http\Controllers\Api\V2;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Auth\{MfaService, MfaSettings, TotpService};
use App\Support\{AmcAccess, AmcAudit};
use Illuminate\Http\Request;
use Illuminate\Support\Facades\{Auth, DB, Hash, RateLimiter};
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use App\Models\{Customer, Ticket, TicketAssignment, PreventiveMaintenance, MaintenanceVisit};

final class UsersController extends Controller
{
    public function __construct(
        private readonly MfaService $mfa,
        private readonly MfaSettings $mfaSettings,
    ) {}

    private function manageableTarget(Request $request, int $id): User
    {
        $actor = $request->user();
        abort_unless(in_array($actor->role, ['admin', 'customer_admin'], true), 403);
        abort_if($request->attributes->has('amc_workspace_actor'), 403,
            'Return to your own workspace to edit account details.');
        abort_unless($request->hasSession()
            && (int) $request->session()->get(\Illuminate\Support\Facades\Auth::guard('web')->getName()) === (int) $actor->id
            && (int) $request->session()->get('amc_mfa_verified_user_id') === (int) $actor->id,
            403, 'Sign in through your browser to edit account details.');
        $query = User::query()->whereIn('role', ['technician', 'customer_admin', 'customer_user']);
        if ($actor->role === 'customer_admin') {
            abort_unless($actor->customer_id, 403);
            $query->where('role', 'customer_user')->where('customer_id', $actor->customer_id);
        }
        return $query->lockForUpdate()->findOrFail($id);
    }

    private function verifyDetailsOtp(Request $request, User $actor): void
    {
        if (! $this->mfaSettings->email($actor)) return;
        $code = $request->input('otp_code');
        abort_unless(is_string($code) && preg_match('/^\d{6}$/D', $code), 422,
            'Enter the verification code sent to your email.');
        abort_unless($this->mfa->consumeEmail($request, $code, $this->detailsPurpose($request, $actor)), 422,
            'The verification code is invalid or expired.');
    }

    private function detailsPurpose(Request $request, User $actor): string
    {
        return 'user_details:'.$actor->id.':'.$actor->mfa_security_version.':'.$request->route('id');
    }

    public function sendDetailsOtp(Request $request, int $id)
    {
        $this->manageableTarget($request, $id);
        $actor = $request->user();
        abort_unless($this->mfaSettings->email($actor), 422, 'Email OTP is not enabled for your account.');
        $this->mfa->sendEmail($request, $actor, $this->detailsPurpose($request, $actor));
        return response()->json(['message' => 'Check your inbox for the verification code. It may take a moment to arrive.'])
            ->header('Cache-Control', 'no-store');
    }

    public function updateDetails(Request $request, int $id)
    {
        return DB::transaction(function () use ($request, $id) {
        $target = $this->manageableTarget($request, $id);
        $actor = $request->user();
        $data = $request->validate([
            'designation_id' => $target->role === 'technician'
                ? ['required', 'integer', Rule::exists('technician_designations', 'id')->where('is_active', true)] : ['prohibited'],
            'name' => ['sometimes','required','string','max:255'],
            'email' => ['sometimes','required','email','max:255', Rule::unique('users','email')->ignoreModel($target)],
            'password' => ['sometimes','required','string','max:1024','confirmed', Password::min(8)->mixedCase()->numbers()->symbols()],
            'current_password' => ['nullable','string','max:1024'],
            'otp_code' => ['nullable','string','max:12'],
        ]);
        if ($target->role === 'technician') app(\App\Services\TechnicianDesignationService::class)->active((int) $data['designation_id']);
        if (isset($data['designation_id']) && (int) $data['designation_id'] === (int) $target->designation_id) unset($data['designation_id']);
        foreach (['name', 'email'] as $field) {
            if (isset($data[$field]) && $data[$field] === $target->{$field}) unset($data[$field]);
        }
        $changesIdentity = isset($data['name']) || isset($data['email']);
        abort_unless($changesIdentity || isset($data['password']) || isset($data['designation_id']), 422,
            'Change the account details before saving.');
        if ($changesIdentity) {
            abort_unless(Hash::check((string) $request->input('current_password'), $actor->password), 422,
                'Your account password is incorrect.');
        }
        if ($this->mfaSettings->email($actor)) $this->verifyDetailsOtp($request, $actor);
        unset($data['current_password'], $data['otp_code']);
        $changed = array_values(array_intersect(['name','email','password','designation_id'], array_keys($data)));
        if (isset($data['email'])) $data['email_verified_at'] = null;
        if (isset($data['password']) || isset($data['email'])) {
            $data['mfa_security_version'] = (int) $target->mfa_security_version + 1;
            $data['remember_token'] = null;
            $target->tokens()->delete();
            DB::table('password_reset_tokens')->whereIn('email', [$target->email, $data['email'] ?? $target->email])->delete();
        }
        $target->forceFill($data)->save();
        AmcAudit::record($request, 'user.details_updated', $target, [
            'changed' => $changed,
        ]);
        return response()->json(['data' => $target->only(['id','name','email'])])
            ->header('Cache-Control', 'no-store, private');
        });
    }
    /** Admin manages workforce/client accounts; Customer Admin sees only company users. */
    public function index(Request $request)
    {
        $actor = $request->user();
        abort_unless(AmcAccess::mayManageUsers($actor), 403);
        $filters = $request->validate([
            'search' => ['sometimes','string','max:100'],
            'customer_id' => ['sometimes','integer','exists:customers,id'],
            'workforce_only' => ['sometimes','boolean'],
            'role' => ['sometimes',Rule::in(['admin','technician','customer_admin','customer_user'])],
            'status' => ['sometimes',Rule::in(['active','inactive'])],
            'designation_id' => ['sometimes', 'integer'],
            'per_page' => ['sometimes','integer','min:1','max:100'],
            'page' => ['sometimes','integer','min:1','max:1000000'],
        ]);
        $query = User::query()->select(['id','name','email','role','customer_id','status','created_at','designation_id','workforce_type'])->with('designation:id,name,level,is_active');
        if (AmcAccess::isAdmin($actor)) $query->where('role', '!=', 'super_admin');
        if ($actor->role === 'customer_admin') {
            abort_unless($actor->customer_id, 403);
            $query->where('customer_id', $actor->customer_id)->where('role', 'customer_user');
        }
        if ($actor->role === 'admin') {
            if (! empty($filters['workforce_only'])) $query->whereNull('customer_id');
            elseif (isset($filters['customer_id'])) $query->where('customer_id', $filters['customer_id']);
            if (isset($filters['role'])) $query->where('role', $filters['role']);
        }
        if ($actor->role === 'admin' && isset($filters['designation_id'])) $query->where('role', 'technician')->where('designation_id', $filters['designation_id']);
        if ($actor->role === 'admin') {
            $query->addSelect(['client_admin_count' => DB::table('users as client_admins')->selectRaw('count(*)')
                ->whereColumn('client_admins.customer_id', 'users.customer_id')
                ->whereNull('client_admins.deleted_at')->where('client_admins.role', 'customer_admin')]);
        }
        if (isset($filters['status'])) $query->where('status', $filters['status']);
        if (! empty($filters['search'])) {
            $term = addcslashes($filters['search'], '%_\\');
            $query->where(fn ($q) => $q->where('name','like',"%{$term}%")
                ->orWhere('email','like',"%{$term}%"));
        }
        return \App\Support\GridSort::apply($query, $request, ['name', 'email', 'role', 'status'], ['customer' => ['customer', 'company_name']])->paginate($request->integer('per_page',25));
    }

    public function show(Request $request, int $id)
    {
        $actor = $request->user();
        abort_unless(AmcAccess::mayManageUsers($actor), 403);
        $query = User::query()->whereIn('role', ['admin', 'technician', 'customer_admin', 'customer_user'])
            ->select(['id', 'name', 'email', 'phone', 'role', 'customer_id', 'status', 'created_at', 'last_login_at', 'email_verified_at', 'designation_id', 'workforce_type']);
        if ($actor->role === 'customer_admin') {
            abort_unless($actor->customer_id, 403);
            $query->where('customer_id', $actor->customer_id)->where('role', 'customer_user');
        }
        $user = $query->with(['customer:id,company_name', 'designation:id,name,level,is_active'])->findOrFail($id);
        if ($user->role === 'customer_user' && $user->customer_id) {
            $user->load(['assignedAssets' => fn ($assets) => $assets->where('assets.customer_id', $user->customer_id)
                ->select(['assets.id', 'assets.name', 'assets.asset_tag'])]);
        }
        return response()->json((new \App\Http\Resources\UserDetailsResource($user))->resolve($request))
            ->header('Cache-Control', 'private, no-store');
    }

    public function store(\App\Http\Requests\Api\V2\StoreUserRequest $request)
    {
        $actor = $request->user();
        abort_unless(AmcAccess::mayManageUsers($actor), 403);
        $data = $request->validated();
        if ($actor->role === 'customer_admin') {
            abort_unless($actor->customer_id, 403);
            $data['customer_id'] = $actor->customer_id;
        }
        if (in_array($data['role'], ['customer_admin', 'customer_user'], true)) {
            abort_unless(! empty($data['customer_id']), 422, 'Customer users require a customer_id.');
        } else {
            abort_if(! empty($data['customer_id']), 422, 'Staff must not have a customer_id.');
            $data['customer_id'] = null;
        }
        // The User model has Laravel's hashed password cast.
        $user = \Illuminate\Support\Facades\DB::transaction(function () use ($data) {
            $ids = $data['asset_ids'] ?? null;
            unset($data['asset_ids']);
            if ($data['role'] === 'technician') app(\App\Services\TechnicianDesignationService::class)->active((int) $data['designation_id']);
            $user = User::create($data);
            if ($ids !== null) app(\App\Services\UserAssetAssignments::class)->sync($user, $ids);
            return $user;
        });
        AmcAudit::record($request, 'user.created', $user, ['role' => $user->role, 'customer_id'=>$user->customer_id]);
        return response()->json(['data' => $user->only(['id','name','email','role','status','customer_id'])], 201);
    }

    public function changeStatus(Request $request, int $id)
    {
        $actor = $request->user();
        abort_unless(AmcAccess::mayManageUsers($actor), 403);
        $data = $request->validate(['status'=>['required', Rule::in(['active','inactive'])]]);
        $query = User::query();
        if ($actor->role === 'admin') $query->where('role','!=','super_admin');
        if ($actor->role === 'customer_admin') {
            abort_unless($actor->customer_id, 403);
            $query->where('customer_id', $actor->customer_id)->where('role', 'customer_user');
        }
        $user = $query->findOrFail($id);
        abort_if($user->id === $actor->id && $data['status'] === 'inactive', 422, 'You cannot deactivate yourself.');
        if ($user->role === 'super_admin') abort(403, 'Super Admin accounts require privileged recovery procedures.');
        $user->update($data);
        $user->tokens()->delete();
        AmcAudit::record($request, 'user.status_changed', $user, ['status'=>$user->status]);
        return response()->json(['data'=>$user->only(['id','status'])]);
    }

    private function deleteTarget(Request $request, int $id): User
    {
        abort_unless($request->user()->role === 'admin', 403);
        abort_if($request->attributes->has('amc_workspace_actor'), 403);
        return User::query()->whereIn('role', ['technician', 'customer_admin', 'customer_user'])->findOrFail($id);
    }

    private function deletePurpose(User $actor, int $id): string
    {
        return 'user_delete:'.$actor->id.':'.$actor->mfa_security_version.':'.$id;
    }

    public function sendDeleteOtp(Request $request, int $id)
    {
        $this->deleteTarget($request, $id);
        $actor = $request->user();
        abort_unless(in_array('email', $this->mfa->requiredMethods($actor), true), 422,
            'Email verification is not enabled for your account.');
        $this->requireDeleteBrowserSession($request, $actor);
        $this->mfa->sendEmail($request, $actor, $this->deletePurpose($actor, $id));
        return response()->json(['message' => 'Check your inbox for the verification code. It may take a moment to arrive.'])
            ->header('Cache-Control', 'no-store');
    }

    private function requireDeleteBrowserSession(Request $request, User $actor): void
    {
        abort_unless($request->hasSession()
            && (int) $request->session()->get(Auth::guard('web')->getName()) === (int) $actor->id
            && (int) $request->session()->get('amc_mfa_verified_user_id') === (int) $actor->id,
            403, 'Sign in through your browser to verify this action.');
    }

    public function destroy(Request $request, int $id, TotpService $totp)
    {
        $target = $this->deleteTarget($request, $id);
        $actor = $request->user();
        if ($target->role === 'customer_admin') {
            abort_if(User::where('customer_id', $target->customer_id)->where('role', 'customer_admin')->count() <= 1,
                422, 'This client needs at least one client admin.');
        }
        $methods = $this->mfa->requiredMethods($actor);
        if ($methods !== []) {
            $this->requireDeleteBrowserSession($request, $actor);
            $data = $request->validate([
                'totp_code' => in_array('totp', $methods, true) ? ['required', 'string', 'regex:/^\d{6}$/D'] : ['prohibited'],
                'email_code' => in_array('email', $methods, true) ? ['required', 'string', 'regex:/^\d{6}$/D'] : ['prohibited'],
            ]);
            $failureKey = 'amc:user-delete:mfa:'.$actor->id.':'.$request->ip();
            abort_if(RateLimiter::tooManyAttempts($failureKey, 5), 429,
                'Too many verification attempts. Please try again later.');
            if (in_array('totp', $methods, true) && ! $this->mfa->consumeTotp($actor, $data['totp_code'], $totp)) {
                RateLimiter::hit($failureKey, 600);
                throw \Illuminate\Validation\ValidationException::withMessages(['totp_code' => 'Enter a valid authenticator code.']);
            }
            if (in_array('email', $methods, true) && ! $this->mfa->consumeEmail($request, $data['email_code'], $this->deletePurpose($actor, $id))) {
                RateLimiter::hit($failureKey, 600);
                throw \Illuminate\Validation\ValidationException::withMessages(['email_code' => 'Enter a valid email verification code.']);
            }
            RateLimiter::clear($failureKey);
        }
        DB::transaction(function () use ($request, $id) {
            $user = User::query()->whereIn('role', ['technician', 'customer_admin', 'customer_user'])
                ->whereKey($id)->lockForUpdate()->firstOrFail();
            if ($user->role === 'customer_admin') {
                // Serialize deletes for the same client, including concurrent requests for different admins.
                Customer::whereKey($user->customer_id)->lockForUpdate()->firstOrFail();
                abort_if(User::where('customer_id', $user->customer_id)->where('role', 'customer_admin')->count() <= 1,
                    422, 'This client needs at least one client admin.');
            }
            $user->assignedAssets()->detach();
            if ($user->role === 'technician') {
                app(\App\Services\TechnicianAssignmentLimits::class)->clearOverride($user);
                TicketAssignment::where('technician_id', $user->id)->whereNull('unassigned_at')->update(['unassigned_at' => now()]);
                Ticket::where('assigned_to', $user->id)->update(['assigned_to' => null]);
                PreventiveMaintenance::where('assigned_to', $user->id)->update(['assigned_to' => null]);
                MaintenanceVisit::where('technician_id', $user->id)->where('status', 'scheduled')->update(['technician_id' => null]);
            }
            $user->tokens()->delete();
            $user->forceFill(['status' => 'inactive', 'remember_token' => null,
                'mfa_security_version' => (int) $user->mfa_security_version + 1])->save();
            $user->delete();
            AmcAudit::record($request, 'user.deleted', $user, ['role' => $user->role, 'customer_id' => $user->customer_id]);
        });
        return response()->noContent();
    }
}
