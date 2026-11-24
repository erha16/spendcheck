<?php

namespace App\Http\Requests;

use App\Models\Activity;
use App\Models\AdvanceSettlementItem;
use App\Models\Category;
use App\Models\OperationalAdvance;
use App\Models\User;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class ReviseAdvanceSettlementItemRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();
        $advance = $this->route('operationalAdvance');
        $item = $this->route('item');

        return $user instanceof User
            && $advance instanceof OperationalAdvance
            && $item instanceof AdvanceSettlementItem
            && (($advance->responsible_user_id === $user->id && $user->can('operational_advances.submit'))
                || ($user->hasRole('keuangan_sarpras') && $user->can('operational_advances.settle')));
    }

    /** @return array<string, ValidationRule|array<mixed>|string> */
    public function rules(): array
    {
        return [
            'occurred_at' => ['required', 'date_format:Y-m-d\\TH:i', 'before_or_equal:now'],
            'description' => ['required', 'string', 'max:1000'],
            'category_id' => ['required', 'integer', Rule::exists('categories', 'id')->where('is_active', true)],
            'subcategory_id' => ['nullable', 'integer', Rule::exists('subcategories', 'id')->where('is_active', true)],
            'activity_id' => ['nullable', 'integer', Rule::exists('activities', 'id')->where('is_active', true)],
            'amount' => ['required', 'numeric', 'string', 'regex:/^\\d{1,17}(\\.\\d{1,2})?$/', 'gt:0'],
            'attachments' => ['required', 'array', 'min:1'],
            'attachments.*' => ['required', 'file', 'mimes:jpg,jpeg,png,pdf,heic', 'extensions:jpg,jpeg,png,pdf,heic', 'max:10240'],
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator): void {
            $categoryId = $this->input('category_id');
            if (! is_numeric($categoryId) || $validator->errors()->has('category_id')) {
                return;
            }

            $category = Category::query()->withCount([
                'subcategories' => fn ($query) => $query->where('is_active', true),
            ])->where('is_active', true)->find($categoryId);
            if ($category === null) {
                return;
            }

            if ($category->subcategories_count > 0 && blank($this->input('subcategory_id'))) {
                $validator->errors()->add('subcategory_id', 'Subkategori wajib dipilih untuk kategori ini.');
            } elseif ($this->filled('subcategory_id') && ! $category->subcategories()
                ->whereKey($this->input('subcategory_id'))->where('is_active', true)->exists()) {
                $validator->errors()->add('subcategory_id', 'Subkategori harus aktif dan sesuai dengan kategori.');
            }

            $activityId = $this->input('activity_id');
            if (! empty($activityId) && ! Activity::query()->whereKey($activityId)->where('is_active', true)->exists()) {
                $validator->errors()->add('activity_id', 'Kegiatan harus aktif.');
            }
        }];
    }
}
