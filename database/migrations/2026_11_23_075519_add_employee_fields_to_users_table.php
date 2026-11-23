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
        Schema::table('users', function (Blueprint $table): void {
            $table->string('employee_number')->nullable()->unique();
            $table->string('phone', 30)->nullable();
            $table->foreignId('department_id')->nullable()->constrained()->restrictOnDelete();
            $table->boolean('is_active')->default(true);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('department_id');
            $table->dropUnique(['employee_number']);
            $table->dropColumn(['employee_number', 'phone', 'is_active']);
        });
    }
};
