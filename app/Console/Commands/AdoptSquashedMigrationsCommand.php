<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Database\Migrations\MigrationRepositoryInterface;
use Illuminate\Support\Facades\DB;

/**
 * Makes a database that ran the pre-squash migration files record the squashed files instead (plan 13.1), so
 * `migrate:status` shows nothing pending. It only rewrites rows in the migrations table and never runs a migration:
 * the schema the squashed files describe is already in place. Delete it once every shared database has adopted them.
 */
class AdoptSquashedMigrationsCommand extends Command
{
    /**
     * The migration files the squash replaced. A database adopts only when it has recorded every one of them.
     *
     * @var list<string>
     */
    public const REPLACED_MIGRATIONS = [
        '0001_01_01_000000_create_users_table',
        '0001_01_01_000001_create_cache_table',
        '0001_01_01_000002_create_jobs_table',
        '2026_09_03_000001_create_validation_submissions_table',
        '2026_09_04_045004_create_programs_table',
        '2026_09_04_045005_create_training_weeks_table',
        '2026_09_04_045006_create_activities_table',
        '2026_09_04_045007_create_activity_requirements_table',
        '2026_09_04_045008_create_evidence_requirements_table',
        '2026_09_04_045009_create_activity_submissions_table',
        '2026_09_04_045010_create_submission_versions_table',
        '2026_09_04_045011_create_submission_evidence_table',
        '2026_09_04_045012_create_daily_logs_table',
        '2026_09_04_045013_create_submission_reviews_table',
        '2026_09_04_045014_create_training_gates_table',
        '2026_09_04_045015_create_gate_criteria_table',
        '2026_09_04_045016_create_gate_evaluations_table',
        '2026_09_04_045017_create_gate_criterion_evaluations_table',
        '2026_09_04_045020_support_activity_retry_attempts',
        '2026_09_04_045859_add_training_role_and_path_to_users_table',
        '2026_09_04_050918_add_configuration_to_activities_table',
        '2026_09_04_053555_add_user_id_to_validation_submissions_table',
        '2026_09_04_053556_add_validation_submission_id_to_submission_versions_table',
        '2026_09_07_000001_create_activity_recovery_requests_table',
        '2026_09_07_030000_align_week2_working_template_fields',
        '2026_09_07_031000_update_weekly_log_gate_criteria',
        '2026_09_07_032000_remove_redundant_activity1_lab_fields',
        '2026_09_07_033000_label_activity1_lab_reference',
        '2026_09_07_034000_add_artifact_name_to_validation_submissions',
        '2026_09_10_120000_add_weekly_loom_gate_criteria',
        '2026_09_10_130000_add_is_active_to_users_table',
        '2026_09_10_130001_create_admin_audit_logs_table',
        '2026_09_10_140000_reorder_official_activity_fields',
        '2026_09_10_150000_expand_week3_activity_fields',
        '2026_09_10_160000_add_week3_activity_fields',
        '2026_09_10_170000_create_attendance_sessions_table',
        '2026_09_10_180000_add_drive_link_to_users_table',
        '2026_09_11_090000_create_activity_scoring_tables',
        '2026_09_11_100000_expand_validation_artifact_context',
        '2026_09_11_110000_deprecate_redundant_activity_fields',
        '2026_09_11_120000_mark_artifact_context_fields',
        '2026_09_11_130000_order_artifact_context_fields',
        '2026_09_11_140000_create_google_drive_connections_table',
        '2026_09_11_150000_create_application_settings_table',
        '2026_09_11_160000_add_participant_drive_destination_to_connections',
        '2026_09_11_170000_create_password_setup_tokens_table',
        '2026_09_11_180000_create_coach_participant_assignments_table',
        '2026_09_11_190000_extend_activity_scores_for_second_readers',
        '2026_09_12_100000_create_google_drive_exports_table',
        '2026_09_12_110000_create_google_drive_evidence_exports_table',
        '2026_09_14_120000_add_last_attempt_at_to_google_drive_exports',
        '2026_09_14_121000_fix_google_drive_evidence_export_foreign_key',
        '2026_09_15_130000_add_destination_folder_id_to_google_drive_exports',
        '2026_09_16_100000_add_picker_destination_to_google_drive_connections',
        '2026_09_16_120000_create_google_drive_syncs_table',
        '2026_09_16_130000_add_follow_up_to_google_drive_syncs_table',
        '2026_09_16_140000_add_destination_metadata_to_google_drive_syncs',
        '2026_09_16_150000_add_prompt_and_evidence_files_to_validation_submissions',
        '2026_09_16_160000_fix_google_drive_evidence_export_sqlite_foreign_key',
        '2026_09_16_170000_add_component_results_to_google_drive_syncs',
        '2026_09_17_120000_add_status_to_validation_submissions_table',
        '2026_09_18_100000_make_google_drive_exports_destination_aware',
        '2026_09_18_150000_create_weekly_video_recordings_table',
        '2026_09_18_150100_migrate_weekly_loom_to_video_recordings',
        '2026_09_18_160000_align_current_activity_submission_definitions',
        '2026_09_18_160100_restore_current_regression_context_fields',
        '2026_09_21_000000_create_participant_notifications_table',
        '2026_09_22_120000_remove_week_two_lab_context_fields',
        '2026_09_23_120000_create_week_three_workspaces',
        '2026_09_23_130000_create_submission_interactions',
        '2026_09_23_140000_create_activity_score_suggestions',
        '2026_09_24_120000_add_overall_feedback_to_activity_scores',
        '2026_09_24_130000_add_evidence_processing_to_activity_score_suggestions',
        '2026_09_30_120000_create_week_four_interview_pairs_and_feedback',
        '2026_10_01_135658_create_week_four_mentor_interview_schedules_table',
        '2026_10_02_100000_create_week_four_peer_interview_waivers_table',
        '2026_10_05_120000_create_mentor_interview_availabilities_table',
        '2026_10_05_120100_allow_early_mentor_interview_schedules',
        '2026_10_06_120000_create_interview_schedulers_table',
        '2026_10_06_130000_add_interview_scheduler_approval_fields',
        '2026_10_06_140000_add_interview_booking_urls_and_public_title',
        '2026_10_23_051317_add_identity_fields_to_users_table',
        '2026_10_23_060944_delete_qa_training_data',
        '2026_10_23_062326_drop_validation_lab_tables',
        '2026_10_23_064320_delete_daily_log_participant_notifications',
        '2026_10_23_065759_drop_daily_logs_table',
        '2026_10_23_070805_drop_week_three_workspace_tables',
        '2026_10_23_071847_drop_week_four_peer_interview_tables',
        '2026_10_23_073546_drop_mentor_interview_booking_tables',
        '2026_10_23_121319_drop_training_path_columns',
        '2026_10_23_140801_create_training_sessions_table',
        '2026_10_23_140802_create_training_session_participants_table',
        '2026_10_23_143612_create_training_session_resources_table',
        '2026_10_23_173403_turn_training_session_resources_into_links',
        '2026_10_23_192547_store_weekly_videos_as_google_drive_files',
        '2026_10_23_200206_add_sharing_to_weekly_video_recordings_table',
        '2026_10_23_202323_add_training_session_to_training_records',
        '2026_10_23_211821_require_training_session_on_training_records',
        '2026_10_23_223926_add_repository_url_to_training_session_participants_table',
        '2026_10_23_232343_create_training_session_mentors_table',
        '2026_10_23_232344_create_mentor_assignments_table',
        '2026_10_23_234934_drop_coach_participant_assignments_table',
    ];

