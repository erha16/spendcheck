<?php

namespace App\Http\Requests;

use App\Models\OperationalAdvance;
use App\Models\User;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CorrectOperationalAdvancePayoutRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();
        $advance = $this->route('operationalAdvance');

        return $user instanceof User
            && $advance instanceof OperationalAdvance
            && $user->hasRole('keuangan_sarpras')
            && $user->can('transactions.correct')
            && $user->can('transactions.post')
            && $user->can('operational_advances.disburse')
            && ! $advance->isParticipant($user);
    }

    /** @return array<string, ValidationRule|array<mixed>|string> */
    public function rules(): array
    {
        return [
            'cash_account_id' => ['required', 'integer', Rule::exists('cash_accounts', 'id')->where('is_active', true)],
            'occurred_at' => ['required', 'date_format:Y-m-d\\TH:i', 'before_or_equal:now'],
            'correction_reason' => ['required', 'string', 'min:5', 'max:5000'],
            'allow_negative' => ['sometimes', 'boolean'],
            'override_reason' => [Rule::requiredIf(fn (): bool => $this->boolean('allow_negative')), 'nullable', 'string', 'max:5000'],
        ];
    }
}
