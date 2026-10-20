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
        Schema::create('evidence_requirements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('activity_requirement_id')->constrained()->cascadeOnDelete();
            $table->string('title');
            $table->text('description')->nullable();
            $table->string('evidence_type');
            $table->boolean('is_required')->default(true);
            $table->unsignedSmallInteger('minimum_count')->default(1);
            $table->unsignedSmallInteger('maximum_count')->nullable();
            $table->json('configuration')->nullable();
            $table->unsignedSmallInteger('position');
            $table->timestamps();

            $table->index(['activity_requirement_id', 'position']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('evidence_requirements');
    }
};
