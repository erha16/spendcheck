<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::connection()->getDriverName() !== 'mysql') {
            return;
        }

        DB::statement('ALTER TABLE cash_movements ADD CONSTRAINT cash_movements_amount_positive CHECK (amount > 0)');
        DB::statement("ALTER TABLE cash_movements ADD CONSTRAINT cash_movements_direction_valid CHECK (direction IN ('in', 'out'))");
        DB::statement('ALTER TABLE opening_balance_requests ADD CONSTRAINT opening_balance_requests_amount_positive CHECK (amount > 0)');
    }

    public function down(): void
    {
        if (DB::connection()->getDriverName() !== 'mysql') {
            return;
        }

        DB::statement('ALTER TABLE opening_balance_requests DROP CHECK opening_balance_requests_amount_positive');
        DB::statement('ALTER TABLE cash_movements DROP CHECK cash_movements_direction_valid');
        DB::statement('ALTER TABLE cash_movements DROP CHECK cash_movements_amount_positive');
    }
};
