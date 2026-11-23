<?php

namespace Database\Seeders;

use App\Models\PaymentMethod;
use Illuminate\Database\Seeder;

class MasterDataSeeder extends Seeder
{
    public function run(): void
    {
        foreach (['Tunai', 'Transfer', 'QRIS', 'Pembayaran pribadi'] as $name) {
            PaymentMethod::firstOrCreate(['name' => $name], ['is_active' => true]);
        }
    }
}
