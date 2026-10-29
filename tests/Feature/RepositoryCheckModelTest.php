<?php

namespace Tests\Feature;

use App\Enums\RepositoryCheckStatus;
use App\Models\RepositoryCheck;
use App\Models\TrainingWeek;
use App\Models\User;
use Closure;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class RepositoryCheckModelTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_stored_check_reads_back_its_status_as_a_case_and_its_json_and_dates_as_values(): void
    {
        $check = RepositoryCheck::factory()->completed()->create(['ref_input' => 'main']);

        $check = RepositoryCheck::query()->findOrFail($check->id);

        $this->assertSame(RepositoryCheckStatus::Completed, $check->status);
        $this->assertSame('main', $check->ref_input);
        $this->assertSame('default_branch', $check->resolved_ref['type']);
        $this->assertSame('activity-outputs/week-1/README.md', $check->expected_files[0]['path']);
        $this->assertSame('present', $check->findings['files'][0]['status']);
        $this->assertSame('present_and_matches', $check->assessment['files']['activity-1-readme']['verdict']);
        $this->assertSame(4, $check->github_requests);
        $this->assertInstanceOf(Carbon::class, $check->queued_at);
        $this->assertInstanceOf(Carbon::class, $check->started_at);
        $this->assertInstanceOf(Carbon::class, $check->finished_at);
    }

    public function test_a_new_check_is_queued_with_no_github_requests(): void
    {
        $check = RepositoryCheck::query()->create([
            'user_id' => User::factory()->create()->id,
            'training_week_id' => TrainingWeek::factory()->create()->id,
            'repository_url' => 'https://github.com/ana/l4-accelerator',
            'expected_files' => [],
        ]);

        $check->refresh();

        $this->assertSame(RepositoryCheckStatus::Queued, $check->status);
        $this->assertSame(0, $check->github_requests);
        $this->assertNull($check->findings);
        $this->assertNull($check->assessment);
    }

    public function test_the_participant_week_scope_returns_only_that_participants_checks_for_that_week(): void
    {
        $participant = User::factory()->create();
        $week = TrainingWeek::factory()->create();
        $checks = RepositoryCheck::factory()->count(2)->create(['user_id' => $participant->id, 'training_week_id' => $week->id]);
        RepositoryCheck::factory()->create(['training_week_id' => $week->id]);
        RepositoryCheck::factory()->create(['user_id' => $participant->id]);

        $found = RepositoryCheck::query()->forParticipantWeek($participant, $week)->orderBy('id')->pluck('id')->all();

        $this->assertSame($checks->pluck('id')->all(), $found);
    }

    #[DataProvider('statuses')]
    public function test_the_predicates_follow_the_status(string $state, bool $isQueued, bool $isRunning, bool $isFinished): void
    {
        $check = RepositoryCheck::factory()->{$state}()->make();

        $this->assertSame($isQueued, $check->isQueued());
        $this->assertSame($isRunning, $check->isRunning());
        $this->assertSame($isFinished, $check->isFinished());
    }

    /**
     * @return array<string, array{string, bool, bool, bool}>
     */
    public static function statuses(): array
    {
        return [
            'queued' => ['queued', true, false, false],
            'running' => ['running', false, true, false],
            'completed' => ['completed', false, false, true],
            'failed' => ['failed', false, false, true],
        ];
    }

    /**
     * @param  Closure(RepositoryCheck): ?bool  $deleteOwner
     */
    #[DataProvider('owners')]
    public function test_deleting_the_owner_of_a_check_deletes_it(Closure $deleteOwner): void
    {
        $check = RepositoryCheck::factory()->completed()->create();
        $otherCheck = RepositoryCheck::factory()->completed()->create();

        $deleteOwner($check);

        $this->assertModelMissing($check);
        $this->assertModelExists($otherCheck);
    }

    /**
     * @return array<string, array{Closure(RepositoryCheck): ?bool}>
     */
    public static function owners(): array
    {
        return [
            'the week' => [fn (RepositoryCheck $check): ?bool => $check->week->delete()],
            'the curriculum' => [fn (RepositoryCheck $check): ?bool => $check->week->program->delete()],
            'the participant' => [fn (RepositoryCheck $check): ?bool => $check->participant->delete()],
        ];
    }

    public function test_deleting_who_started_the_check_keeps_it_without_a_requester(): void
    {
        $check = RepositoryCheck::factory()->completed()->create();

        $check->requestedBy->delete();

        $check->refresh();
        $this->assertNull($check->requested_by);
        $this->assertSame(RepositoryCheckStatus::Completed, $check->status);
    }
}
