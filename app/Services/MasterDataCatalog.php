<?php

namespace App\Services;

use App\Models\Activity;
use App\Models\CashAccount;
use App\Models\Category;
use App\Models\FundSource;
use App\Models\PaymentMethod;
use App\Models\Subcategory;
use App\Models\Vendor;
use Illuminate\Database\Eloquent\Model;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class MasterDataCatalog
{
    /** @return array<string, array{label: string, model: class-string<Model>, fields: array<string, array<string, mixed>>, columns: array<string, string>, search: list<string>}> */
    public function definitions(): array
    {
        $code = ['type' => 'text', 'label' => 'Kode', 'rules' => ['required', 'string', 'max:50']];
        $name = ['type' => 'text', 'label' => 'Nama', 'rules' => ['required', 'string', 'max:255']];
        $description = ['type' => 'textarea', 'label' => 'Keterangan', 'rules' => ['nullable', 'string', 'max:5000']];

        return [
            'categories' => ['label' => 'Kategori', 'model' => Category::class, 'fields' => ['code' => $code, 'name' => $name, 'description' => $description], 'columns' => ['code' => 'Kode', 'name' => 'Nama', 'description' => 'Keterangan'], 'search' => ['code', 'name']],
            'subcategories' => ['label' => 'Subkategori', 'model' => Subcategory::class, 'fields' => ['category_id' => ['type' => 'category', 'label' => 'Kategori', 'rules' => ['required', 'integer', 'exists:categories,id']], 'code' => $code, 'name' => $name, 'description' => $description], 'columns' => ['category.name' => 'Kategori', 'code' => 'Kode', 'name' => 'Nama', 'description' => 'Keterangan'], 'search' => ['code', 'name']],
            'activities' => ['label' => 'Kegiatan', 'model' => Activity::class, 'fields' => ['code' => $code, 'name' => $name, 'description' => $description, 'starts_at' => ['type' => 'date', 'label' => 'Tanggal mulai', 'rules' => ['nullable', 'date']], 'ends_at' => ['type' => 'date', 'label' => 'Tanggal selesai', 'rules' => ['nullable', 'date', 'after_or_equal:starts_at']]], 'columns' => ['code' => 'Kode', 'name' => 'Nama', 'starts_at' => 'Mulai', 'ends_at' => 'Selesai'], 'search' => ['code', 'name', 'description']],
            'vendors' => ['label' => 'Vendor', 'model' => Vendor::class, 'fields' => ['name' => $name, 'type' => ['type' => 'text', 'label' => 'Jenis vendor', 'rules' => ['required', 'string', 'max:100']], 'contact' => ['type' => 'text', 'label' => 'Kontak', 'rules' => ['nullable', 'string', 'max:255']], 'phone' => ['type' => 'text', 'label' => 'Nomor telepon', 'rules' => ['nullable', 'string', 'max:30']], 'address' => ['type' => 'textarea', 'label' => 'Alamat', 'rules' => ['nullable', 'string', 'max:2000']]], 'columns' => ['name' => 'Nama', 'type' => 'Jenis', 'contact' => 'Kontak', 'phone' => 'Telepon'], 'search' => ['name', 'type', 'contact', 'phone']],
            'cash-accounts' => ['label' => 'Akun kas', 'model' => CashAccount::class, 'fields' => ['name' => $name, 'type' => ['type' => 'select', 'label' => 'Jenis akun', 'rules' => ['required', 'in:tunai,bank'], 'options' => ['tunai' => 'Tunai', 'bank' => 'Bank']], 'account_number' => ['type' => 'text', 'label' => 'Nomor rekening (opsional)', 'rules' => ['nullable', 'string', 'max:100']], 'bank_name' => ['type' => 'text', 'label' => 'Nama bank (opsional)', 'rules' => ['nullable', 'string', 'max:255']]], 'columns' => ['name' => 'Nama akun', 'type' => 'Jenis', 'account_number' => 'Nomor rekening', 'bank_name' => 'Bank'], 'search' => ['name', 'type', 'account_number', 'bank_name']],
            'fund-sources' => ['label' => 'Sumber dana', 'model' => FundSource::class, 'fields' => ['name' => $name, 'description' => $description], 'columns' => ['name' => 'Nama', 'description' => 'Keterangan'], 'search' => ['name', 'description']],
            'payment-methods' => ['label' => 'Metode pembayaran', 'model' => PaymentMethod::class, 'fields' => ['name' => $name, 'description' => $description], 'columns' => ['name' => 'Nama', 'description' => 'Keterangan'], 'search' => ['name', 'description']],
        ];
    }

    /** @return array{label: string, model: class-string<Model>, fields: array<string, array<string, mixed>>, columns: array<string, string>, search: list<string>} */
    public function get(string $resource): array
    {
        return $this->definitions()[$resource] ?? throw new NotFoundHttpException('Master data tidak ditemukan.');
    }

    public function permission(string $resource, string $action): string
    {
        $this->get($resource);

        return str_replace('-', '_', $resource).'.'.$action;
    }
}