    /**
     * The squashed migration files, one per table, that create the same schema.
     *
     * @var list<string>
     */
    public const SQUASHED_MIGRATIONS = [
        '2026_10_24_000001_create_users_table',
        '2026_10_24_000002_create_password_reset_tokens_table',
        '2026_10_24_000003_create_sessions_table',
        '2026_10_24_000004_create_cache_table',
        '2026_10_24_000005_create_cache_locks_table',
        '2026_10_24_000006_create_jobs_table',
        '2026_10_24_000007_create_job_batches_table',
        '2026_10_24_000008_create_failed_jobs_table',
        '2026_10_24_000009_create_application_settings_table',
        '2026_10_24_000010_create_admin_audit_logs_table',
        '2026_10_24_000011_create_password_setup_tokens_table',
        '2026_10_24_000012_create_attendance_sessions_table',
        '2026_10_24_000013_create_programs_table',
        '2026_10_24_000014_create_training_weeks_table',
        '2026_10_24_000015_create_activities_table',
        '2026_10_24_000016_create_activity_requirements_table',
        '2026_10_24_000017_create_evidence_requirements_table',
        '2026_10_24_000018_create_training_gates_table',
        '2026_10_24_000019_create_gate_criteria_table',
        '2026_10_24_000020_create_training_sessions_table',
        '2026_10_24_000021_create_training_session_participants_table',
        '2026_10_24_000022_create_training_session_mentors_table',
        '2026_10_24_000023_create_training_session_resources_table',
        '2026_10_24_000024_create_mentor_assignments_table',
        '2026_10_24_000025_create_activity_submissions_table',
        '2026_10_24_000026_create_submission_versions_table',
        '2026_10_24_000027_create_submission_evidence_table',
        '2026_10_24_000028_create_submission_reviews_table',
        '2026_10_24_000029_create_submission_interactions_table',
        '2026_10_24_000030_create_activity_recovery_requests_table',
        '2026_10_24_000031_create_activity_scores_table',
        '2026_10_24_000032_create_activity_score_dimensions_table',
        '2026_10_24_000033_create_activity_score_histories_table',
        '2026_10_24_000034_create_activity_score_suggestions_table',
        '2026_10_24_000035_create_gate_evaluations_table',
        '2026_10_24_000036_create_gate_criterion_evaluations_table',
        '2026_10_24_000037_create_weekly_video_recordings_table',
        '2026_10_24_000038_create_participant_notifications_table',
        '2026_10_24_000039_create_interview_schedulers_table',
        '2026_10_24_000040_create_google_drive_connections_table',
        '2026_10_24_000041_create_google_drive_exports_table',
        '2026_10_24_000042_create_google_drive_evidence_exports_table',
        '2026_10_24_000043_create_google_drive_syncs_table',
    ];

