<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $this->createIfMissing('advance_number_sequences', function (Blueprint $table): void {
            $table->id();
            $table->char('period', 6)->unique();
            $table->unsignedInteger('last_number')->default(0);
            $table->timestamps();
        });

        $this->createIfMissing('operational_advances', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->string('number')->unique();
            $table->string('status', 30)->default('draft')->index();
            $table->text('purpose');
            $table->decimal('requested_amount', 19, 2);
            $table->decimal('approved_amount', 19, 2)->nullable();
            $table->decimal('disbursed_amount', 19, 2)->nullable();
            $table->foreignId('requester_user_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('responsible_user_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('activity_id')->nullable()->constrained('activities')->restrictOnDelete();
            $table->foreignId('planned_cash_account_id')->constrained('cash_accounts')->restrictOnDelete();
            $table->text('mandate_reason')->nullable();
            $table->date('due_date');
            $table->dateTime('submitted_at')->nullable();
            $table->foreignId('approved_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->dateTime('approved_at')->nullable();
            $table->foreignId('rejected_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->dateTime('rejected_at')->nullable();
            $table->text('rejection_reason')->nullable();
            $table->foreignId('revision_requested_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->dateTime('revision_requested_at')->nullable();
            $table->text('revision_reason')->nullable();
            $table->foreignId('cancelled_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->dateTime('cancelled_at')->nullable();
            $table->text('cancellation_reason')->nullable();
            $table->foreignId('disbursed_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->dateTime('disbursed_at')->nullable();
            $table->foreignId('closed_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->dateTime('closed_at')->nullable();
            $table->timestamps();
            $table->index(['responsible_user_id', 'status']);
            $table->index(['requester_user_id', 'status']);
            $table->index(['activity_id', 'status']);
            $table->index(['created_at', 'status']);
        });

        $this->createIfMissing('advance_attachments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('operational_advance_id')->constrained()->restrictOnDelete();
            $table->string('original_name');
            $table->string('disk', 40)->default('local');
            $table->string('path')->unique();
            $table->string('mime_type', 100);
            $table->unsignedBigInteger('size_bytes');
            $table->char('checksum', 64)->nullable();
            $table->foreignId('uploaded_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();
            $table->index(['operational_advance_id', 'created_at']);
        });

        $this->createIfMissing('advance_settlements', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('operational_advance_id')->constrained()->restrictOnDelete();
            $table->unsignedInteger('batch_number');
            $table->string('status', 30)->default('draft')->index();
            $table->foreignId('submitted_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->dateTime('submitted_at')->nullable();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->dateTime('reviewed_at')->nullable();
            $table->text('review_notes')->nullable();
            $table->timestamps();
            $table->unique(['operational_advance_id', 'batch_number']);
            $table->index(['status', 'submitted_at']);
        });

        $this->createIfMissing('advance_settlement_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('advance_settlement_id')->constrained()->restrictOnDelete();
            $table->foreignId('replaces_item_id')->nullable()->constrained('advance_settlement_items')->restrictOnDelete();
            $table->unique('replaces_item_id', 'asi_replaces_unique');
            $table->dateTime('occurred_at');
            $table->text('description');
            $table->foreignId('category_id')->constrained()->restrictOnDelete();
            $table->foreignId('subcategory_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('activity_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('vendor_id')->nullable()->constrained()->restrictOnDelete();
            $table->decimal('amount', 19, 2);
            $table->string('review_status', 30)->default('pending')->index();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->dateTime('reviewed_at')->nullable();
            $table->text('review_reason')->nullable();
            $table->timestamps();
        });

        if (! Schema::hasIndex('advance_settlement_items', 'asi_replaces_unique')) {
            Schema::table('advance_settlement_items', function (Blueprint $table): void {
                $table->unique('replaces_item_id', 'asi_replaces_unique');
            });
        }
        if (! Schema::hasIndex('advance_settlement_items', 'asi_settle_status_idx')) {
            Schema::table('advance_settlement_items', function (Blueprint $table): void {
                $table->index(['advance_settlement_id', 'review_status'], 'asi_settle_status_idx');
            });
        }
        if (! Schema::hasIndex('advance_settlement_items', 'asi_category_date_idx')) {
            Schema::table('advance_settlement_items', function (Blueprint $table): void {
                $table->index(['category_id', 'occurred_at'], 'asi_category_date_idx');
            });
        }

        $this->createIfMissing('advance_settlement_attachments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('advance_settlement_item_id')->constrained('advance_settlement_items', 'id', 'asat_item_fk')->restrictOnDelete();
            $table->string('original_name');
            $table->string('disk', 40)->default('local');
            $table->string('path');
            $table->string('mime_type', 100);
            $table->unsignedBigInteger('size_bytes');
            $table->char('checksum', 64)->nullable();
            $table->foreignId('uploaded_by')->constrained('users', 'id', 'asat_uploader_fk')->restrictOnDelete();
            $table->timestamps();
            $table->unique('path', 'asat_path_unique');
            $table->index(['advance_settlement_item_id', 'created_at'], 'asat_item_date_idx');
        });
        if (! Schema::hasForeignKey('advance_settlement_attachments', 'asat_item_fk')) {
            Schema::table('advance_settlement_attachments', function (Blueprint $table): void {
                $table->foreign('advance_settlement_item_id', 'asat_item_fk')
                    ->references('id')->on('advance_settlement_items')->restrictOnDelete();
            });
        }
        if (! Schema::hasForeignKey('advance_settlement_attachments', 'asat_uploader_fk')) {
            Schema::table('advance_settlement_attachments', function (Blueprint $table): void {
                $table->foreign('uploaded_by', 'asat_uploader_fk')
                    ->references('id')->on('users')->restrictOnDelete();
            });
        }
        if (! Schema::hasIndex('advance_settlement_attachments', 'asat_path_unique')) {
            Schema::table('advance_settlement_attachments', function (Blueprint $table): void {
                $table->unique('path', 'asat_path_unique');
            });
        }
        if (! Schema::hasIndex('advance_settlement_attachments', 'asat_item_date_idx')) {
            Schema::table('advance_settlement_attachments', function (Blueprint $table): void {
                $table->index(['advance_settlement_item_id', 'created_at'], 'asat_item_date_idx');
            });
        }

        $this->createIfMissing('advance_settlement_item_adjustments', function (Blueprint $table): void {
            $table->id();
            $table->uuid('adjustment_group_id');
            $table->foreignId('advance_settlement_item_id')->constrained('advance_settlement_items', 'id', 'asiaj_item_fk')->restrictOnDelete();
            $table->string('kind', 20);
            $table->string('status', 20)->default('pending');
            $table->dateTime('occurred_at');
            $table->text('description')->nullable();
            $table->foreignId('category_id')->nullable()->constrained('categories', 'id', 'asiaj_category_fk')->restrictOnDelete();
            $table->foreignId('subcategory_id')->nullable()->constrained('subcategories', 'id', 'asiaj_subcategory_fk')->restrictOnDelete();
            $table->foreignId('activity_id')->nullable()->constrained('activities', 'id', 'asiaj_activity_fk')->restrictOnDelete();
            $table->foreignId('vendor_id')->nullable()->constrained('vendors', 'id', 'asiaj_vendor_fk')->restrictOnDelete();
            $table->decimal('amount_delta', 19, 2);
            $table->text('reason');
            $table->foreignId('created_by')->constrained('users', 'id', 'asiaj_creator_fk')->restrictOnDelete();
            $table->foreignId('reviewed_by')->nullable()->constrained('users', 'id', 'asiaj_reviewer_fk')->restrictOnDelete();
            $table->dateTime('reviewed_at')->nullable();
            $table->text('review_reason')->nullable();
            $table->dateTime('created_at');
            $table->unique(['adjustment_group_id', 'kind'], 'asiaj_group_kind_uq');
            $table->index(['advance_settlement_item_id', 'created_at'], 'asiaj_item_date_idx');
            $table->index('adjustment_group_id', 'asiaj_group_idx');
            $table->index('status', 'asiaj_status_idx');
        });

        $adjustmentReferences = [
            'advance_settlement_item_id' => ['advance_settlement_items', 'asiaj_item_fk'],
            'category_id' => ['categories', 'asiaj_category_fk'],
            'subcategory_id' => ['subcategories', 'asiaj_subcategory_fk'],
            'activity_id' => ['activities', 'asiaj_activity_fk'],
            'vendor_id' => ['vendors', 'asiaj_vendor_fk'],
            'created_by' => ['users', 'asiaj_creator_fk'],
            'reviewed_by' => ['users', 'asiaj_reviewer_fk'],
        ];
        foreach ($adjustmentReferences as $column => [$referencedTable, $constraintName]) {
            if (! Schema::hasForeignKey('advance_settlement_item_adjustments', $constraintName)) {
                Schema::table('advance_settlement_item_adjustments', function (Blueprint $table) use ($column, $referencedTable, $constraintName): void {
                    $table->foreign($column, $constraintName)->references('id')->on($referencedTable)->restrictOnDelete();
                });
            }
        }
        foreach (['asiaj_group_kind_uq' => 'unique', 'asiaj_item_date_idx' => 'index', 'asiaj_group_idx' => 'index', 'asiaj_status_idx' => 'index'] as $indexName => $indexType) {
            if (! Schema::hasIndex('advance_settlement_item_adjustments', $indexName)) {
                Schema::table('advance_settlement_item_adjustments', function (Blueprint $table) use ($indexName): void {
                    match ($indexName) {
                        'asiaj_group_kind_uq' => $table->unique(['adjustment_group_id', 'kind'], $indexName),
                        'asiaj_item_date_idx' => $table->index(['advance_settlement_item_id', 'created_at'], $indexName),
                        'asiaj_group_idx' => $table->index('adjustment_group_id', $indexName),
                        default => $table->index('status', $indexName),
                    };
                });
            }
        }

        if (DB::connection()->getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE operational_advances ADD CONSTRAINT operational_advances_status_valid CHECK (status IN ('draft', 'submitted', 'revision_requested', 'approved', 'disbursed', 'settlement_in_review', 'partially_accounted', 'return_due', 'reimbursement_due', 'settled', 'rejected', 'cancelled'))");
            DB::statement('ALTER TABLE operational_advances ADD CONSTRAINT operational_advances_requested_amount_positive CHECK (requested_amount > 0)');
            DB::statement('ALTER TABLE operational_advances ADD CONSTRAINT operational_advances_approved_amount_valid CHECK (approved_amount IS NULL OR (approved_amount > 0 AND approved_amount <= requested_amount))');
            DB::statement('ALTER TABLE operational_advances ADD CONSTRAINT operational_advances_disbursed_amount_nonnegative CHECK (disbursed_amount IS NULL OR disbursed_amount >= 0)');
            DB::statement('ALTER TABLE advance_settlement_items ADD CONSTRAINT advance_settlement_items_amount_positive CHECK (amount > 0)');
            DB::statement("ALTER TABLE advance_settlement_items ADD CONSTRAINT advance_settlement_items_status_valid CHECK (review_status IN ('pending', 'accepted', 'revision_requested', 'rejected', 'superseded'))");
            DB::statement("ALTER TABLE advance_settlements ADD CONSTRAINT advance_settlements_status_valid CHECK (status IN ('draft', 'submitted', 'in_review', 'completed', 'revision_requested'))");
            DB::statement("ALTER TABLE advance_settlement_item_adjustments ADD CONSTRAINT advance_settlement_adjustments_kind_valid CHECK (kind IN ('reversal', 'replacement'))");
            DB::statement("ALTER TABLE advance_settlement_item_adjustments ADD CONSTRAINT advance_settlement_adjustments_status_valid CHECK (status IN ('pending', 'approved', 'rejected'))");
            DB::statement("ALTER TABLE advance_settlement_item_adjustments ADD CONSTRAINT advance_settlement_adjustments_amount_sign_valid CHECK ((kind = 'reversal' AND amount_delta < 0) OR (kind = 'replacement' AND amount_delta > 0))");
        }
    }

    public function down(): void
    {
        if (DB::connection()->getDriverName() === 'mysql') {
            DB::statement('ALTER TABLE advance_settlement_item_adjustments DROP CHECK advance_settlement_adjustments_amount_sign_valid');
            DB::statement('ALTER TABLE advance_settlement_item_adjustments DROP CHECK advance_settlement_adjustments_status_valid');
            DB::statement('ALTER TABLE advance_settlement_item_adjustments DROP CHECK advance_settlement_adjustments_kind_valid');
            DB::statement('ALTER TABLE advance_settlements DROP CHECK advance_settlements_status_valid');
            DB::statement('ALTER TABLE advance_settlement_items DROP CHECK advance_settlement_items_status_valid');
            DB::statement('ALTER TABLE advance_settlement_items DROP CHECK advance_settlement_items_amount_positive');
            DB::statement('ALTER TABLE operational_advances DROP CHECK operational_advances_disbursed_amount_nonnegative');
            DB::statement('ALTER TABLE operational_advances DROP CHECK operational_advances_approved_amount_valid');
            DB::statement('ALTER TABLE operational_advances DROP CHECK operational_advances_requested_amount_positive');
            DB::statement('ALTER TABLE operational_advances DROP CHECK operational_advances_status_valid');
        }

        Schema::dropIfExists('advance_settlement_item_adjustments');
        Schema::dropIfExists('advance_settlement_attachments');
        Schema::dropIfExists('advance_settlement_items');
        Schema::dropIfExists('advance_settlements');
        Schema::dropIfExists('advance_attachments');
        Schema::dropIfExists('operational_advances');
        Schema::dropIfExists('advance_number_sequences');
    }

    private function createIfMissing(string $tableName, Closure $definition): void
    {
        if (! Schema::hasTable($tableName)) {
            Schema::create($tableName, $definition);
        }
    }
};
