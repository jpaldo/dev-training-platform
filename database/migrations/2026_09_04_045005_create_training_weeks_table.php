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
        Schema::create('training_weeks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('program_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('week_number');
            $table->string('title');
            $table->text('description')->nullable();
            $table->unsignedSmallInteger('position');
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['program_id', 'week_number']);
            $table->unique(['program_id', 'position']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('training_weeks');
    }
};
