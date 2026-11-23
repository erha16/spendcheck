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

        $permissions = [];
        foreach ($permissionDefinitions as $name => [$displayName, $module]) {
            $permissions[$name] = Permission::updateOrCreate(['name' => $name], ['display_name' => $displayName, 'module' => $module]);
        }

        foreach ($roles as $name => [$displayName, $description]) {
            $role = Role::updateOrCreate(['name' => $name], ['display_name' => $displayName, 'description' => $description]);
            $assigned = match ($name) {
                'admin_system' => array_keys($permissionDefinitions),
                'petugas_sarpras', 'keuangan_sarpras', 'kepala_sarpras', 'divisi_keuangan' => ['departments.view'],
                default => [],
            };
            $role->permissions()->sync(array_map(fn (string $permission) => $permissions[$permission]->id, $assigned));
        }
    }
}
