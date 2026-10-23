<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

/**
 * Deletes the QA training data (DAT-2 to DAT-4, MIG-1): every row in the per-participant training tables, the
 * *AI-Assisted QA Level 3* program, every participant account and the participants' local files. Admin and coach
 * accounts, their Drive connections and the audit log stay. Files on Google Drive are left alone.
 *
 * It uses the query builder and the stored values (not models or enums) so it keeps running after Phase 2 deletes
 * those classes, and it skips tables that a later migration drops.
 */
return new class extends Migration
{
    private const QA_PROGRAM_NAME = 'AI-Assisted QA Level 3';

    private const PARTICIPANT_ROLE = 'participant';

    /**
     * Training tables emptied completely, each listed before the tables its foreign keys point to, so the order holds
     * on MySQL and on SQLite whether or not cascades are enforced. Restricting keys (`activity_scores.scorer_id`,
     * the Week 4 `mentor_id`, `assigned_by_id` and `authorized_by_id`, `week_four_mentor_interview_schedules.activity_id`)
     * are cleared here, before their users and activities are deleted.
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
        'daily_logs',
        'attendance_sessions',
        'weekly_video_recordings',
        'validation_submissions',
        'week_three_fix_validations',
        'week_three_tests',
        'week_three_findings',
        'week_three_workspaces',
        'week_four_partner_feedback',
        'week_four_interview_pairs',
        'week_four_peer_interview_waivers',
        'week_four_mentor_interview_schedules',
        'mentor_interview_availabilities',
    ];

    /**
     * Rows owned by a participant that are not training data, as table => user column. They are deleted before the
     * participants (most cascade anyway; `sessions` has no foreign key).
     */
    private const PARTICIPANT_OWNED_ROWS = [
        'coach_participant_assignments' => 'participant_id',
        'password_setup_tokens' => 'user_id',
        'google_drive_connections' => 'user_id',
        'sessions' => 'user_id',
    ];

    public function up(): void
    {
        $files = $this->localFiles();

        DB::transaction(function (): void {
            foreach (self::TRAINING_TABLES as $table) {
                if (Schema::hasTable($table)) {
                    DB::table($table)->delete();
                }
            }

            $this->deleteQaProgram();
            $this->deleteParticipants();
        });

        foreach ($files as $disk => $paths) {
            Storage::disk($disk)->delete($paths);
        }
    }

    public function down(): void
    {
        // Deleted training data and files cannot be restored; the release keeps a database dump and a storage archive.
    }

    /**
     * The local files referenced by evidence, weekly videos and Validation Lab records, grouped by disk. A table
     * without a `path` column is skipped: from 8.12, `weekly_video_recordings` holds Google Drive files, not uploads.
     *
     * @return array<string, list<string>>
     */
    private function localFiles(): array
    {
        $files = collect();

        foreach (['submission_evidence', 'weekly_video_recordings'] as $table) {
            if (Schema::hasColumn($table, 'path')) {
                $files = $files->concat(
                    DB::table($table)->whereNotNull('path')->get(['disk', 'path'])
                        ->map(fn (object $file): array => ['disk' => $file->disk, 'path' => $file->path]),
                );
            }
        }

        if (Schema::hasTable('validation_submissions')) {
            $files = $files->concat(
                DB::table('validation_submissions')->whereNotNull('evidence_files')->pluck('evidence_files')
                    ->flatMap(fn (string $evidenceFiles): array => json_decode($evidenceFiles, true) ?: []),
            );
        }

        return $files
            ->filter(fn (array $file): bool => filled($file['path'] ?? null))
            ->groupBy(fn (array $file): string => ($file['disk'] ?? null) ?: 'local')
            ->map(fn (Collection $diskFiles): array => $diskFiles->pluck('path')->unique()->values()->all())
            ->all();
    }

    /**
     * Deletes the QA program's training sessions (once 8.1 has created that table) and the program; its weeks,
     * activities, requirements and gates cascade.
     */
    private function deleteQaProgram(): void
    {
        $qaProgramIds = DB::table('programs')->where('name', self::QA_PROGRAM_NAME)->select('id');

        if (Schema::hasTable('training_sessions')) {
            DB::table('training_sessions')->whereIn('program_id', $qaProgramIds)->delete();
        }

        DB::table('programs')->where('name', self::QA_PROGRAM_NAME)->delete();
    }

    /**
     * Deletes every participant with their sessions, tokens, Drive connections and coach assignments. Foreign keys
     * declared `nullOnDelete` keep their rows and clear the reference: `admin_audit_logs.target_user_id`,
     * `password_setup_tokens.created_by` and `interview_schedulers.user_id` / `approved_by`.
     */
    private function deleteParticipants(): void
    {
        $participants = fn (): Builder => DB::table('users')->where('role', self::PARTICIPANT_ROLE);

        foreach (self::PARTICIPANT_OWNED_ROWS as $table => $userColumn) {
            if (Schema::hasTable($table)) {
                DB::table($table)->whereIn($userColumn, $participants()->select('id'))->delete();
            }
        }

        DB::table('password_reset_tokens')->whereIn('email', $participants()->select('email'))->delete();

        $participants()->delete();
    }
};
