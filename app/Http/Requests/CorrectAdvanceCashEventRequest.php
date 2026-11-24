<?php

namespace App\Http\Requests;

use App\Models\OperationalAdvance;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CorrectAdvanceCashEventRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        $user = $this->user();
        $advance = $this->route('operationalAdvance');
        $transaction = $this->route('transaction');
        $eventPermission = $transaction instanceof Transaction && $transaction->type === 'advance_return'
            ? 'operational_advances.return'
            : 'operational_advances.reimburse';

        return $user instanceof User && $advance instanceof OperationalAdvance && $transaction instanceof Transaction
            && in_array($transaction->type, ['advance_return', 'advance_repay'], true)
            && $user->hasRole('keuangan_sarpras') && $user->can($eventPermission)
            && $user->can('transactions.correct') && $user->can('transactions.post')
            && ! $advance->isParticipant($user);
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'advance_event_key' => ['required', 'uuid'],
            'cash_account_id' => ['required', 'integer', Rule::exists('cash_accounts', 'id')->where('is_active', true)],
            'amount' => ['required', 'numeric', 'string', 'regex:/^\d{1,17}(\.\d{1,2})?$/', 'gt:0'],
            'occurred_at' => ['required', 'date_format:Y-m-d\\TH:i', 'before_or_equal:now'],
            'correction_reason' => ['required', 'string', 'min:5', 'max:5000'],
            'allow_negative' => ['sometimes', 'boolean'],
            'override_reason' => [Rule::requiredIf(fn (): bool => $this->boolean('allow_negative')), 'nullable', 'string', 'max:5000'],
        ];
    }
}
