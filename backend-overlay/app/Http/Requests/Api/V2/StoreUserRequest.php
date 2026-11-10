<?php

namespace App\Http\Requests\Api\V2;

use App\Support\AmcAccess;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

class StoreUserRequest extends FormRequest
{
    public function authorize(): bool { return AmcAccess::mayManageUsers($this->user()); }
    public function rules(): array
    {
        $actor = $this->user();
        $allowed = match ($actor->role) {
            'admin' => ['technician','customer_admin','customer_user'],
            'customer_admin' => ['customer_user'],
            default => [],
        };
        return [
            'name' => ['required','string','max:255'],
            'email' => ['required','email','max:255', Rule::unique('users', 'email')],
            'password' => ['required','string', 'max:1024', 'confirmed', Password::min(8)->mixedCase()->numbers()->symbols()],
            'role' => ['required', Rule::in($allowed)],
            'customer_id' => $actor->role === 'customer_admin'
                ? ['prohibited'] : ['nullable','integer','exists:customers,id'],
            'status' => ['sometimes',Rule::in(['active','inactive'])],
            'designation_id' => $this->input('role') === 'technician'
                ? ['required', 'integer', Rule::exists('technician_designations', 'id')->where('is_active', true)] : ['prohibited'],
            'workforce_type' => $this->input('role') === 'technician'
                ? ['required', Rule::in(['employee', 'freelancer'])] : ['prohibited'],
            'asset_ids' => ['sometimes','array','max:1000'],
            'asset_ids.*' => ['required','integer','distinct'],
        ];
    }

    public function attributes(): array
    {
        return ['designation_id' => 'designation', 'workforce_type' => 'workforce classification'];
    }
}
