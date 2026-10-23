<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Creates the links an admin adds to a session, for the whole session or one week.
     */
    public function up(): void
    {
        Schema::create('training_session_resources', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('training_session_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('week_number')->nullable();
            $table->string('title');
            $table->text('url');
            $table->foreignId('added_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['training_session_id', 'week_number']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('training_session_resources');
    }
};
