<?php

namespace App\Http\Requests\Api\V2;

use App\Support\AmcAccess;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

final class StoreClientOnboardingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null && AmcAccess::isStaff($this->user());
    }

    public function rules(): array
    {
        return [
            'company_name' => ['required','string','max:255'],
            'customer_code' => ['required','string','max:50', Rule::unique('customers','customer_code')],
            'email' => ['nullable','email','max:255'],
            'phone' => ['nullable','string','max:50'],
            'city' => ['nullable','string','max:100'],
            'country' => ['nullable','string','max:100'],
            'customer_admin_name' => ['required','string','max:255'],
            'customer_admin_email' => ['required','email','max:255',Rule::unique('users','email')],
            'customer_admin_password' => ['required','confirmed', Password::min(12)->mixedCase()->numbers()->symbols()],
        ];
    }
}
