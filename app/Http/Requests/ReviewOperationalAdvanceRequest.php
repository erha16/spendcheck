<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ReviewOperationalAdvanceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('operational_advances.review') ?? false;
    }

    /** @return array<string, ValidationRule|array<mixed>|string> */
    public function rules(): array
    {
        return [
            'decision' => ['required', Rule::in(['revision_requested', 'rejected'])],
            'reason' => ['required', 'string', 'max:5000'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'decision.required' => 'Keputusan pemeriksaan wajib dipilih.',
            'decision.in' => 'Keputusan pemeriksaan tidak valid.',
            'reason.required' => 'Alasan revisi atau penolakan wajib diisi.',
        ];
    }
}
