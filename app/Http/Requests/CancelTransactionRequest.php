<?php

namespace App\Http\Requests;

use App\Models\Transaction;
use Illuminate\Foundation\Http\FormRequest;

class CancelTransactionRequest extends FormRequest
{
    public function authorize(): bool
    {
        $transaction = $this->route('transaction');

        return $this->user()?->can('transactions.cancel')
            || ($transaction instanceof Transaction
                && $transaction->created_by === $this->user()?->id
                && ($this->user()?->can('transactions.expense.create') ?? false));
    }

    public function rules(): array
    {
        return [
            'reason' => ['required', 'string', 'max:5000'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return ['reason.required' => 'Alasan pembatalan wajib diisi.'];
    }
}
