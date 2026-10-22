<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Adds the employee ID, first and last name and job role, then fills the names of existing users from `name`.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->string('employee_id', 50)->nullable()->unique()->after('id');
            $table->string('first_name')->nullable()->after('name');
            $table->string('last_name')->nullable()->after('first_name');
            $table->string('job_role')->nullable()->after('role');
        });

        $this->backfillNames();
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropUnique(['employee_id']);
        });

        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn(['employee_id', 'first_name', 'last_name', 'job_role']);
        });
    }

    /**
     * Splits each `name` at its first space: the first word becomes the first name and the rest the last name (empty
     * for a one-word name).
     */
    private function backfillNames(): void
    {
        DB::table('users')
            ->select(['id', 'name'])
            ->chunkById(500, function (Collection $users): void {
                foreach ($users as $user) {
                    [$firstName, $lastName] = array_pad(explode(' ', trim((string) $user->name), 2), 2, '');

                    DB::table('users')->where('id', $user->id)->update([
                        'first_name' => $firstName,
                        'last_name' => trim($lastName),
                    ]);
                }
            });
    }
};
