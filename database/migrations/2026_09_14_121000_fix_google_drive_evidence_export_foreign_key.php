<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() === 'sqlite') {
            return;
        }

        Schema::table('google_drive_evidence_exports', function (Blueprint $table): void {
            $table->dropForeign(['submission_evidence_id']);
            $table->foreign('submission_evidence_id')->references('id')->on('submission_evidence')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'sqlite') {
            return;
        }

        Schema::table('google_drive_evidence_exports', function (Blueprint $table): void {
            $table->dropForeign(['submission_evidence_id']);
            $table->foreign('submission_evidence_id')->references('id')->on('submission_evidences')->cascadeOnDelete();
        });
    }
};
