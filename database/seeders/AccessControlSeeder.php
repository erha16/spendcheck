<?php

namespace Database\Seeders;

use App\Models\Permission;
use App\Models\Role;
use Illuminate\Database\Seeder;

class AccessControlSeeder extends Seeder
{
    public function run(): void
    {
        $roles = [
            'admin_system' => ['Administrator Sistem', 'Pengelolaan akses dan konfigurasi aplikasi.'],
            'petugas_sarpras' => ['Petugas Sarpras', 'Pengelolaan operasional sarana dan prasarana.'],
            'driver' => ['Driver', 'Akses operasional pengemudi.'],
            'keuangan_sarpras' => ['Keuangan Sarpras', 'Akses pencatatan keuangan sarana dan prasarana.'],
            'kepala_sarpras' => ['Kepala Sarpras', 'Akses pemantauan sarana dan prasarana.'],
            'divisi_keuangan' => ['Divisi Keuangan', 'Akses keuangan sesuai penugasan.'],
        ];
        $permissionDefinitions = [
            'users.view' => ['Lihat pengguna', 'users'], 'users.create' => ['Buat pengguna', 'users'],
            'users.update' => ['Ubah pengguna', 'users'], 'users.deactivate' => ['Aktifkan/nonaktifkan pengguna', 'users'],
            'users.reset_password' => ['Reset kata sandi pengguna', 'users'], 'roles.view' => ['Lihat role', 'roles'],
            'roles.assign' => ['Atur role pengguna', 'roles'], 'departments.view' => ['Lihat unit kerja', 'departments'],
            'departments.manage' => ['Kelola unit kerja', 'departments'], 'audit_logs.view' => ['Lihat audit log', 'audit_logs'],
        ];
        foreach (['categories' => 'kategori', 'subcategories' => 'subkategori', 'activities' => 'kegiatan', 'vendors' => 'vendor', 'cash_accounts' => 'akun kas', 'fund_sources' => 'sumber dana', 'payment_methods' => 'metode pembayaran'] as $module => $label) {
            $permissionDefinitions[$module.'.view'] = ['Lihat '.$label, $module];
            $permissionDefinitions[$module.'.manage'] = ['Kelola '.$label, $module];
        }
        $permissionDefinitions['transactions.view'] = ['Lihat transaksi', 'transactions'];
        $permissionDefinitions['transactions.expense.create'] = ['Buat pengeluaran', 'transactions'];
        $permissionDefinitions['transactions.income.create'] = ['Buat pemasukan', 'transactions'];
        $permissionDefinitions['transactions.transfer.create'] = ['Buat transfer antar akun kas', 'transactions'];
        $permissionDefinitions['transactions.manage'] = ['Kelola transaksi', 'transactions'];
        $permissionDefinitions['transactions.review'] = ['Periksa transaksi', 'transactions'];
        $permissionDefinitions['transactions.post'] = ['Posting transaksi', 'transactions'];
        $permissionDefinitions['transactions.cancel'] = ['Batalkan draft transaksi', 'transactions'];
        $permissionDefinitions['transactions.correct'] = ['Koreksi transaksi posted', 'transactions'];
        $permissionDefinitions['transactions.negative_balance.override'] = ['Override saldo negatif', 'transactions'];
        $permissionDefinitions['cashflow.view'] = ['Lihat arus kas', 'cashflow'];
        $permissionDefinitions['cash_accounts.opening_balance.propose'] = ['Ajukan saldo awal', 'cash_accounts.opening_balance'];
        $permissionDefinitions['cash_accounts.opening_balance.approve'] = ['Tinjau saldo awal', 'cash_accounts.opening_balance'];
        $permissionDefinitions['operational_advances.view'] = ['Lihat seluruh bon operasional', 'operational_advances'];
        $permissionDefinitions['operational_advances.view_own'] = ['Lihat bon sendiri', 'operational_advances'];
        $permissionDefinitions['operational_advances.create'] = ['Buat bon operasional', 'operational_advances'];
        $permissionDefinitions['operational_advances.submit'] = ['Ajukan bon operasional', 'operational_advances'];
        $permissionDefinitions['operational_advances.review'] = ['Periksa bon operasional', 'operational_advances'];
        $permissionDefinitions['operational_advances.approve'] = ['Setujui bon operasional', 'operational_advances'];
        $permissionDefinitions['operational_advances.disburse'] = ['Cairkan bon operasional', 'operational_advances'];
        $permissionDefinitions['operational_advances.settle'] = ['Periksa pertanggungjawaban bon', 'operational_advances'];
        $permissionDefinitions['operational_advances.return'] = ['Catat pengembalian sisa bon', 'operational_advances'];
        $permissionDefinitions['operational_advances.reimburse'] = ['Bayar penggantian bon', 'operational_advances'];
        $permissionDefinitions['operational_advances.cancel'] = ['Batalkan bon operasional', 'operational_advances'];
        $permissionDefinitions['operational_advances.cancel_own'] = ['Batalkan draft bon sendiri', 'operational_advances'];
        $permissionDefinitions['operational_advances.manage_all'] = ['Kelola draft seluruh bon', 'operational_advances'];
        $permissionDefinitions['operational_advances.report'] = ['Lihat laporan bon operasional', 'operational_advances'];

        $permissions = [];
        foreach ($permissionDefinitions as $name => [$displayName, $module]) {
            $permissions[$name] = Permission::updateOrCreate(['name' => $name], ['display_name' => $displayName, 'module' => $module]);
        }

        foreach ($roles as $name => [$displayName, $description]) {
            $role = Role::updateOrCreate(['name' => $name], ['display_name' => $displayName, 'description' => $description]);
            $assigned = match ($name) {
                'admin_system', 'keuangan_sarpras' => [],
                'petugas_sarpras' => ['categories.view', 'subcategories.view', 'activities.view', 'vendors.view', 'payment_methods.view'],
                'driver' => ['activities.view'],
                'kepala_sarpras', 'divisi_keuangan' => ['categories.view', 'subcategories.view', 'activities.view', 'vendors.view', 'cash_accounts.view', 'fund_sources.view', 'payment_methods.view'],
                default => [],
            };
            $assigned = array_merge($assigned, match ($name) {
                'admin_system' => array_values(array_diff(array_keys($permissionDefinitions), [
                    'transactions.view', 'transactions.expense.create', 'transactions.income.create', 'transactions.transfer.create', 'transactions.manage',
                    'transactions.review', 'transactions.post', 'transactions.cancel', 'transactions.correct', 'transactions.negative_balance.override',
                    'cashflow.view', 'cash_accounts.opening_balance.propose', 'cash_accounts.opening_balance.approve',
                    'operational_advances.view', 'operational_advances.view_own', 'operational_advances.create', 'operational_advances.submit',
                    'operational_advances.review', 'operational_advances.approve', 'operational_advances.disburse', 'operational_advances.settle',
                    'operational_advances.return', 'operational_advances.reimburse', 'operational_advances.cancel', 'operational_advances.cancel_own',
                    'operational_advances.manage_all', 'operational_advances.report',
                ])),
                'petugas_sarpras', 'keuangan_sarpras', 'kepala_sarpras', 'divisi_keuangan' => ['departments.view'],
                default => [],
            });
            $assigned = array_merge($assigned, match ($name) {
                'petugas_sarpras' => [
                    'transactions.view', 'transactions.expense.create',
                    'operational_advances.view_own', 'operational_advances.create', 'operational_advances.submit', 'operational_advances.cancel_own',
                ],
                'driver' => [
                    'operational_advances.view_own', 'operational_advances.create', 'operational_advances.submit', 'operational_advances.cancel_own',
                ],
                'keuangan_sarpras' => [
                    'transactions.view', 'transactions.expense.create', 'transactions.income.create', 'transactions.transfer.create', 'transactions.manage',
                    'transactions.review', 'transactions.post', 'transactions.cancel', 'transactions.correct', 'transactions.negative_balance.override',
                    'cashflow.view', 'cash_accounts.opening_balance.propose',
                    'operational_advances.view', 'operational_advances.create', 'operational_advances.submit', 'operational_advances.review',
                    'operational_advances.approve', 'operational_advances.disburse', 'operational_advances.settle', 'operational_advances.return',
                    'operational_advances.reimburse', 'operational_advances.cancel', 'operational_advances.manage_all', 'operational_advances.report',
                ],
                'kepala_sarpras', 'divisi_keuangan' => [
                    'transactions.view', 'cashflow.view', 'operational_advances.view', 'operational_advances.report',
                ],
                default => [],
            });
            if ($name === 'kepala_sarpras') {
                $assigned[] = 'cash_accounts.opening_balance.approve';
            }
            if (in_array($name, ['admin_system', 'keuangan_sarpras'], true)) {
                foreach (['categories', 'subcategories', 'activities', 'vendors', 'cash_accounts', 'fund_sources', 'payment_methods'] as $module) {
                    $assigned[] = $module.'.view';
                    $assigned[] = $module.'.manage';
                }
            }
            $assignedIds = array_map(fn (string $permission): int => $permissions[$permission]->id, $assigned);
            $phaseTwoIds = collect($permissions)->filter(fn (Permission $permission): bool => in_array($permission->module, ['categories', 'subcategories', 'activities', 'vendors', 'cash_accounts', 'fund_sources', 'payment_methods'], true))->pluck('id')->all();
            $role->permissions()->detach(array_diff($phaseTwoIds, $assignedIds));
            $role->permissions()->syncWithoutDetaching($assignedIds);
        }
    }
}
