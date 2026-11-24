<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ReviewTransactionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('transactions.review') ?? false;
    }

    public function rules(): array
    {
        return [
            'decision' => ['required', Rule::in(['revision', 'reject'])],
            'reason' => ['required', 'string', 'max:5000'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'decision.required' => 'Tindakan pemeriksaan wajib dipilih.',
            'decision.in' => 'Tindakan pemeriksaan tidak valid.',
            'reason.required' => 'Alasan wajib diisi.',
            'reason.max' => 'Alasan maksimal 5.000 karakter.',
        ];
    }
}
