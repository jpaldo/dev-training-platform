<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Creates the requirements of an activity, in position order.
     */
    public function up(): void
    {
        Schema::create('activity_requirements', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('activity_id')->constrained()->cascadeOnDelete();
            $table->string('requirement_type');
            $table->string('title');
            $table->text('description')->nullable();
            $table->boolean('is_required')->default(true);
            $table->unsignedSmallInteger('position');
            $table->json('configuration')->nullable();
            $table->timestamps();
            $table->index(['activity_id', 'position'], 'activity_req_activity_pos_idx');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('activity_requirements');
    }
};
