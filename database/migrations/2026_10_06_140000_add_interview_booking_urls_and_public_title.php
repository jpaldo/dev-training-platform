<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('interview_schedulers')) {
            Schema::table('interview_schedulers', function (Blueprint $table): void {
                if (! Schema::hasColumn('interview_schedulers', 'mock_booking_url')) {
                    $table->text('mock_booking_url')->nullable()->after('booking_url');
                }
                if (! Schema::hasColumn('interview_schedulers', 'final_booking_url')) {
                    $table->text('final_booking_url')->nullable()->after('mock_booking_url');
                }
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('interview_schedulers')) {
            Schema::table('interview_schedulers', function (Blueprint $table): void {
                $columns = [];
                if (Schema::hasColumn('interview_schedulers', 'mock_booking_url')) {
                    $columns[] = 'mock_booking_url';
                }
                if (Schema::hasColumn('interview_schedulers', 'final_booking_url')) {
                    $columns[] = 'final_booking_url';
                }
                if ($columns !== []) {
                    $table->dropColumn($columns);
                }
            });
        }
    }
};
