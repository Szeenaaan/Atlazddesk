<?php

namespace App\Http\Requests\Api\V2;

use App\Models\TechnicianDesignation;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class DesignationRequest extends FormRequest
{
    public function authorize(): bool { return $this->user()->can('manage', TechnicianDesignation::class); }
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:100', Rule::unique('technician_designations')->ignore($this->route('id'))],
            'level' => ['required', 'integer', 'min:0', 'max:15'],
            'is_active' => ['required', 'boolean'],
        ];
    }
}
