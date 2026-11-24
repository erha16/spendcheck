<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::connection()->getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE transactions ADD CONSTRAINT transactions_advance_payout_fields_valid CHECK (type <> 'advance_payout' OR (operational_advance_id IS NOT NULL AND payment_method_id IS NULL AND source_cash_account_id IS NOT NULL AND destination_cash_account_id IS NULL AND vendor_id IS NULL AND fund_source_id IS NULL))");
            DB::statement("ALTER TABLE cash_movements ADD CONSTRAINT movements_advance_payout_outflow_valid CHECK (kind <> 'advance_payout' OR (direction = 'out' AND transaction_id IS NOT NULL AND transfer_leg IS NULL))");
        }
    }

    public function down(): void
    {
        if (DB::connection()->getDriverName() === 'mysql') {
            DB::statement('ALTER TABLE cash_movements DROP CHECK movements_advance_payout_outflow_valid');
            DB::statement('ALTER TABLE transactions DROP CHECK transactions_advance_payout_fields_valid');
        }
    }
};
