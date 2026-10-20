<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $dropIndex = static function (string $table, string $index): void {
            $exists = collect(Schema::getIndexes($table))->contains(
                static fn (array $definition): bool => ($definition['name'] ?? null) === $index
            );

            if ($exists) {
                Schema::table($table, static function (Blueprint $blueprint) use ($index): void {
                    $blueprint->dropIndex($index);
                });
            }
        };

        $ensureIndex = static function (string $table, string $column, string $index): void {
            $exists = collect(Schema::getIndexes($table))->contains(
                static fn (array $definition): bool => ($definition['name'] ?? null) === $index
            );

            if (! $exists) {
                Schema::table($table, static function (Blueprint $blueprint) use ($column, $index): void {
                    $blueprint->index($column, $index);
                });
            }
        };

        $ensureIndex('google_drive_exports', 'user_id', 'google_drive_exports_user_id_index');
        $dropIndex('google_drive_exports', 'google_drive_exports_user_id_export_type_scope_key_unique');
        $dropIndex('google_drive_exports', 'google_drive_exports_destination_identity_unique');
        Schema::table('google_drive_exports', function (Blueprint $table): void {
            $table->unique(['user_id', 'export_type', 'scope_key', 'destination_folder_id'], 'google_drive_exports_destination_identity_unique');
        });

        $ensureIndex('google_drive_evidence_exports', 'submission_evidence_id', 'google_drive_evidence_exports_submission_evidence_id_index');
        $dropIndex('google_drive_evidence_exports', 'google_drive_evidence_exports_submission_evidence_id_unique');
        $dropIndex('google_drive_evidence_exports', 'google_drive_evidence_destination_identity_unique');
        Schema::table('google_drive_evidence_exports', function (Blueprint $table): void {
            $table->unique(['submission_evidence_id', 'destination_folder_id'], 'google_drive_evidence_destination_identity_unique');
        });
    }

    public function down(): void
    {
        Schema::table('google_drive_evidence_exports', function (Blueprint $table): void {
            $table->dropUnique('google_drive_evidence_destination_identity_unique');
            $table->unique('submission_evidence_id');
        });
        Schema::table('google_drive_exports', function (Blueprint $table): void {
            $table->dropUnique('google_drive_exports_destination_identity_unique');
            $table->unique(['user_id', 'export_type', 'scope_key']);
        });
    }
};
