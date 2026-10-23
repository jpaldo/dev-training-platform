<?php

namespace Tests\Feature;

use App\Models\Activity;
use App\Models\ActivitySubmission;
use App\Models\MentorAssignment;
use App\Models\SubmissionVersion;
use App\Models\TrainingSession;
use App\Models\TrainingWeek;
use App\Models\User;
use App\Rules\GitHubRepositoryUrl;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class SessionRepositoryUrlTest extends TestCase
{
    use LazilyRefreshDatabase;

    private const REPOSITORY_URL = 'https://github.com/ana/l4-accelerator';

    public function test_joining_stays_one_click_and_the_session_page_then_asks_for_the_repository(): void
    {
        $participant = User::factory()->create();
        $session = TrainingSession::factory()->active()->create();

        $this->actingAs($participant)->post(route('training.sessions.join', $session))
            ->assertRedirect(route('training.sessions.show', $session));

        $this->assertDatabaseHas('training_session_participants', ['training_session_id' => $session->id, 'user_id' => $participant->id, 'repository_url' => null]);
        $this->actingAs($participant)->get(route('training.sessions.show', $session))
            ->assertOk()
            ->assertSeeInOrder([
                '>Add your GitHub repository for this session</h2>',
                '<form method="POST" action="'.route('training.sessions.repository.update', $session).'"',
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
        $session = TrainingSession::factory()->active()->withParticipants($participant)->create();

        $this->actingAs($participant)
            ->from(route('training.sessions.show', $session))
            ->put(route('training.sessions.repository.update', $session), ['repository_url' => self::REPOSITORY_URL])
            ->assertRedirect(route('training.sessions.show', $session))
            ->assertSessionHasNoErrors()
            ->assertSessionHas('status', 'Your GitHub repository is saved.');

        $this->assertDatabaseHas('training_session_participants', ['training_session_id' => $session->id, 'user_id' => $participant->id, 'repository_url' => self::REPOSITORY_URL]);
        $this->actingAs($participant)->get(route('training.sessions.show', $session))
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
            ->assertDontSee('Add your GitHub repository for this session');
    }

    public function test_a_member_changes_their_repository(): void
    {
        $participant = User::factory()->create();
        $session = TrainingSession::factory()->active()->withParticipants($participant)->create();
        $session->participants()->updateExistingPivot($participant->id, ['repository_url' => self::REPOSITORY_URL]);

        $this->actingAs($participant)
            ->put(route('training.sessions.repository.update', $session), ['repository_url' => 'https://github.com/ana/l4-v2/'])
            ->assertSessionHasNoErrors();

        $this->assertDatabaseHas('training_session_participants', ['user_id' => $participant->id, 'repository_url' => 'https://github.com/ana/l4-v2/']);
    }

    #[DataProvider('rejectedRepositoryLinks')]
    public function test_a_link_that_is_not_a_github_repository_fails_and_keeps_the_saved_one(string $value, string $message): void
    {
        $participant = User::factory()->create();
        $session = TrainingSession::factory()->active()->withParticipants($participant)->create();
        $session->participants()->updateExistingPivot($participant->id, ['repository_url' => self::REPOSITORY_URL]);

        $this->actingAs($participant)
            ->from(route('training.sessions.show', $session))
            ->put(route('training.sessions.repository.update', $session), ['repository_url' => $value])
            ->assertRedirect(route('training.sessions.show', $session))
            ->assertSessionHasErrors(['repository_url' => $message]);

        $this->assertDatabaseHas('training_session_participants', ['user_id' => $participant->id, 'repository_url' => self::REPOSITORY_URL]);
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
        $session = TrainingSession::factory()->active()->withParticipants($participant)->create();
        $session->participants()->updateExistingPivot($participant->id, ['repository_url' => self::REPOSITORY_URL]);

        $content = $this->actingAs($participant)
            ->from(route('training.sessions.show', $session))
            ->followingRedirects()
            ->put(route('training.sessions.repository.update', $session), ['repository_url' => 'https://github.com/ana'])
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

    public function test_only_a_participant_in_the_session_can_save_a_repository(): void
    {
        $member = User::factory()->create();
        $session = TrainingSession::factory()->active()->withParticipants($member)->create();
        $outsider = User::factory()->create();
        TrainingSession::factory()->active()->withParticipants($outsider)->create();
        $coach = User::factory()->create(['role' => 'coach']);
        $admin = User::factory()->create(['role' => 'admin']);

        foreach ([$outsider, $coach, $admin] as $user) {
            $this->actingAs($user)
                ->put(route('training.sessions.repository.update', $session), ['repository_url' => self::REPOSITORY_URL])
                ->assertForbidden();
        }

        $this->assertDatabaseMissing('training_session_participants', ['repository_url' => self::REPOSITORY_URL]);
    }

    public function test_the_mentor_sees_the_repository_of_the_submissions_session_on_the_review_and_scoring_pages(): void
    {
        [$coach, $participant, $sessionA, $sessionB, $version] = $this->mentorFixture();
        $sessionA->participants()->updateExistingPivot($participant->id, ['repository_url' => self::REPOSITORY_URL]);
        $sessionB->participants()->updateExistingPivot($participant->id, ['repository_url' => 'https://github.com/ana/other-session']);

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
                ->assertDontSee('other-session')
                ->assertDontSee('No repository yet');
        }
    }

    public function test_the_mentor_sees_no_repository_yet_when_the_participant_has_none_in_the_submissions_session(): void
    {
        [$coach, $participant, , $sessionB, $version] = $this->mentorFixture();
        $sessionB->participants()->updateExistingPivot($participant->id, ['repository_url' => 'https://github.com/ana/other-session']);

        foreach ([route('coach.reviews.show', $version), route('coach.scoring.edit', $version)] as $url) {
            $this->actingAs($coach)->get($url)
                ->assertOk()
                ->assertSeeInOrder(['Repository:', 'No repository yet', '</header>'], false)
                ->assertDontSee('other-session');
        }
    }

    /**
     * A participant in two sessions of one program, a coach who is their current mentor in the first session, and a
     * submitted version of their attempt at a Week 2 activity in the first session.
     *
     * @return array{User, User, TrainingSession, TrainingSession, SubmissionVersion}
     */
    private function mentorFixture(): array
    {
        $participant = User::factory()->create();
        $coach = User::factory()->create(['role' => 'coach']);
        $week = TrainingWeek::factory()->create(['week_number' => 2]);
        $sessionA = TrainingSession::factory()->forWeek($week)->withParticipants($participant)->create();
        MentorAssignment::factory()->create(['training_session_id' => $sessionA->id, 'participant_id' => $participant->id, 'mentor_id' => $coach->id]);
        $sessionB = TrainingSession::factory()->forWeek($week)->withParticipants($participant)->create();
        $activity = Activity::factory()->for($week, 'week')->withScoringDimensions([['key' => 'D1', 'label' => 'Quality', 'max' => 100]])->create();
        $submission = ActivitySubmission::factory()->for($activity)->for($participant, 'participant')->for($sessionA)->create(['status' => 'submitted', 'submitted_at' => now()]);
        $version = SubmissionVersion::factory()->for($submission, 'submission')->create(['status' => 'submitted', 'submitted_at' => now()]);

        return [$coach, $participant, $sessionA, $sessionB, $version];
    }
}
