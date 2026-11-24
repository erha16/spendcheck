<?php

namespace App\Http\Requests;

use App\Models\User;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ReviewAdvanceSettlementAdjustmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() instanceof User
            && $this->user()->hasRole('keuangan_sarpras')
            && $this->user()->can('operational_advances.settle');
    }

    /** @return array<string, ValidationRule|array<mixed>|string> */
    public function rules(): array
    {
        return [
            'decision' => ['required', Rule::in(['approved', 'rejected'])],
            'review_reason' => ['required_if:decision,rejected', 'nullable', 'string', 'min:5', 'max:5000'],
        ];
    }
}
