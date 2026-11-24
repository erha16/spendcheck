<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('transactions', function (Blueprint $table): void {
            $table->text('notes')->nullable();
            $table->foreignId('responsible_user_id')->nullable()->change();
            $table->foreignId('payment_method_id')->nullable()->change();
        });

        Schema::table('cash_movements', function (Blueprint $table): void {
            $table->string('transfer_leg', 12)->nullable();
            $table->unique(['transaction_id', 'transfer_leg'], 'cash_movements_transfer_leg_unique');
        });
    }

    public function down(): void
    {
        Schema::table('cash_movements', function (Blueprint $table): void {
            $table->dropUnique('cash_movements_transfer_leg_unique');
            $table->dropColumn('transfer_leg');
        });

        Schema::table('transactions', function (Blueprint $table): void {
            $table->dropColumn('notes');
        });
    }
};
