<?php

namespace App\Http\Requests;

use App\Models\User;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ReviewAdvanceSettlementItemRequest extends FormRequest
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
            'decision' => ['required', Rule::in(['accepted', 'revision_requested', 'rejected'])],
            'reason' => ['required_if:decision,revision_requested,rejected', 'nullable', 'string', 'min:5', 'max:5000'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'decision.required' => 'Keputusan item wajib dipilih.',
            'decision.in' => 'Keputusan item tidak valid.',
            'reason.required_if' => 'Alasan wajib diisi untuk permintaan revisi atau penolakan.',
        ];
    }
}
