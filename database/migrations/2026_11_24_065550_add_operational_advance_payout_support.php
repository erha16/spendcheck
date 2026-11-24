<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('transactions', function (Blueprint $table): void {
            $table->foreignId('operational_advance_id')->nullable()
                ->constrained('operational_advances')->restrictOnDelete();
            $table->index(['operational_advance_id', 'type'], 'transactions_advance_type_idx');
            $table->foreignId('payment_method_id')->nullable()->change();
        });

    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (DB::table('transactions')
            ->where('type', 'advance_payout')
            ->whereNull('payment_method_id')
            ->exists()) {
            throw new RuntimeException('Pencairan bon tersimpan; migrasi payout tidak dapat dibatalkan tanpa mengubah histori.');
        }

        Schema::table('transactions', function (Blueprint $table): void {
            $table->dropIndex('transactions_advance_type_idx');
            $table->dropConstrainedForeignId('operational_advance_id');
            $table->foreignId('payment_method_id')->nullable(false)->change();
        });
    }
};
