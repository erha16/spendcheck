<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('transactions', function (Blueprint $table): void {
            $table->dateTime('submitted_at')->nullable();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->dateTime('reviewed_at')->nullable();
            $table->text('revision_reason')->nullable();
            $table->foreignId('rejected_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->dateTime('rejected_at')->nullable();
            $table->text('rejection_reason')->nullable();
            $table->foreignId('cancelled_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->dateTime('cancelled_at')->nullable();
            $table->text('cancellation_reason')->nullable();
            $table->boolean('negative_balance_override')->default(false);
            $table->decimal('negative_balance_shortfall', 19, 2)->default(0);
            $table->text('negative_balance_override_reason')->nullable();
            $table->foreignId('negative_balance_override_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->dateTime('negative_balance_override_at')->nullable();
        });

        Schema::table('transaction_items', function (Blueprint $table): void {
            $table->foreignId('category_id')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('transactions', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('reviewed_by');
            $table->dropConstrainedForeignId('rejected_by');
            $table->dropConstrainedForeignId('cancelled_by');
            $table->dropConstrainedForeignId('negative_balance_override_by');
            $table->dropColumn([
                'submitted_at', 'reviewed_at', 'revision_reason', 'rejected_at', 'rejection_reason', 'cancelled_at', 'cancellation_reason',
                'negative_balance_override', 'negative_balance_shortfall', 'negative_balance_override_reason', 'negative_balance_override_at',
            ]);
        });
    }
};
