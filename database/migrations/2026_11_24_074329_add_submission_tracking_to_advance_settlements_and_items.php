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
        Schema::table('advance_settlements', function (Blueprint $table): void {
            $table->uuid('submission_key')->nullable()->unique('advance_settlements_submission_key_unique');
        });

        Schema::table('advance_settlement_items', function (Blueprint $table): void {
            $table->foreignId('submitted_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->index(['submitted_by', 'review_status'], 'asi_submitter_status_idx');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('advance_settlement_items', function (Blueprint $table): void {
            $table->dropIndex('asi_submitter_status_idx');
            $table->dropConstrainedForeignId('submitted_by');
        });

        Schema::table('advance_settlements', function (Blueprint $table): void {
            $table->dropUnique('advance_settlements_submission_key_unique');
            $table->dropColumn('submission_key');
        });
    }
};
