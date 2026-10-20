<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('week_three_workspaces', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('training_week_id')->constrained()->cascadeOnDelete();
            $table->string('status')->default('draft');
            $table->text('regression_areas')->nullable();
            $table->text('regression_issues')->nullable();
            $table->text('coverage_gaps')->nullable();
            $table->text('final_conclusion')->nullable();
            $table->timestamps();
            $table->unique(['user_id', 'training_week_id']);
        });

        Schema::create('week_three_findings', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('week_three_workspace_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('position');
            $table->string('title')->nullable();
            $table->text('description')->nullable();
            $table->text('steps_to_reproduce')->nullable();
            $table->text('expected_result')->nullable();
            $table->text('actual_result')->nullable();
            $table->text('observed_evidence')->nullable();
            $table->string('ai_tool')->nullable();
            $table->text('ai_proposed_root_cause')->nullable();
            $table->string('human_validation')->nullable();
            $table->text('human_validation_explanation')->nullable();
            $table->string('severity')->nullable();
            $table->text('blast_radius')->nullable();
            $table->text('affected_areas')->nullable();
            $table->text('next_step')->nullable();
            $table->timestamps();
            $table->unique(['week_three_workspace_id', 'position']);
        });

        Schema::create('week_three_tests', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('week_three_finding_id')->constrained()->cascadeOnDelete();
            $table->string('path');
            $table->string('name')->nullable();
            $table->text('preconditions')->nullable();
            $table->text('steps')->nullable();
            $table->text('expected_result')->nullable();
            $table->text('actual_result')->nullable();
            $table->string('execution_result')->nullable();
            $table->string('framework')->nullable();
            $table->string('script_location')->nullable();
            $table->text('artifact_evidence')->nullable();
            $table->text('execution_evidence')->nullable();
            $table->timestamps();
            $table->unique(['week_three_finding_id', 'path']);
        });

        Schema::create('week_three_fix_validations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('week_three_test_id')->constrained()->cascadeOnDelete();
            $table->text('buggy_result')->nullable();
            $table->text('bug_free_result')->nullable();
            $table->string('status')->nullable();
            $table->text('evidence')->nullable();
            $table->text('regression_issues')->nullable();
            $table->text('coverage_gaps')->nullable();
            $table->timestamps();
            $table->unique('week_three_test_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('week_three_fix_validations');
        Schema::dropIfExists('week_three_tests');
        Schema::dropIfExists('week_three_findings');
        Schema::dropIfExists('week_three_workspaces');
    }
};
