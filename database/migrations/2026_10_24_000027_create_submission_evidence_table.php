<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Creates the evidence attached to a submission version: text, a link or a stored file.
     */
    public function up(): void
    {
        Schema::create('submission_evidence', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('submission_version_id')->constrained()->cascadeOnDelete();
            $table->foreignId('evidence_requirement_id')->nullable()->constrained()->nullOnDelete();
            $table->string('evidence_type');
            $table->string('title')->nullable();
            $table->text('content')->nullable();
            $table->string('url', 2048)->nullable();
            $table->string('disk')->nullable();
            $table->string('path')->nullable();
            $table->string('original_filename')->nullable();
            $table->string('mime_type')->nullable();
            $table->unsignedBigInteger('size_bytes')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->index(['submission_version_id', 'evidence_requirement_id'], 'submission_evidence_ver_req_idx');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('submission_evidence');
    }
};
