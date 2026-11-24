<?php

namespace App\Http\Requests;

use App\Models\OperationalAdvance;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class ApproveOperationalAdvanceRequest extends FormRequest
{
    public function authorize(): bool
    {
        $advance = $this->route('operationalAdvance');
        $user = $this->user();

        return $user?->can('operational_advances.approve')
            && $advance instanceof OperationalAdvance
            && ! $advance->isParticipant($user);
    }

    /** @return array<string, ValidationRule|array<mixed>|string> */
    public function rules(): array
    {
        return [
            'approved_amount' => ['required', 'numeric', 'string', 'regex:/^\d{1,17}(\.\d{1,2})?$/', 'gt:0'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'approved_amount.required' => 'Nominal persetujuan wajib diisi.',
            'approved_amount.regex' => 'Nominal persetujuan harus positif dan maksimal dua angka desimal.',
        ];
    }
}
