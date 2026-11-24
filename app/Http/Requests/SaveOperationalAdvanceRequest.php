<?php

namespace App\Http\Requests;

use App\Models\OperationalAdvance;
use App\Models\User;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SaveOperationalAdvanceRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();
        $advance = $this->route('operationalAdvance');

        if (! $user instanceof User || ! $user->can('operational_advances.create')) {
            return false;
        }

        if (! $advance instanceof OperationalAdvance) {
            return true;
        }

        return $user->can('operational_advances.manage_all') || $advance->isParticipant($user);
    }

    /** @return array<string, ValidationRule|array<mixed>|string> */
    public function rules(): array
    {
        $user = $this->user();
        $canCreateForOthers = $user?->hasRole('keuangan_sarpras') ?? false;
        $mandated = $canCreateForOthers && (
            (int) $this->input('requester_user_id', $user?->id) !== $user?->id
            || (int) $this->input('responsible_user_id', $user?->id) !== $user?->id
        );

        return [
            'purpose' => ['required', 'string', 'max:5000'],
            'requested_amount' => ['required', 'numeric', 'string', 'regex:/^\d{1,17}(\.\d{1,2})?$/', 'gt:0'],
            'requester_user_id' => [$canCreateForOthers ? 'sometimes' : 'prohibited', 'integer', Rule::exists('users', 'id')->where('is_active', true)],
            'responsible_user_id' => [$canCreateForOthers ? 'sometimes' : 'prohibited', 'integer', Rule::exists('users', 'id')->where('is_active', true)],
            'mandate_reason' => [$mandated ? 'required' : 'nullable', 'string', 'max:5000'],
            'activity_id' => ['nullable', 'integer', Rule::exists('activities', 'id')->where('is_active', true)],
            'planned_cash_account_id' => ['required', 'integer', Rule::exists('cash_accounts', 'id')->where('is_active', true)],
            'due_date' => ['required', 'date'],
            'attachments' => ['nullable', 'array'],
            'attachments.*' => ['file', 'mimes:jpg,jpeg,png,pdf,heic', 'extensions:jpg,jpeg,png,pdf,heic', 'max:10240'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'purpose.required' => 'Tujuan bon wajib diisi.',
            'requested_amount.required' => 'Nominal yang diajukan wajib diisi.',
            'requested_amount.regex' => 'Nominal harus positif dan maksimal dua angka desimal.',
            'mandate_reason.required' => 'Alasan mandat wajib diisi untuk pengajuan atas nama pengguna lain.',
            'planned_cash_account_id.required' => 'Akun kas rencana wajib dipilih.',
            'due_date.required' => 'Tanggal pertanggungjawaban wajib diisi.',
            'attachments.*.max' => 'Ukuran setiap lampiran maksimal 10 MB.',
        ];
    }
}
