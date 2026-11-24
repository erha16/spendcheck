<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('transactions', function (Blueprint $table): void {
            $table->unique('reversal_of_id');
        });
        Schema::table('cash_movements', function (Blueprint $table): void {
            $table->unique('reversal_of_id');
        });

        Schema::create('transaction_revisions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('transaction_id')->constrained()->restrictOnDelete();
            $table->foreignId('replacement_transaction_id')->unique()->constrained('transactions')->restrictOnDelete();
            $table->unsignedInteger('revision_number');
            $table->text('reason');
            $table->json('before_values');
            $table->json('after_values');
            $table->foreignId('corrected_by')->constrained('users')->restrictOnDelete();
            $table->dateTime('corrected_at');
            $table->timestamps();
            $table->unique(['transaction_id', 'revision_number']);
            $table->index(['transaction_id', 'corrected_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('transaction_revisions');
        Schema::table('cash_movements', function (Blueprint $table): void {
            $table->dropUnique(['reversal_of_id']);
        });
        Schema::table('transactions', function (Blueprint $table): void {
            $table->dropUnique(['reversal_of_id']);
        });
    }
};
