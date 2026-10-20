<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('google_drive_evidence_exports', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('submission_evidence_id')->unique()->constrained('submission_evidence')->cascadeOnDelete();
            $table->string('drive_file_id')->nullable();
            $table->string('status')->default('pending');
            $table->text('error')->nullable();
            $table->timestamp('last_synced_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('google_drive_evidence_exports');
    }
};
