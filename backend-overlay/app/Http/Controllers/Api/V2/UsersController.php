<?php

namespace App\Http\Controllers\Api\V2;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Auth\{MfaService, MfaSettings};
use App\Support\{AmcAccess, AmcAudit};
use Illuminate\Http\Request;
use Illuminate\Support\Facades\{DB, Hash};
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

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
            'name' => ['sometimes','required','string','max:255'],
            'email' => ['sometimes','required','email','max:255', Rule::unique('users','email')->ignore($target->id)],
            'password' => ['sometimes','required','string','max:1024','confirmed', Password::min(8)->mixedCase()->numbers()->symbols()],
            'current_password' => ['nullable','string','max:1024'],
            'otp_code' => ['nullable','string','max:12'],
        ]);
        foreach (['name', 'email'] as $field) {
            if (isset($data[$field]) && $data[$field] === $target->{$field}) unset($data[$field]);
        }
        $changesIdentity = isset($data['name']) || isset($data['email']);
        abort_unless($changesIdentity || isset($data['password']), 422,
            'Change the name, email address or password before saving.');
        if ($changesIdentity) {
            abort_unless(Hash::check((string) $request->input('current_password'), $actor->password), 422,
                'Your account password is incorrect.');
        }
        if ($this->mfaSettings->email($actor)) $this->verifyDetailsOtp($request, $actor);
        unset($data['current_password'], $data['otp_code']);
        $changed = array_values(array_intersect(['name','email','password'], array_keys($data)));
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
            'per_page' => ['sometimes','integer','min:1','max:100'],
            'page' => ['sometimes','integer','min:1','max:1000000'],
        ]);
        $query = User::query()->select(['id','name','email','role','customer_id','status','created_at']);
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
            ->select(['id', 'name', 'email', 'phone', 'role', 'customer_id', 'status', 'created_at', 'last_login_at', 'email_verified_at']);
        if ($actor->role === 'customer_admin') {
            abort_unless($actor->customer_id, 403);
            $query->where('customer_id', $actor->customer_id)->where('role', 'customer_user');
        }
        $user = $query->with('customer:id,company_name')->findOrFail($id);
        if ($user->role === 'customer_user' && $user->customer_id) {
            $user->load(['assignedAssets' => fn ($assets) => $assets->where('assets.customer_id', $user->customer_id)
                ->select(['assets.id', 'assets.name', 'assets.asset_tag'])]);
        }
        return response()->json((new \App\Http\Resources\UserDetailsResource($user))->resolve($request))
            ->header('Cache-Control', 'private, no-store');
    }

    public function store(Request $request)
    {
        $actor = $request->user();
        abort_unless(AmcAccess::mayManageUsers($actor), 403);
        $allowed = match ($actor->role) {
            'admin' => ['technician','customer_admin','customer_user'],
            'customer_admin' => ['customer_user'],
            default => [],
        };
        $data = $request->validate([
            'name' => ['required','string','max:255'],
            'email' => ['required','email','max:255', Rule::unique('users', 'email')],
            'password' => ['required','string', 'max:1024', 'confirmed', Password::min(8)->mixedCase()->numbers()->symbols()],
            'role' => ['required', Rule::in($allowed)],
            'customer_id' => $actor->role === 'customer_admin'
                ? ['prohibited'] : ['nullable','integer','exists:customers,id'],
            'status' => ['sometimes',Rule::in(['active','inactive'])],
            'asset_ids' => ['sometimes','array','max:1000'],
            'asset_ids.*' => ['required','integer','distinct'],
        ]);
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
}
