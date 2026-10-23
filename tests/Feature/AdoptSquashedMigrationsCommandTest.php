<?php

namespace Tests\Feature;

use App\Console\Commands\AdoptSquashedMigrationsCommand;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class AdoptSquashedMigrationsCommandTest extends TestCase
{
    use LazilyRefreshDatabase;

    private const LATER_MIGRATION = '2026_11_02_090000_add_notes_to_training_sessions_table';

    public function test_it_replaces_the_old_migration_rows_with_the_squashed_ones_in_a_new_batch(): void
    {
        $this->recordMigrations(AdoptSquashedMigrationsCommand::REPLACED_MIGRATIONS, 6);

        $this->artisan('app:adopt-squashed-migrations', ['--force' => true])
            ->expectsOutputToContain('Adopted the squashed migrations: deleted 102 rows and recorded 43 with batch 7.')
            ->assertExitCode(0);

        $this->assertSame(
            $this->sortedByName(array_fill_keys(AdoptSquashedMigrationsCommand::SQUASHED_MIGRATIONS, 7)),
            $this->recordedMigrations(),
        );
    }

    public function test_it_does_nothing_when_the_squashed_migrations_are_already_recorded(): void
    {
        $this->recordMigrations(AdoptSquashedMigrationsCommand::SQUASHED_MIGRATIONS, 1);
        DB::table('migrations')->insert(['migration' => self::LATER_MIGRATION, 'batch' => 2]);
        $before = $this->recordedMigrations();

        $this->artisan('app:adopt-squashed-migrations', ['--force' => true])
            ->expectsOutputToContain('Already adopted.')
            ->assertExitCode(0);

        $this->assertSame($before, $this->recordedMigrations());
    }

    public function test_it_refuses_and_changes_nothing_when_an_old_migration_is_not_recorded(): void
    {
        $missing = AdoptSquashedMigrationsCommand::REPLACED_MIGRATIONS[0];
        $this->recordMigrations(array_slice(AdoptSquashedMigrationsCommand::REPLACED_MIGRATIONS, 1), 6);
        $before = $this->recordedMigrations();

        $this->artisan('app:adopt-squashed-migrations', ['--force' => true])
            ->expectsOutputToContain('Adoption refused: this database has not run exactly the replaced migrations.')
            ->expectsOutputToContain($missing)
            ->assertExitCode(1);

        $this->assertSame($before, $this->recordedMigrations());
    }

    public function test_it_refuses_and_changes_nothing_when_a_migration_outside_both_lists_is_recorded(): void
    {
        $this->recordMigrations([...AdoptSquashedMigrationsCommand::REPLACED_MIGRATIONS, self::LATER_MIGRATION], 6);
        $before = $this->recordedMigrations();

        $this->artisan('app:adopt-squashed-migrations', ['--force' => true])
            ->expectsOutputToContain('Adoption refused: this database has not run exactly the replaced migrations.')
            ->expectsOutputToContain(self::LATER_MIGRATION)
            ->assertExitCode(1);

        $this->assertSame($before, $this->recordedMigrations());
    }

    public function test_pretend_lists_the_rows_it_would_delete_and_insert_and_changes_nothing(): void
    {
        $this->recordMigrations(AdoptSquashedMigrationsCommand::REPLACED_MIGRATIONS, 6);
        $before = $this->recordedMigrations();

        $this->artisan('app:adopt-squashed-migrations', ['--pretend' => true])
            ->expectsOutputToContain('Would delete 102 rows:')
            ->expectsOutputToContain(AdoptSquashedMigrationsCommand::REPLACED_MIGRATIONS[0])
            ->expectsOutputToContain(AdoptSquashedMigrationsCommand::REPLACED_MIGRATIONS[101])
            ->expectsOutputToContain('Would insert 43 rows with batch 7:')
            ->expectsOutputToContain(AdoptSquashedMigrationsCommand::SQUASHED_MIGRATIONS[0])
            ->expectsOutputToContain(AdoptSquashedMigrationsCommand::SQUASHED_MIGRATIONS[42])
            ->assertExitCode(0);

        $this->assertSame($before, $this->recordedMigrations());
    }

    public function test_it_refuses_to_change_the_migrations_table_outside_the_local_environment_without_force(): void
    {
        $this->recordMigrations(AdoptSquashedMigrationsCommand::REPLACED_MIGRATIONS, 6);
        $before = $this->recordedMigrations();

        $this->artisan('app:adopt-squashed-migrations')
            ->expectsOutputToContain('Adoption refused: pass --force')
            ->assertExitCode(1);

        $this->assertSame($before, $this->recordedMigrations());
    }

    /**
     * Replaces every row of the migrations table with the given migrations, all in one batch.
     *
     * @param  list<string>  $migrations
     */
    private function recordMigrations(array $migrations, int $batch): void
    {
        DB::table('migrations')->delete();
        DB::table('migrations')->insert(array_map(
            fn (string $migration): array => ['migration' => $migration, 'batch' => $batch],
            $migrations,
        ));
    }

    /**
     * @return array<string, int> batch by migration name, sorted by name
     */
    private function recordedMigrations(): array
    {
        return DB::table('migrations')->orderBy('migration')->pluck('batch', 'migration')->all();
    }

    /**
     * @param  array<string, int>  $batches
     * @return array<string, int>
     */
    private function sortedByName(array $batches): array
    {
        ksort($batches);

        return $batches;
    }
}
