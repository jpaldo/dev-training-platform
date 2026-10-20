<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('week_four_interview_pairs')) {
            Schema::create('week_four_interview_pairs', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('participant_one_id')->constrained('users')->cascadeOnDelete();
                $table->foreignId('participant_two_id')->constrained('users')->cascadeOnDelete();
                $table->foreignId('assigned_by_id')->constrained('users')->restrictOnDelete();
                $table->string('status')->default('active');
                $table->timestamps();
                $table->unique(['participant_one_id', 'participant_two_id'], 'week4_pairs_participants_unique');
                $table->index(['participant_one_id', 'status']);
                $table->index(['participant_two_id', 'status']);
            });
        } elseif (! collect(Schema::getIndexes('week_four_interview_pairs'))->contains(fn (array $index): bool => ($index['name'] ?? '') === 'week4_pairs_participants_unique')) {
            Schema::table('week_four_interview_pairs', function (Blueprint $table): void {
                $table->unique(['participant_one_id', 'participant_two_id'], 'week4_pairs_participants_unique');
            });
        }

        Schema::create('week_four_partner_feedback', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('pair_id')->constrained('week_four_interview_pairs')->cascadeOnDelete();
            $table->foreignId('author_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('recipient_id')->constrained('users')->cascadeOnDelete();
            $table->unsignedTinyInteger('question_number');
            $table->text('content');
            $table->timestamps();
            $table->unique(['pair_id', 'author_id', 'recipient_id', 'question_number'], 'week4_feedback_unique');
            $table->index(['recipient_id', 'pair_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('week_four_partner_feedback');
        Schema::dropIfExists('week_four_interview_pairs');
    }
};
