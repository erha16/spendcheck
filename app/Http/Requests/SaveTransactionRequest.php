<?php

namespace App\Http\Requests;

use App\Models\Category;
use App\Models\Subcategory;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class SaveTransactionRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();
        if (! $user instanceof User) {
            return false;
        }

        $transaction = $this->route('transaction');
        if ($transaction instanceof Transaction) {
            return $user->can('transactions.manage')
                || ($transaction->created_by === $user->id && $user->can('transactions.expense.create'));
        }

        $type = $this->string('type')->toString();

        return in_array($type, ['income', 'expense', 'transfer'], true) && $user->can('transactions.'.$type.'.create');
    }

    public function rules(): array
    {
        $transaction = $this->route('transaction');
        $type = $transaction instanceof Transaction ? $transaction->type : $this->string('type')->toString();
        $isExpense = $type === 'expense';
        $isIncome = $type === 'income';
        $isTransfer = $type === 'transfer';
        $isPetugas = $this->user()?->hasRole('petugas_sarpras') ?? false;
        $accountRule = $isPetugas
            ? Rule::exists('cash_accounts', 'id')->where('is_active', true)
            : Rule::exists('cash_accounts', 'id');

        return [
            'type' => ['required', Rule::in($transaction instanceof Transaction ? [$type] : ['income', 'expense', 'transfer'])],
            'occurred_at' => ['required', 'date'],
            'description' => ['required', 'string', 'max:5000'],
            'responsible_user_id' => [$isTransfer ? 'nullable' : 'required', 'nullable', 'integer', Rule::exists('users', 'id')->where('is_active', true)],
            'payment_method_id' => [$isTransfer ? 'nullable' : 'required', 'nullable', 'integer', Rule::exists('payment_methods', 'id')->where('is_active', true)],
            'source_cash_account_id' => [$isExpense || $isTransfer ? 'required' : 'nullable', 'integer', $accountRule],
            'destination_cash_account_id' => [$isIncome || $isTransfer ? 'required' : 'nullable', 'integer', Rule::exists('cash_accounts', 'id')->where('is_active', true)],
            'vendor_id' => ['nullable', 'integer', Rule::exists('vendors', 'id')->where('is_active', true)],
            'fund_source_id' => [$isExpense ? 'required' : 'nullable', 'integer', Rule::exists('fund_sources', 'id')->where('is_active', true)],
            'income_mode' => [$isIncome ? 'required' : 'nullable', Rule::in(['direct', 'items'])],
            'income_amount' => [$isIncome && $this->input('income_mode') === 'direct' ? 'required' : 'nullable', 'numeric', 'string', 'regex:/^\d{1,17}(\.\d{1,2})?$/', 'gt:0'],
            'transfer_amount' => [$isTransfer ? 'required' : 'nullable', 'numeric', 'string', 'regex:/^\d{1,17}(\.\d{1,2})?$/', 'gt:0'],
            'notes' => ['nullable', 'string', 'max:5000'],
            'items' => [$isExpense || ($isIncome && $this->input('income_mode') === 'items') ? 'required' : 'nullable', 'array', 'min:1'],
            'items.*.description' => ['required_with:items', 'string', 'max:1000'],
            'items.*.category_id' => [$isExpense ? 'required' : 'nullable', 'nullable', 'integer', Rule::exists('categories', 'id')->where('is_active', true)],
            'items.*.subcategory_id' => ['nullable', 'integer', Rule::exists('subcategories', 'id')->where('is_active', true)],
            'items.*.activity_id' => ['nullable', 'integer', Rule::exists('activities', 'id')->where('is_active', true)],
            'items.*.quantity' => [$isExpense ? 'required' : 'nullable', 'numeric', 'gt:0', 'decimal:0,4'],
            'items.*.unit' => [$isExpense ? 'required' : 'nullable', 'nullable', 'string', 'max:50'],
            'items.*.unit_price' => [$isExpense || ($isIncome && $this->input('income_mode') === 'items') ? 'required' : 'nullable', 'numeric', 'string', 'regex:/^\d{1,17}(\.\d{1,2})?$/', 'gt:0'],
            'attachments' => ['nullable', 'array'],
            'attachments.*' => ['file', 'mimes:jpg,jpeg,png,pdf,heic', 'extensions:jpg,jpeg,png,pdf,heic', 'max:10240'],
            'change_reason' => [$this->requiresChangeReason() ? 'required' : 'nullable', 'nullable', 'string', 'max:5000'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'type.required' => 'Jenis transaksi wajib dipilih.',
            'type.in' => 'Jenis transaksi tidak valid.',
            'occurred_at.required' => 'Tanggal transaksi wajib diisi.',
            'occurred_at.date' => 'Tanggal transaksi tidak valid.',
            'description.required' => 'Deskripsi transaksi wajib diisi.',
            'responsible_user_id.required' => 'Penanggung jawab wajib dipilih.',
            'responsible_user_id.exists' => 'Penanggung jawab harus pengguna aktif.',
            'payment_method_id.required' => 'Metode pembayaran wajib dipilih.',
            'payment_method_id.exists' => 'Metode pembayaran harus aktif.',
            'source_cash_account_id.required' => 'Akun kas sumber wajib dipilih.',
            'destination_cash_account_id.required' => 'Akun kas tujuan wajib dipilih.',
            'fund_source_id.required' => 'Sumber dana wajib dipilih untuk pengeluaran.',
            'fund_source_id.exists' => 'Sumber dana harus aktif.',
            'income_amount.required' => 'Nilai pemasukan wajib diisi.',
            'income_amount.regex' => 'Nilai pemasukan harus berupa nominal dengan maksimal dua angka desimal.',
            'income_amount.gt' => 'Nilai pemasukan harus lebih dari nol.',
            'transfer_amount.required' => 'Nominal transfer wajib diisi.',
            'transfer_amount.regex' => 'Nominal transfer harus berupa angka dengan maksimal dua angka desimal.',
            'transfer_amount.gt' => 'Nominal transfer harus lebih besar dari nol.',
            'items.required' => 'Minimal satu rincian transaksi wajib diisi.',
            'items.min' => 'Minimal satu rincian transaksi wajib diisi.',
            'items.*.description.required_with' => 'Deskripsi setiap rincian wajib diisi.',
            'items.*.category_id.required' => 'Kategori setiap item pengeluaran wajib dipilih.',
            'items.*.quantity.required' => 'Kuantitas setiap item wajib diisi.',
            'items.*.unit.required' => 'Satuan setiap item wajib diisi.',
            'items.*.unit_price.required' => 'Harga setiap item wajib diisi.',
            'attachments.*.mimes' => 'Bukti harus berupa JPG, PNG, PDF, atau HEIC.',
            'attachments.*.extensions' => 'Ekstensi bukti tidak sesuai dengan isi file.',
            'attachments.*.max' => 'Ukuran setiap bukti maksimal 10 MB.',
            'change_reason.required' => 'Alasan perubahan wajib diisi untuk transaksi pengguna lain.',
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator): void {
            $transaction = $this->route('transaction');
            $type = $transaction instanceof Transaction ? $transaction->type : $this->input('type');
            if ($validator->errors()->hasAny(['type', 'items'])) {
                return;
            }

            if ($type === 'transfer') {
                $sourceAccountId = $this->input('source_cash_account_id');
                $destinationAccountId = $this->input('destination_cash_account_id');
                if (is_scalar($sourceAccountId) && is_scalar($destinationAccountId)
                    && (string) $sourceAccountId === (string) $destinationAccountId) {
                    $validator->errors()->add('destination_cash_account_id', 'Akun kas sumber dan tujuan harus berbeda.');
                }

                return;
            }

            if ($type !== 'expense') {
                return;
            }

            foreach ($this->input('items', []) as $index => $item) {
                if (! is_array($item) || ! isset($item['category_id']) || $validator->errors()->has("items.$index.category_id")) {
                    continue;
                }

                $category = Category::query()->withCount(['subcategories' => fn ($query) => $query->where('is_active', true)])
                    ->where('is_active', true)
                    ->find($item['category_id']);
                if ($category === null) {
                    continue;
                }

                if ($category->subcategories_count > 0 && empty($item['subcategory_id'])) {
                    $validator->errors()->add("items.$index.subcategory_id", 'Subkategori wajib dipilih untuk kategori ini.');

                    continue;
                }

                if (! empty($item['subcategory_id']) && ! Subcategory::query()
                    ->whereKey($item['subcategory_id'])
                    ->where('category_id', $category->id)
                    ->where('is_active', true)
                    ->exists()) {
                    $validator->errors()->add("items.$index.subcategory_id", 'Subkategori harus aktif dan sesuai dengan kategori.');
                }
            }
        }];
    }

    private function requiresChangeReason(): bool
    {
        $transaction = $this->route('transaction');

        return $transaction instanceof Transaction
            && $transaction->created_by !== $this->user()?->id
            && ($this->user()?->can('transactions.manage') ?? false);
    }
}
