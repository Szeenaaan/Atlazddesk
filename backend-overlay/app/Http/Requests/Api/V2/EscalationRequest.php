<?php

namespace App\Http\Requests\Api\V2;

use Illuminate\Foundation\Http\FormRequest;

class EscalationRequest extends FormRequest
{
    public function authorize(): bool { return $this->user()->role === 'technician'; }
    public function rules(): array { return ['reason' => ['required', 'string', 'max:5000']]; }
}
