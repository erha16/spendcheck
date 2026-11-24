<?php

namespace App\Http\Requests;

use App\Models\OperationalAdvance;
use App\Models\User;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class DisburseOperationalAdvanceRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();
        $advance = $this->route('operationalAdvance');

        return $user instanceof User
            && $advance instanceof OperationalAdvance
            && $user->can('operational_advances.disburse')
            && $user->can('transactions.post')
            && ! $advance->isParticipant($user);
    }

    /** @return array<string, ValidationRule|array<mixed>|string> */
    public function rules(): array
    {
        return [
            'cash_account_id' => [
                'required',
                'integer',
                Rule::exists('cash_accounts', 'id')->where('is_active', true),
            ],
            'occurred_at' => ['required', 'date_format:Y-m-d\\TH:i', 'before_or_equal:now'],
            'allow_negative' => ['sometimes', 'boolean'],
            'override_reason' => [Rule::requiredIf(fn (): bool => $this->boolean('allow_negative')), 'nullable', 'string', 'max:5000'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'cash_account_id.required' => 'Rekening kas pencairan wajib dipilih.',
            'cash_account_id.exists' => 'Rekening kas tidak aktif atau tidak tersedia.',
            'occurred_at.required' => 'Tanggal pencairan aktual wajib diisi.',
            'occurred_at.before_or_equal' => 'Tanggal pencairan tidak boleh di masa depan.',
            'override_reason.required' => 'Alasan override saldo negatif wajib diisi.',
        ];
    }
}
