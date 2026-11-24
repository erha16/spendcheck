<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('transactions', function (Blueprint $table): void {
            $table->uuid('advance_event_key')->nullable()->unique('transactions_advance_event_key_unique');
            $table->index(['operational_advance_id', 'type', 'status'], 'transactions_advance_event_status_idx');
        });

        if (DB::connection()->getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE transactions ADD CONSTRAINT transactions_advance_return_fields_valid CHECK (type <> 'advance_return' OR (operational_advance_id IS NOT NULL AND advance_event_key IS NOT NULL AND payment_method_id IS NULL AND source_cash_account_id IS NULL AND vendor_id IS NULL AND fund_source_id IS NULL AND (status NOT IN ('posted', 'reversed') OR destination_cash_account_id IS NOT NULL)))");
            DB::statement("ALTER TABLE transactions ADD CONSTRAINT transactions_advance_repay_fields_valid CHECK (type <> 'advance_repay' OR (operational_advance_id IS NOT NULL AND advance_event_key IS NOT NULL AND payment_method_id IS NULL AND source_cash_account_id IS NOT NULL AND destination_cash_account_id IS NULL AND vendor_id IS NULL AND fund_source_id IS NULL AND status IN ('posted', 'reversed')))");
            DB::statement("ALTER TABLE cash_movements ADD CONSTRAINT movements_advance_return_inflow_valid CHECK (kind <> 'advance_return' OR (direction = 'in' AND transaction_id IS NOT NULL AND transfer_leg IS NULL))");
            DB::statement("ALTER TABLE cash_movements ADD CONSTRAINT movements_advance_repay_outflow_valid CHECK (kind <> 'advance_repay' OR (direction = 'out' AND transaction_id IS NOT NULL AND transfer_leg IS NULL))");
        }
    }

    public function down(): void
    {
        if (DB::connection()->getDriverName() === 'mysql') {
            DB::statement('ALTER TABLE cash_movements DROP CHECK movements_advance_repay_outflow_valid');
            DB::statement('ALTER TABLE cash_movements DROP CHECK movements_advance_return_inflow_valid');
            DB::statement('ALTER TABLE transactions DROP CHECK transactions_advance_repay_fields_valid');
            DB::statement('ALTER TABLE transactions DROP CHECK transactions_advance_return_fields_valid');
        }

        Schema::table('transactions', function (Blueprint $table): void {
            $table->dropIndex('transactions_advance_event_status_idx');
            $table->dropUnique('transactions_advance_event_key_unique');
            $table->dropColumn('advance_event_key');
        });
    }
};
