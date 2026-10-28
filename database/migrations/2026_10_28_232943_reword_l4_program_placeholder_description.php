<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const OLD_DESCRIPTION = 'Placeholder — the L4 Accelerator Training curriculum is coming soon.';

    private const NEW_DESCRIPTION = 'Placeholder — the L4 Accelerator Training program is coming soon.';

    /**
     * Rewords the seeded L4 placeholder description now that the UI says "program" instead of "curriculum". The
     * seeder skips a program that already exists, so existing rows are updated here. Only rows that still hold the
     * exact placeholder change; a description an admin edited is left alone.
     */
    public function up(): void
    {
        DB::table('programs')->where('description', self::OLD_DESCRIPTION)->update(['description' => self::NEW_DESCRIPTION]);
    }

    /**
     * Restores the old wording on rows that still hold the new placeholder.
     */
    public function down(): void
    {
        DB::table('programs')->where('description', self::NEW_DESCRIPTION)->update(['description' => self::OLD_DESCRIPTION]);
    }
};
