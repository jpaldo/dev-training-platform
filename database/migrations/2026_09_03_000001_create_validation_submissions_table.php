<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('validation_submissions', function (Blueprint $table) {
            $table->id();
            $table->string('trainee_name');
            $table->string('artifact_code');
            foreach (['source', 'scope', 'spec', 'cross_check', 'run', 'log'] as $check) {
                $table->string($check.'_status')->nullable();
                $table->text($check.'_notes')->nullable();
            }
            $table->text('finding')->nullable();
            $table->string('decision')->nullable();
            $table->text('evidence_notes')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('validation_submissions');
    }
};
