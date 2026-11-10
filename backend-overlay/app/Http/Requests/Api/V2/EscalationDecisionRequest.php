<?php

namespace App\Http\Requests\Api\V2;

use App\Models\TicketEscalation;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class EscalationDecisionRequest extends FormRequest
{
    public function authorize(): bool { return $this->user()->can('review', TicketEscalation::class); }
    public function rules(): array
    {
        return [
            'decision' => ['required', Rule::in(['approved', 'rejected'])],
            'selected_employee_id' => ['required_if:decision,approved', 'prohibited_if:decision,rejected', 'integer'],
            'admin_note' => ['required_if:decision,rejected', 'nullable', 'string', 'max:5000'],
        ];
    }

    public function attributes(): array
    {
        return ['selected_employee_id' => 'replacement employee', 'admin_note' => 'administrative note'];
    }
}
