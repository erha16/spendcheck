<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('transaction_number_sequences', function (Blueprint $table): void {
            $table->id();
            $table->string('type', 20);
            $table->char('period', 6);
            $table->unsignedInteger('last_number')->default(0);
            $table->timestamps();
            $table->unique(['type', 'period']);
        });

        Schema::create('transactions', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->string('number')->unique();
            $table->string('type', 20);
            $table->dateTime('occurred_at')->index();
            $table->text('description');
            $table->foreignId('responsible_user_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('payment_method_id')->constrained()->restrictOnDelete();
            $table->foreignId('source_cash_account_id')->nullable()->constrained('cash_accounts')->restrictOnDelete();
            $table->foreignId('destination_cash_account_id')->nullable()->constrained('cash_accounts')->restrictOnDelete();
            $table->foreignId('vendor_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('fund_source_id')->nullable()->constrained()->restrictOnDelete();
            $table->decimal('total_amount', 19, 2)->default(0);
            $table->string('status', 30)->default('draft')->index();
            $table->foreignId('posted_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->dateTime('posted_at')->nullable();
            $table->foreignId('reversal_of_id')->nullable()->constrained('transactions')->restrictOnDelete();
            $table->timestamps();
            $table->index(['type', 'occurred_at']);
            $table->index(['status', 'occurred_at']);
        });

        Schema::create('transaction_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('transaction_id')->constrained()->restrictOnDelete();
            $table->text('description');
            $table->foreignId('category_id')->constrained()->restrictOnDelete();
            $table->foreignId('subcategory_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('activity_id')->nullable()->constrained()->restrictOnDelete();
            $table->decimal('quantity', 18, 4);
            $table->string('unit', 50)->nullable();
            $table->decimal('unit_price', 19, 2);
            $table->decimal('subtotal', 19, 2);
            $table->timestamps();
            $table->index(['category_id', 'subcategory_id']);
        });

        Schema::create('opening_balance_requests', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('cash_account_id')->constrained()->restrictOnDelete();
            $table->decimal('amount', 19, 2);
            $table->dateTime('effective_at');
            $table->text('reason');
            $table->string('status', 20)->default('pending')->index();
            $table->foreignId('proposed_by')->constrained('users')->restrictOnDelete();
            $table->dateTime('proposed_at');
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->dateTime('reviewed_at')->nullable();
            $table->text('review_notes')->nullable();
            $table->timestamps();
            $table->index(['cash_account_id', 'status']);
        });

        Schema::create('cash_movements', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('cash_account_id')->constrained()->restrictOnDelete();
            $table->foreignId('transaction_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('opening_balance_request_id')->nullable()->unique()->constrained()->restrictOnDelete();
            $table->string('kind', 30);
            $table->string('direction', 3);
            $table->decimal('amount', 19, 2);
            $table->string('idempotency_key', 120)->unique();
            $table->dateTime('occurred_at');
            $table->dateTime('recorded_at');
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('reversal_of_id')->nullable()->constrained('cash_movements')->restrictOnDelete();
            $table->timestamps();
            $table->index(['cash_account_id', 'occurred_at', 'id']);
            $table->index(['transaction_id', 'kind']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cash_movements');
        Schema::dropIfExists('opening_balance_requests');
        Schema::dropIfExists('transaction_items');
        Schema::dropIfExists('transactions');
        Schema::dropIfExists('transaction_number_sequences');
    }
};
