<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('operational_advances', function (Blueprint $table): void {
            $table->index(['status', 'due_date'], 'adv_status_due_idx');
        });
    }

    public function down(): void
    {
        Schema::table('operational_advances', function (Blueprint $table): void {
            $table->dropIndex('adv_status_due_idx');
        });
    }
};
