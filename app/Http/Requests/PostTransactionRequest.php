<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class PostTransactionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('transactions.post') ?? false;
    }

    public function rules(): array
    {
        return [
            'allow_negative' => ['sometimes', 'boolean'],
            'override_reason' => ['required_if:allow_negative,1,true', 'nullable', 'string', 'max:5000'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return ['override_reason.required_if' => 'Alasan override saldo negatif wajib diisi.'];
    }
}
