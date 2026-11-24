<?php

namespace App\Http\Requests;

use App\Models\Activity;
use App\Models\Category;
use App\Models\OperationalAdvance;
use App\Models\User;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class SubmitAdvanceSettlementRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();
        $advance = $this->route('operationalAdvance');

        return $user instanceof User
            && $advance instanceof OperationalAdvance
            && (($advance->responsible_user_id === $user->id && $user->can('operational_advances.submit'))
                || ($user->hasRole('keuangan_sarpras') && $user->can('operational_advances.settle')));
    }

    /** @return array<string, ValidationRule|array<mixed>|string> */
    public function rules(): array
    {
        return [
            'submission_key' => ['required', 'uuid'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.occurred_at' => ['required', 'date_format:Y-m-d\\TH:i', 'before_or_equal:now'],
            'items.*.description' => ['required', 'string', 'max:1000'],
            'items.*.category_id' => ['required', 'integer', Rule::exists('categories', 'id')->where('is_active', true)],
            'items.*.subcategory_id' => ['nullable', 'integer', Rule::exists('subcategories', 'id')->where('is_active', true)],
            'items.*.activity_id' => ['nullable', 'integer', Rule::exists('activities', 'id')->where('is_active', true)],
            'items.*.amount' => ['required', 'numeric', 'string', 'regex:/^\\d{1,17}(\\.\\d{1,2})?$/', 'gt:0'],
            'items.*.attachments' => ['required', 'array', 'min:1'],
            'items.*.attachments.*' => ['required', 'file', 'mimes:jpg,jpeg,png,pdf,heic', 'extensions:jpg,jpeg,png,pdf,heic', 'max:10240'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'items.required' => 'Minimal satu rincian pengeluaran wajib diisi.',
            'items.*.occurred_at.required' => 'Tanggal setiap pengeluaran wajib diisi.',
            'items.*.description.required' => 'Deskripsi setiap pengeluaran wajib diisi.',
            'items.*.category_id.required' => 'Kategori setiap pengeluaran wajib dipilih.',
            'items.*.amount.regex' => 'Nominal harus positif dan maksimal dua angka desimal.',
            'items.*.attachments.required' => 'Bukti untuk setiap item pengeluaran wajib dilampirkan.',
            'items.*.attachments.*.mimes' => 'Bukti harus berupa JPG, PNG, PDF, atau HEIC.',
            'items.*.attachments.*.extensions' => 'Ekstensi bukti tidak sesuai dengan isi file.',
            'items.*.attachments.*.max' => 'Ukuran setiap bukti maksimal 10 MB.',
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator): void {
            foreach ($this->input('items', []) as $index => $itemData) {
                if (! is_array($itemData) || ! isset($itemData['category_id'])
                    || $validator->errors()->has("items.$index.category_id")) {
                    continue;
                }

                $category = Category::query()->withCount([
                    'subcategories' => fn ($query) => $query->where('is_active', true),
                ])->where('is_active', true)->find($itemData['category_id']);
                if ($category === null) {
                    continue;
                }

                if ($category->subcategories_count > 0 && empty($itemData['subcategory_id'])) {
                    $validator->errors()->add("items.$index.subcategory_id", 'Subkategori wajib dipilih untuk kategori ini.');
                } elseif (! empty($itemData['subcategory_id']) && ! $category->subcategories()
                    ->whereKey($itemData['subcategory_id'])->where('is_active', true)->exists()) {
                    $validator->errors()->add("items.$index.subcategory_id", 'Subkategori harus aktif dan sesuai dengan kategori.');
                }

                if (! empty($itemData['activity_id']) && ! Activity::query()
                    ->whereKey($itemData['activity_id'])->where('is_active', true)->exists()) {
                    $validator->errors()->add("items.$index.activity_id", 'Kegiatan harus aktif.');
                }
            }
        }];
    }
}
