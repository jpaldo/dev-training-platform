<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Creates the session resources: files on a private disk or links, for the whole session (`week_number` null) or
     * one week. The file columns are null for links, and `url` is null for files.
     */
    public function up(): void
    {
        Schema::create('training_session_resources', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('training_session_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('week_number')->nullable();
            $table->string('title');
            $table->string('type', 20);
            $table->text('url')->nullable();
            $table->string('disk')->nullable();
            $table->string('path')->nullable();
            $table->string('original_filename')->nullable();
            $table->string('mime_type')->nullable();
            $table->unsignedBigInteger('size_bytes')->nullable();
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['training_session_id', 'week_number']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('training_session_resources');
    }
};
