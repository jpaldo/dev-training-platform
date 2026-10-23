<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Activity;
use App\Models\ActivityRequirement;
use App\Models\AdminAuditLog;
use App\Models\EvidenceRequirement;
use App\Models\GateCriterion;
use App\Models\Program;
use App\Models\TrainingGate;
use App\Models\TrainingSession;
use App\Models\TrainingWeek;
use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class DeleteQaTrainingDataMigrationTest extends TestCase
{
    use LazilyRefreshDatabase;

    private const EVIDENCE_PATH = 'evidence/1/1/report.pdf';

    /**
     * Every table that holds per-participant training data.
     */
    private const TRAINING_TABLES = [
        'google_drive_evidence_exports',
        'google_drive_exports',
        'google_drive_syncs',
        'participant_notifications',
        'activity_recovery_requests',
        'activity_score_dimensions',
        'activity_score_histories',
        'activity_scores',
        'activity_score_suggestions',
        'submission_reviews',
        'submission_interactions',
        'submission_evidence',
        'submission_versions',
        'activity_submissions',
        'gate_criterion_evaluations',
        'gate_evaluations',
        'attendance_sessions',
        'weekly_video_recordings',
        'coach_participant_assignments',
    ];

    public function test_it_empties_the_training_tables_and_deletes_the_qa_program_and_participants(): void
    {
        Storage::fake('local');
        $this->createQaProgram();
        $coach = User::factory()->create(['role' => UserRole::Coach]);
        $participant = User::factory()->create();
        $this->seedTrainingRecords($participant, $coach);

        $this->migration()->up();

        foreach (self::TRAINING_TABLES as $table) {
            $this->assertDatabaseEmpty($table);
        }
        $this->assertDatabaseMissing('programs', ['name' => 'AI-Assisted QA Level 3']);
        $this->assertDatabaseEmpty('training_weeks');
        $this->assertDatabaseEmpty('activities');
        $this->assertDatabaseEmpty('training_gates');
        $this->assertDatabaseMissing('users', ['role' => 'participant']);
        $this->assertDatabaseMissing('sessions', ['user_id' => $participant->id]);
        $this->assertDatabaseMissing('password_reset_tokens', ['email' => $participant->email]);
        $this->assertDatabaseMissing('password_setup_tokens', ['user_id' => $participant->id]);
        $this->assertDatabaseMissing('google_drive_connections', ['user_id' => $participant->id]);
    }

    public function test_it_keeps_admin_and_coach_accounts_with_their_audit_rows_and_drive_connection(): void
    {
        Storage::fake('local');
        $this->createQaProgram();
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $coach = User::factory()->create(['role' => UserRole::Coach]);
        $participant = User::factory()->create();
        $this->seedTrainingRecords($participant, $coach);
        $coachAudit = AdminAuditLog::query()->create(['actor_id' => $admin->id, 'target_user_id' => $coach->id, 'action' => 'user_updated']);
        $participantAudit = AdminAuditLog::query()->create(['actor_id' => $admin->id, 'target_user_id' => $participant->id, 'action' => 'user_created']);
        DB::table('google_drive_connections')->insert(['user_id' => $admin->id, 'access_token' => 'admin-token', 'connected_at' => now()]);

        $this->migration()->up();

        $this->assertModelExists($admin);
        $this->assertModelExists($coach);
        $this->assertDatabaseHas('admin_audit_logs', ['id' => $coachAudit->id, 'actor_id' => $admin->id, 'target_user_id' => $coach->id]);
        $this->assertDatabaseHas('admin_audit_logs', ['id' => $participantAudit->id, 'actor_id' => $admin->id, 'target_user_id' => null, 'action' => 'user_created']);
        $this->assertDatabaseHas('google_drive_connections', ['user_id' => $admin->id]);
    }

    public function test_it_keeps_programs_other_than_the_qa_program(): void
    {
        $this->createQaProgram();
        $otherWeek = TrainingWeek::factory()->create();

        $this->migration()->up();

        $this->assertModelExists($otherWeek->program);
        $this->assertModelExists($otherWeek);
        $this->assertDatabaseCount('programs', 1);
    }

    public function test_it_deletes_the_local_files_the_training_records_reference(): void
    {
        Storage::fake('local');
        Storage::disk('local')->put(self::EVIDENCE_PATH, 'evidence');
        Storage::disk('local')->put('public-interview/booking.pdf', 'kept');
        $this->createQaProgram();
        $this->seedTrainingRecords(User::factory()->create(), User::factory()->create(['role' => UserRole::Coach]));

        $this->migration()->up();

        Storage::disk('local')->assertMissing(self::EVIDENCE_PATH);
        Storage::disk('local')->assertExists('public-interview/booking.pdf');
    }

    public function test_it_changes_nothing_on_a_database_without_training_data(): void
    {
        Storage::fake('local');
        Storage::disk('local')->put('public-interview/booking.pdf', 'kept');
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $coach = User::factory()->create(['role' => UserRole::Coach]);
        $audit = AdminAuditLog::query()->create(['actor_id' => $admin->id, 'target_user_id' => $coach->id, 'action' => 'user_created']);

        $this->migration()->up();

        $this->assertDatabaseCount('users', 2);
        $this->assertDatabaseHas('admin_audit_logs', ['id' => $audit->id, 'target_user_id' => $coach->id]);
        Storage::disk('local')->assertExists('public-interview/booking.pdf');
    }

    /**
     * The QA program by its stored name, with one week, an activity with an evidence requirement and a Gate with one
     * criterion.
     */
    private function createQaProgram(): void
    {
        $week = TrainingWeek::factory()
            ->for(Program::factory()->state(['name' => 'AI-Assisted QA Level 3']))
            ->create(['week_number' => 2, 'position' => 1]);
        EvidenceRequirement::factory()
            ->for(ActivityRequirement::factory()->for(Activity::factory()->for($week, 'week')))
            ->create();
        GateCriterion::factory()->for(TrainingGate::factory()->for($week, 'week'), 'gate')->create();
    }

    /**
     * Writes one row to every training table (and the participant's session, tokens and Drive connection) for the
     * QA program. Rows go in through the query builder: most of these tables have no factory and their
     * models are removed during Phase 2. The submission, Gate evaluation and video need a training session (since 9.1);
     * it belongs to another program, because sessions did not exist when this migration ran and keep their program.
     */
    private function seedTrainingRecords(User $participant, User $coach): void
    {
        $week = TrainingWeek::query()->firstOrFail();
        $activity = Activity::query()->firstOrFail();
        $criterion = GateCriterion::query()->firstOrFail();
        $evidenceRequirement = EvidenceRequirement::query()->firstOrFail();
        $sessionId = TrainingSession::factory()->create()->id;

        $submissionId = DB::table('activity_submissions')->insertGetId(['training_session_id' => $sessionId, 'activity_id' => $activity->id, 'user_id' => $participant->id, 'status' => 'submitted']);
        $versionId = DB::table('submission_versions')->insertGetId(['activity_submission_id' => $submissionId, 'version_number' => 1]);
        $evidenceId = DB::table('submission_evidence')->insertGetId([
            'submission_version_id' => $versionId,
            'evidence_requirement_id' => $evidenceRequirement->id,
            'evidence_type' => 'file',
            'disk' => 'local',
            'path' => self::EVIDENCE_PATH,
            'original_filename' => 'report.pdf',
        ]);
        $scoreId = DB::table('activity_scores')->insertGetId(['submission_version_id' => $versionId, 'scorer_id' => $coach->id]);
        $gateEvaluationId = DB::table('gate_evaluations')->insertGetId(['training_session_id' => $sessionId, 'training_gate_id' => $criterion->training_gate_id, 'user_id' => $participant->id, 'reviewer_id' => $coach->id]);

        DB::table('google_drive_evidence_exports')->insert(['submission_evidence_id' => $evidenceId]);
        DB::table('google_drive_exports')->insert(['user_id' => $participant->id, 'export_type' => 'weekly_workbook', 'scope_key' => '2']);
        DB::table('google_drive_syncs')->insert(['user_id' => $participant->id, 'destination_folder_id' => 'folder']);
        DB::table('participant_notifications')->insert(['user_id' => $participant->id, 'key' => 'activity', 'type' => 'activity_incomplete', 'title' => 'Activity incomplete', 'body' => 'Finish it.']);
        DB::table('activity_recovery_requests')->insert(['user_id' => $participant->id, 'activity_submission_id' => $submissionId, 'reviewed_by' => $coach->id]);
        DB::table('activity_score_dimensions')->insert(['activity_score_id' => $scoreId, 'dimension' => 'D1', 'points' => 10, 'evidence_reasoning' => 'Clear.']);
        DB::table('activity_score_histories')->insert(['activity_score_id' => $scoreId, 'scorer_id' => $coach->id, 'new_score' => 10]);
        DB::table('activity_score_suggestions')->insert(['submission_version_id' => $versionId, 'dimensions' => '[]']);
        DB::table('submission_reviews')->insert(['submission_version_id' => $versionId, 'reviewer_id' => $coach->id, 'decision' => 'approved', 'reviewed_at' => now()]);
        DB::table('submission_interactions')->insert(['submission_version_id' => $versionId, 'sequence' => 1]);
        DB::table('gate_criterion_evaluations')->insert(['gate_evaluation_id' => $gateEvaluationId, 'gate_criterion_id' => $criterion->id, 'reviewer_id' => $coach->id]);
        DB::table('attendance_sessions')->insert(['user_id' => $participant->id, 'check_in_at' => now()]);
        DB::table('weekly_video_recordings')->insert([
            'training_session_id' => $sessionId,
            'user_id' => $participant->id,
            'training_week_id' => $week->id,
            'drive_file_id' => 'week-2-video',
            'name' => 'week-2.mp4',
            'mime_type' => 'video/mp4',
            'size_bytes' => 5,
        ]);
        DB::table('coach_participant_assignments')->insert(['coach_id' => $coach->id, 'participant_id' => $participant->id]);
        DB::table('sessions')->insert(['id' => 'participant-session', 'user_id' => $participant->id, 'payload' => '', 'last_activity' => now()->timestamp]);
        DB::table('password_reset_tokens')->insert(['email' => $participant->email, 'token' => 'reset-token']);
        DB::table('password_setup_tokens')->insert(['user_id' => $participant->id, 'token_hash' => 'setup-token', 'expires_at' => now()->addDay(), 'created_by' => $coach->id]);
        DB::table('google_drive_connections')->insert(['user_id' => $participant->id, 'access_token' => 'participant-token', 'connected_at' => now()]);
    }

    private function migration(): Migration
    {
        return require database_path('migrations/2026_10_23_060944_delete_qa_training_data.php');
    }
}
