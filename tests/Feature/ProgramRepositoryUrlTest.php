<?php

namespace Tests\Feature;

use App\Models\Activity;
use App\Models\ActivitySubmission;
use App\Models\MentorAssignment;
use App\Models\Program;
use App\Models\SubmissionVersion;
use App\Models\TrainingWeek;
use App\Models\User;
use App\Rules\GitHubRepositoryUrl;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ProgramRepositoryUrlTest extends TestCase
{
    use LazilyRefreshDatabase;

    private const REPOSITORY_URL = 'https://github.com/ana/l4-accelerator';

    public function test_joining_stays_one_click_and_the_curriculum_page_then_asks_for_the_repository(): void
    {
        $participant = User::factory()->create();
        $program = Program::factory()->withWeeks(2)->create();

        $this->actingAs($participant)->post(route('training.programs.join', $program))
            ->assertRedirect(route('training.programs.show', $program));

        $this->assertDatabaseHas('program_participants', ['program_id' => $program->id, 'user_id' => $participant->id, 'repository_url' => null]);
        $this->actingAs($participant)->get(route('training.programs.show', $program))
            ->assertOk()
            ->assertSeeInOrder([
                '>Add your GitHub repository for this program</h2>',
                '<form method="POST" action="'.route('training.programs.repository.update', $program).'"',
                'name="_method" value="PUT"',
                'name="repository_url"',
                'value=""',
                '>Save repository</button>',
                'data-page-tabs',
            ], false)
            ->assertDontSee('>Change</summary>', false);
    }

    public function test_a_member_saves_their_repository_and_sees_it_with_a_change_action(): void
    {
        $participant = User::factory()->create();
        $program = Program::factory()->withWeeks(2)->withParticipants($participant)->create();

        $this->actingAs($participant)
            ->from(route('training.programs.show', $program))
            ->put(route('training.programs.repository.update', $program), ['repository_url' => self::REPOSITORY_URL])
            ->assertRedirect(route('training.programs.show', $program))
            ->assertSessionHasNoErrors()
            ->assertSessionHas('status', 'Your GitHub repository is saved.');

        $this->assertDatabaseHas('program_participants', ['program_id' => $program->id, 'user_id' => $participant->id, 'repository_url' => self::REPOSITORY_URL]);
        $this->actingAs($participant)->get(route('training.programs.show', $program))
            ->assertOk()
            ->assertSeeInOrder([
                '>Your GitHub repository</h2>',
                '<a href="'.self::REPOSITORY_URL.'" target="_blank" rel="noopener noreferrer"',
                '>'.self::REPOSITORY_URL.'<span class="sr-only"> (opens in a new tab)</span></a>',
                '<details class="min-w-0" >',
                '>Change</summary>',
                'value="'.self::REPOSITORY_URL.'"',
                '>Save repository</button>',
                '</details>',
            ], false)
            ->assertDontSee('Add your GitHub repository for this program');
    }

    public function test_a_member_changes_their_repository(): void
    {
        $participant = User::factory()->create();
        $program = Program::factory()->withWeeks(2)->withParticipants($participant)->create();
        $program->participants()->updateExistingPivot($participant->id, ['repository_url' => self::REPOSITORY_URL]);

        $this->actingAs($participant)
            ->put(route('training.programs.repository.update', $program), ['repository_url' => 'https://github.com/ana/l4-v2/'])
            ->assertSessionHasNoErrors();

        $this->assertDatabaseHas('program_participants', ['user_id' => $participant->id, 'repository_url' => 'https://github.com/ana/l4-v2/']);
    }

    #[DataProvider('rejectedRepositoryLinks')]
    public function test_a_link_that_is_not_a_github_repository_fails_and_keeps_the_saved_one(string $value, string $message): void
    {
        $participant = User::factory()->create();
        $program = Program::factory()->withWeeks(2)->withParticipants($participant)->create();
        $program->participants()->updateExistingPivot($participant->id, ['repository_url' => self::REPOSITORY_URL]);

        $this->actingAs($participant)
            ->from(route('training.programs.show', $program))
            ->put(route('training.programs.repository.update', $program), ['repository_url' => $value])
            ->assertRedirect(route('training.programs.show', $program))
            ->assertSessionHasErrors(['repository_url' => $message]);

        $this->assertDatabaseHas('program_participants', ['user_id' => $participant->id, 'repository_url' => self::REPOSITORY_URL]);
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function rejectedRepositoryLinks(): array
    {
        return [
            'owner only' => ['https://github.com/ana', GitHubRepositoryUrl::MESSAGE],
            'branch' => ['https://github.com/ana/repo/tree/main', GitHubRepositoryUrl::MESSAGE],
            'another host' => ['https://gitlab.com/ana/repo', GitHubRepositoryUrl::MESSAGE],
            'blank' => ['', 'The repository link field is required.'],
            'too long' => ['https://github.com/ana/'.str_repeat('a', 240), 'The repository link field must not be greater than 255 characters.'],
        ];
    }

    public function test_a_rejected_change_reopens_the_form_with_the_error_and_the_input(): void
    {
        $participant = User::factory()->create();
        $program = Program::factory()->withWeeks(2)->withParticipants($participant)->create();
        $program->participants()->updateExistingPivot($participant->id, ['repository_url' => self::REPOSITORY_URL]);

        $content = $this->actingAs($participant)
            ->from(route('training.programs.show', $program))
            ->followingRedirects()
            ->put(route('training.programs.repository.update', $program), ['repository_url' => 'https://github.com/ana'])
            ->assertOk()
            ->assertSeeInOrder([
                '>Change</summary>',
                'value="https://github.com/ana"',
                'aria-invalid="true" aria-errormessage="repository_url_error"',
                '<p id="repository_url_error" class="text-sm text-danger" role="alert">'.e(GitHubRepositoryUrl::MESSAGE).'</p>',
            ], false)
            ->getContent();
        $this->assertMatchesRegularExpression('~<details class="min-w-0"\s+open\s*>~', $content);
    }

    public function test_only_a_participant_in_the_curriculum_can_save_a_repository(): void
    {
        $member = User::factory()->create();
        $program = Program::factory()->withWeeks(2)->withParticipants($member)->create();
        $outsider = User::factory()->create();
        Program::factory()->withWeeks(2)->withParticipants($outsider)->create();
        $coach = User::factory()->create(['role' => 'coach']);
        $admin = User::factory()->create(['role' => 'admin']);

        foreach ([$outsider, $coach, $admin] as $user) {
            $this->actingAs($user)
                ->put(route('training.programs.repository.update', $program), ['repository_url' => self::REPOSITORY_URL])
                ->assertForbidden();
        }

        $this->assertDatabaseMissing('program_participants', ['repository_url' => self::REPOSITORY_URL]);
    }

    public function test_the_mentor_sees_the_repository_of_the_submissions_curriculum_on_the_review_and_scoring_pages(): void
    {
        [$coach, $participant, $programA, $programB, $version] = $this->mentorFixture();
        $programA->participants()->updateExistingPivot($participant->id, ['repository_url' => self::REPOSITORY_URL]);
        $programB->participants()->updateExistingPivot($participant->id, ['repository_url' => 'https://github.com/ana/other-curriculum']);

        foreach ([route('coach.reviews.show', $version), route('coach.scoring.edit', $version)] as $url) {
            $this->actingAs($coach)->get($url)
                ->assertOk()
                ->assertSeeInOrder([
                    e($participant->name),
                    'Repository:',
                    '<a href="'.self::REPOSITORY_URL.'" target="_blank" rel="noopener noreferrer"',
                    '>'.self::REPOSITORY_URL.'<span class="sr-only"> (opens in a new tab)</span></a>',
                    '</header>',
                ], false)
                ->assertDontSee('other-curriculum')
                ->assertDontSee('No repository yet');
        }
    }

    public function test_the_mentor_sees_no_repository_yet_when_the_participant_has_none_in_the_submissions_curriculum(): void
    {
        [$coach, $participant, , $programB, $version] = $this->mentorFixture();
        $programB->participants()->updateExistingPivot($participant->id, ['repository_url' => 'https://github.com/ana/other-curriculum']);

        foreach ([route('coach.reviews.show', $version), route('coach.scoring.edit', $version)] as $url) {
            $this->actingAs($coach)->get($url)
                ->assertOk()
                ->assertSeeInOrder(['Repository:', 'No repository yet', '</header>'], false)
                ->assertDontSee('other-curriculum');
        }
    }

    /**
     * A participant in two curricula, a coach who is their current mentor in the first, and a submitted version of
     * their attempt at a Week 2 activity of the first.
     *
     * @return array{User, User, Program, Program, SubmissionVersion}
     */
    private function mentorFixture(): array
    {
        $participant = User::factory()->create();
        $coach = User::factory()->create(['role' => 'coach']);
        $programA = Program::factory()->withParticipants($participant)->withMentors($coach)->create();
        $week = TrainingWeek::factory()->for($programA)->create(['week_number' => 2]);
        MentorAssignment::factory()->create(['program_id' => $programA->id, 'participant_id' => $participant->id, 'mentor_id' => $coach->id]);
        $programB = Program::factory()->withParticipants($participant)->create();
        $activity = Activity::factory()->for($week, 'week')->withScoringDimensions([['key' => 'D1', 'label' => 'Quality', 'max' => 100]])->create();
        $submission = ActivitySubmission::factory()->for($activity)->for($participant, 'participant')->create(['status' => 'submitted', 'submitted_at' => now()]);
        $version = SubmissionVersion::factory()->for($submission, 'submission')->create(['status' => 'submitted', 'submitted_at' => now()]);

        return [$coach, $participant, $programA, $programB, $version];
    }
}
