<?php

namespace App\Http\Requests;

use App\Models\OperationalAdvance;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class CancelOperationalAdvanceRequest extends FormRequest
{
    public function authorize(): bool
    {
        $advance = $this->route('operationalAdvance');
        $user = $this->user();

        if (! $advance instanceof OperationalAdvance || $user === null) {
            return false;
        }

        if ($user->can('operational_advances.cancel')) {
            return true;
        }

        if ($user->can('operational_advances.cancel_own')) {
            abort_unless($advance->isParticipant($user), 404);

            return true;
        }

        return false;
    }

    /** @return array<string, ValidationRule|array<mixed>|string> */
    public function rules(): array
    {
        return ['reason' => ['required', 'string', 'max:5000']];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return ['reason.required' => 'Alasan pembatalan wajib diisi.'];
    }
}
