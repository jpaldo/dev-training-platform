<?php

namespace Tests\Feature;

use Database\Seeders\L4TrainingProgramSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class DataIntegrityTest extends TestCase
{
    use RefreshDatabase;

    public function test_domain_records_have_valid_owners_and_relationships(): void
    {
        $this->seed(L4TrainingProgramSeeder::class);
        foreach ([
            ['submission_versions', 'activity_submission_id', 'activity_submissions'],
            ['submission_evidence', 'submission_version_id', 'submission_versions'],
            ['submission_reviews', 'submission_version_id', 'submission_versions'],
            ['gate_evaluations', 'training_gate_id', 'training_gates'],
            ['gate_criterion_evaluations', 'gate_evaluation_id', 'gate_evaluations'],
        ] as [$table, $column, $parent]) {
            $this->assertSame(0, DB::table($table)->leftJoin($parent, "$table.$column", '=', "$parent.id")->whereNull("$parent.id")->count(), "$table contains orphaned records");
        }
        $this->assertSame(0, DB::table('submission_versions')->whereIn('status', ['submitted', 'approved', 'completed'])->whereNull('submitted_at')->count());
    }
}
