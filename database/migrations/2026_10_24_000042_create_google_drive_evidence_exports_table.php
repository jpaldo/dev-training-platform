<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Creates the Google Drive export tracking rows for evidence files: one per evidence and destination folder. The
     * table name is passed to `constrained()` because it would guess `submission_evidences`.
     */
    public function up(): void
    {
        Schema::create('google_drive_evidence_exports', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('submission_evidence_id')->constrained('submission_evidence')->cascadeOnDelete();
            $table->string('drive_file_id')->nullable();
            $table->string('destination_folder_id')->nullable();
            $table->string('status')->default('pending');
            $table->text('error')->nullable();
            $table->timestamp('last_attempt_at')->nullable();
            $table->timestamp('last_synced_at')->nullable();
            $table->timestamps();
            $table->unique(['submission_evidence_id', 'destination_folder_id'], 'google_drive_evidence_destination_identity_unique');
            $table->index('submission_evidence_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('google_drive_evidence_exports');
    }
};
