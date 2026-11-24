<?php

namespace App\Http\Requests;

use App\Models\OperationalAdvance;
use App\Models\User;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class PayAdvanceReimbursementRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        $user = $this->user();
        $advance = $this->route('operationalAdvance');

        return $user instanceof User && $advance instanceof OperationalAdvance
            && $user->hasRole('keuangan_sarpras')
            && $user->can('operational_advances.reimburse')
            && $user->can('transactions.post')
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
            'allow_negative' => ['sometimes', 'boolean'],
            'override_reason' => [Rule::requiredIf(fn (): bool => $this->boolean('allow_negative')), 'nullable', 'string', 'max:5000'],
        ];
    }
}
