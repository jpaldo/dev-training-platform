<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Creates the criteria of a Gate, in position order.
     */
    public function up(): void
    {
        Schema::create('gate_criteria', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('training_gate_id')->constrained()->cascadeOnDelete();
            $table->string('criterion_type');
            $table->string('title');
            $table->text('description')->nullable();
            $table->boolean('is_required')->default(true);
            $table->unsignedSmallInteger('position');
            $table->json('configuration')->nullable();
            $table->timestamps();
            $table->index(['training_gate_id', 'position']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('gate_criteria');
    }
};
