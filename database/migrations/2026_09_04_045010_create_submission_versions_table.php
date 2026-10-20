<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('submission_versions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('activity_submission_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('version_number');
            $table->string('status')->default('draft');
            $table->json('field_values')->nullable();
            $table->text('participant_note')->nullable();
            $table->timestamp('submitted_at')->nullable();
            $table->timestamps();

            $table->unique(['activity_submission_id', 'version_number']);
            $table->index(['activity_submission_id', 'status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('submission_versions');
    }
};
