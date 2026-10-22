<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

/**
 * Removes the Week 3 workspace (REM-3): drops `week_three_fix_validations`, `week_three_tests`, `week_three_findings`
 * and `week_three_workspaces`, children first. The 2.1 migration already emptied them. Only these four tables have
 * foreign keys to one another; no other table points at them.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::dropIfExists('week_three_fix_validations');
        Schema::dropIfExists('week_three_tests');
        Schema::dropIfExists('week_three_findings');
        Schema::dropIfExists('week_three_workspaces');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // The Week 3 workspace is removed; rolling back does not restore its tables.
    }
};
