<?php

namespace App\Http\Requests;

use App\Models\OperationalAdvance;
use App\Models\User;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ReceiveAdvanceReturnRequest extends FormRequest
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
            && $user->can('operational_advances.return')
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
        return ['cash_account_id' => ['required', 'integer', Rule::exists('cash_accounts', 'id')->where('is_active', true)]];
    }
}
