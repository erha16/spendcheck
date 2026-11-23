<?php

namespace App\Console\Commands;

use App\Models\Role;
use App\Models\User;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;

#[Signature('app:create-initial-administrator')]
#[Description('Create the first SpendCheck system administrator interactively.')]
class CreateInitialAdministrator extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        if (User::whereHas('roles', fn ($query) => $query->where('roles.name', 'admin_system'))->exists()) {
            $this->components->error('Administrator sistem sudah tersedia.');

            return self::FAILURE;
        }

        $name = $this->ask('Nama administrator');
        $email = $this->ask('Email administrator');
        $password = $this->secret('Kata sandi (minimal 12 karakter)');
        $confirmation = $this->secret('Ulangi kata sandi');
        $validator = Validator::make(compact('name', 'email', 'password'), [
            'name' => ['required', 'string', 'max:255'], 'email' => ['required', 'email', 'max:255', 'unique:users,email'], 'password' => ['required', 'string', 'min:12'],
        ]);

        if ($validator->fails() || $password !== $confirmation) {
            $this->components->error($validator->errors()->first() ?: 'Konfirmasi kata sandi tidak cocok.');

            return self::FAILURE;
        }

        $role = Role::where('name', 'admin_system')->first();
        if ($role === null) {
            $this->components->error('Role belum tersedia. Jalankan php artisan db:seed terlebih dahulu.');

            return self::FAILURE;
        }

        DB::transaction(function () use ($name, $email, $password, $role): void {
            $user = User::create(['name' => $name, 'email' => $email, 'password' => Hash::make($password), 'is_active' => true]);
            $user->roles()->attach($role);
        });
        $this->components->info('Administrator sistem berhasil dibuat.');

        return self::SUCCESS;
    }
}