    protected $signature = 'app:adopt-squashed-migrations
        {--pretend : List the rows that would be deleted and inserted, without changing them}
        {--force : Change the migrations table outside the local environment}';

    protected $description = 'Record the squashed migrations in place of the migrations they replaced, without running any migration';

    public function handle(): int
    {
        if (! $this->option('pretend') && ! $this->option('force') && ! $this->laravel->environment('local')) {
            $this->error('Adoption refused: pass --force to change the migrations table outside the local environment.');

            return self::FAILURE;
        }

        /** @var MigrationRepositoryInterface $repository */
        $repository = $this->laravel->make('migration.repository');

        if (! $repository->repositoryExists()) {
            $this->error('Adoption refused: this database has no migrations table.');

            return self::FAILURE;
        }

        return DB::transaction(fn (): int => $this->adopt($repository));
    }

    /**
     * Refuses unless the database recorded exactly the replaced migrations, does nothing when it already records the
     * squashed ones, and otherwise swaps the rows, giving the squashed migrations the next batch number.
     */
    private function adopt(MigrationRepositoryInterface $repository): int
    {
        $recorded = $repository->getRan();
        $recordedReplaced = array_values(array_intersect(self::REPLACED_MIGRATIONS, $recorded));

        if ($recordedReplaced === [] && array_diff(self::SQUASHED_MIGRATIONS, $recorded) === []) {
            $this->info('Already adopted.');

            return self::SUCCESS;
        }

        $missing = array_values(array_diff(self::REPLACED_MIGRATIONS, $recorded));
        $unknown = array_values(array_diff($recorded, self::REPLACED_MIGRATIONS, self::SQUASHED_MIGRATIONS));

        if ($missing !== [] || $unknown !== []) {
            $this->error('Adoption refused: this database has not run exactly the replaced migrations. Nothing was changed.');
            $this->listMigrations('Replaced migrations that are not recorded:', $missing);
            $this->listMigrations('Recorded migrations that are in neither list:', $unknown);

            return self::FAILURE;
        }

        $toInsert = array_values(array_diff(self::SQUASHED_MIGRATIONS, $recorded));
        $batch = $repository->getNextBatchNumber();

        if ($this->option('pretend')) {
            $this->listMigrations(sprintf('Would delete %d rows:', count($recordedReplaced)), $recordedReplaced);
            $this->listMigrations(sprintf('Would insert %d rows with batch %d:', count($toInsert), $batch), $toInsert);

            return self::SUCCESS;
        }

        foreach ($recordedReplaced as $migration) {
            $repository->delete((object) ['migration' => $migration]);
        }

        foreach ($toInsert as $migration) {
            $repository->log($migration, $batch);
        }

        $this->info(sprintf(
            'Adopted the squashed migrations: deleted %d rows and recorded %d with batch %d.',
            count($recordedReplaced),
            count($toInsert),
            $batch,
        ));

        return self::SUCCESS;
    }

    /**
     * @param  list<string>  $migrations
     */
    private function listMigrations(string $heading, array $migrations): void
    {
        if ($migrations === []) {
            return;
        }

        $this->line($heading);

        foreach ($migrations as $migration) {
            $this->line("  {$migration}");
        }
    }
}
