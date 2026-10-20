<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('submission_interactions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('submission_version_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('sequence');
            $table->longText('prompt')->nullable();
            $table->longText('output')->nullable();
            $table->timestamps();
            $table->unique(['submission_version_id', 'sequence']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('submission_interactions');
    }
};
