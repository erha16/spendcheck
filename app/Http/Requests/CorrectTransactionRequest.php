<?php

namespace App\Http\Requests;

use App\Models\Transaction;
use App\Models\User;
use Illuminate\Validation\Rule;

class CorrectTransactionRequest extends SaveTransactionRequest
{
    public function authorize(): bool
    {
        $transaction = $this->route('transaction');

        return $this->user() instanceof User && $this->user()->hasRole('keuangan_sarpras') && $this->user()->can('transactions.correct')
            && $transaction instanceof Transaction && $transaction->status === 'posted';
    }

    public function rules(): array
    {
        return [...parent::rules(),
            'change_reason' => ['nullable'],
            'correction_reason' => ['required', 'string', 'min:5', 'max:5000'],
            'allow_negative' => ['nullable', 'boolean'],
            'override_reason' => [Rule::requiredIf(fn (): bool => $this->input('type') === 'expense' && $this->boolean('allow_negative')), 'nullable', 'string', 'max:5000'],
        ];
    }

    public function messages(): array
    {
        return [...parent::messages(),
            'correction_reason.required' => 'Alasan koreksi wajib diisi.',
            'override_reason.required' => 'Alasan override saldo negatif wajib diisi.',
        ];
    }
}
