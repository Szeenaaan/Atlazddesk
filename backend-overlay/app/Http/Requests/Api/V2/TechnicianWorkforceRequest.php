<?php

namespace App\Http\Requests\Api\V2;

use App\Models\TechnicianDesignation;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class TechnicianWorkforceRequest extends FormRequest
{
    public function authorize(): bool { return $this->user()->can('manage', TechnicianDesignation::class); }
    public function rules(): array
    {
        return [
            'designation_id' => ['required', 'integer', Rule::exists('technician_designations', 'id')->where('is_active', true)],
            'workforce_type' => ['required', Rule::in(['employee', 'freelancer'])],
        ];
    }

    public function attributes(): array
    {
        return ['designation_id' => 'designation', 'workforce_type' => 'workforce classification'];
    }
}
