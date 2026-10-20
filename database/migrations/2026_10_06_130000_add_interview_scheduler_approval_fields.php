<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('interview_schedulers')) {
            return;
        }

        Schema::table('interview_schedulers', function (Blueprint $table): void {
            if (! Schema::hasColumn('interview_schedulers', 'approval_status')) {
                $table->string('approval_status', 20)->default('approved')->after('is_active');
            }
            if (! Schema::hasColumn('interview_schedulers', 'submitted_publicly')) {
                $table->boolean('submitted_publicly')->default(false)->after('approval_status');
            }
            if (! Schema::hasColumn('interview_schedulers', 'approved_by')) {
                $table->foreignId('approved_by')->nullable()->after('submitted_publicly')->constrained('users')->nullOnDelete();
            }
            if (! Schema::hasColumn('interview_schedulers', 'approved_at')) {
                $table->timestamp('approved_at')->nullable()->after('approved_by');
            }
            if (! Schema::hasColumn('interview_schedulers', 'rejected_at')) {
                $table->timestamp('rejected_at')->nullable()->after('approved_at');
            }
        });
        Schema::table('interview_schedulers', function (Blueprint $table): void {
            $table->index(['approval_status', 'submitted_publicly'], 'interview_scheduler_approval_idx');
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('interview_schedulers')) {
            return;
        }
        Schema::table('interview_schedulers', function (Blueprint $table): void {
            $table->dropForeign(['approved_by']);
            $table->dropIndex('interview_scheduler_approval_idx');
            $table->dropColumn(['approval_status', 'submitted_publicly', 'approved_by', 'approved_at', 'rejected_at']);
        });
    }
};
