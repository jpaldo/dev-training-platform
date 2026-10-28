<?php

namespace Tests\Feature;

use App\Enums\ProgramStatus;
use App\Models\Activity;
use App\Models\ActivityRequirement;
use App\Models\ActivitySubmission;
use App\Models\AdminAuditLog;
use App\Models\EvidenceRequirement;
use App\Models\GateCriterion;
use App\Models\GateEvaluation;
use App\Models\MentorAssignment;
use App\Models\Program;
use App\Models\ProgramResource;
use App\Models\TrainingGate;
use App\Models\TrainingWeek;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class AdminCurriculumDuplicateTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_admin_duplicates_a_curriculum_with_all_its_content_and_a_schedule_and_it_is_audited(): void
    {
        $this->travelTo('2026-11-02 09:00:00');
        $admin = User::factory()->create(['role' => 'admin']);
        $source = $this->sourceCurriculum();
        $sourceContent = $this->contentOf($source);

        $response = $this->actingAs($admin)
            ->post(route('admin.programs.duplicate', $source), [
                'name' => 'Winter Cohort',
                'description' => "Line one\r\nLine two",
                'starts_on' => '2026-11-09',
                'offered' => '1',
            ]);

        $copy = Program::query()->where('name', 'Winter Cohort')->sole();
        $response->assertSessionHasNoErrors()
            ->assertRedirect(route('admin.programs.show', $copy).'#weeks')
            ->assertSessionHas('status', 'Created Winter Cohort as a copy of Spring Cohort.');

        $this->assertSame("Line one\nLine two", $copy->description);
        $this->assertTrue($copy->is_active);
        $this->assertSame(ProgramStatus::Upcoming, $copy->status());
        $this->assertSame($sourceContent, $this->contentOf($copy));
        $this->assertSame($sourceContent, $this->contentOf($source->fresh()));

        $this->assertSame([
            [1, '2026-11-09', '2026-11-15'],
            [2, '2026-11-16', '2026-11-22'],
            [3, '2026-11-23', '2026-11-29'],
        ], $copy->weeks()->orderBy('week_number')->get()->map(fn (TrainingWeek $week): array => [
            $week->week_number,
            $week->starts_on->toDateString(),
            $week->ends_on->toDateString(),
        ])->all());
        $this->assertSame('2026-09-07', $source->weeks()->where('week_number', 1)->sole()->starts_on->toDateString());

        $this->assertSame(0, $copy->participants()->count());
        $this->assertSame(0, $copy->mentors()->count());
        $this->assertSame(0, $copy->resources()->count());
        $this->assertSame(0, MentorAssignment::query()->where('program_id', $copy->id)->count());
        $this->assertSame(0, ActivitySubmission::query()->whereHas('activity.week', fn (Builder $weeks): Builder => $weeks->where('program_id', $copy->id))->count());
        $this->assertSame(0, GateEvaluation::query()->whereHas('gate.week', fn (Builder $weeks): Builder => $weeks->where('program_id', $copy->id))->count());

        $audits = AdminAuditLog::query()->orderBy('id')->get();
        $this->assertSame(['curriculum_duplicated', 'curriculum_scheduled'], $audits->pluck('action')->all());
        $this->assertSame($admin->id, $audits[0]->actor_id);
        $this->assertNull($audits[0]->target_user_id);
        $this->assertSame([
            'program_id' => $copy->id,
            'source_program_id' => $source->id,
            'name' => 'Winter Cohort',
            'weeks_count' => 3,
            'starts_on' => '2026-11-09',
            'offered' => true,
        ], $audits[0]->metadata);
        $this->assertSame($copy->id, $audits[1]->metadata['program_id']);
        $this->assertSame('2026-11-09', $audits[1]->metadata['starts_on']);
    }

    public function test_a_copy_without_a_start_date_or_the_offered_box_is_unscheduled_and_not_offered(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $source = $this->sourceCurriculum();

        $this->actingAs($admin)
            ->post(route('admin.programs.duplicate', $source), ['name' => 'Winter Cohort', 'description' => '', 'starts_on' => '', 'offered' => '0'])
            ->assertSessionHasNoErrors();

        $copy = Program::query()->where('name', 'Winter Cohort')->sole();
        $this->assertNull($copy->description);
        $this->assertFalse($copy->is_active);
        $this->assertSame(ProgramStatus::Unscheduled, $copy->status());
        $this->assertSame(0, $copy->weeks()->where(fn (Builder $weeks): Builder => $weeks->whereNotNull('starts_on')->orWhereNotNull('ends_on'))->count());
        $this->assertSame([], Program::query()->openToJoin()->whereKey($copy->id)->pluck('id')->all());

        $audit = AdminAuditLog::query()->sole();
        $this->assertSame('curriculum_duplicated', $audit->action);
        $this->assertSame([
            'program_id' => $copy->id,
            'source_program_id' => $source->id,
            'name' => 'Winter Cohort',
            'weeks_count' => 3,
            'starts_on' => null,
            'offered' => false,
        ], $audit->metadata);
    }

    public function test_editing_the_copy_leaves_the_source_unchanged(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $source = $this->sourceCurriculum();
        $sourceContent = $this->contentOf($source);

        $this->actingAs($admin)
            ->post(route('admin.programs.duplicate', $source), ['name' => 'Winter Cohort'])
            ->assertSessionHasNoErrors();

        $copy = Program::query()->where('name', 'Winter Cohort')->sole();
        $copyWeekIds = $copy->weeks()->pluck('id');
        $this->assertSame([], $copyWeekIds->intersect($source->weeks()->pluck('id'))->all());

        TrainingWeek::query()->whereIn('id', $copyWeekIds)->update(['title' => 'Changed']);
        Activity::query()->whereIn('training_week_id', $copyWeekIds)->update(['title' => 'Changed', 'is_active' => true]);
        ActivityRequirement::query()->whereHas('activity', fn (Builder $activities): Builder => $activities->whereIn('training_week_id', $copyWeekIds))->update(['title' => 'Changed']);
        EvidenceRequirement::query()->whereHas('activityRequirement.activity', fn (Builder $activities): Builder => $activities->whereIn('training_week_id', $copyWeekIds))->update(['title' => 'Changed']);
        TrainingGate::query()->whereIn('training_week_id', $copyWeekIds)->update(['title' => 'Changed']);
        GateCriterion::query()->whereHas('gate', fn (Builder $gates): Builder => $gates->whereIn('training_week_id', $copyWeekIds))->update(['title' => 'Changed', 'is_required' => true]);

        $this->assertSame($sourceContent, $this->contentOf($source->fresh()));
    }

    /**
     * @param  array<string, string>  $input
     */
    #[DataProvider('invalidCopies')]
    public function test_invalid_input_returns_to_the_form_and_creates_nothing(array $input, string $field, string $message): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $source = $this->sourceCurriculum();
        Program::factory()->create(['name' => 'Taken Name']);
        $form = route('admin.programs.duplicate.create', $source);

        $this->actingAs($admin)
            ->from($form)
            ->post(route('admin.programs.duplicate', $source), ['name' => 'Winter Cohort', ...$input])
            ->assertRedirect($form)
            ->assertSessionHasErrors([$field => $message]);

        $this->assertSame(['Spring Cohort', 'Taken Name'], Program::query()->orderBy('id')->pluck('name')->all());
        $this->assertDatabaseCount('training_weeks', 3);
        $this->assertDatabaseCount('admin_audit_logs', 0);
    }

    /**
     * @return array<string, array{array<string, string>, string, string}>
     */
    public static function invalidCopies(): array
    {
        return [
            'blank name' => [['name' => ''], 'name', 'The name field is required.'],
            'name taken' => [['name' => 'Taken Name'], 'name', 'Another program already has this name.'],
            'the source name' => [['name' => 'Spring Cohort'], 'name', 'Another program already has this name.'],
            'name too long' => [['name' => str_repeat('a', 256)], 'name', 'The name field must not be greater than 255 characters.'],
            'not a date' => [['starts_on' => 'next monday-ish'], 'starts_on', 'The start date field must be a valid date.'],
            'offered not a boolean' => [['offered' => 'maybe'], 'offered', 'The offered to participants field must be true or false.'],
        ];
    }

    public function test_the_program_page_links_to_the_duplicate_form_which_starts_from_the_source_description(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $source = $this->sourceCurriculum();
        $formUrl = route('admin.programs.duplicate.create', $source);

        $this->actingAs($admin)
            ->get(route('admin.programs.show', $source))
            ->assertOk()
            ->assertSeeInOrder(['<a href="'.$formUrl.'"', 'Duplicate'], false);

        $form = $this->actingAs($admin)
            ->get($formUrl)
            ->assertOk()
            ->assertSee('Duplicate Spring Cohort')
            ->assertSee('action="'.route('admin.programs.duplicate', $source).'"', false)
            ->assertSee('>Four weeks of training.</textarea>', false)
            ->assertDontSee('name="weeks_count"', false)
            ->assertSee('name="starts_on"', false)
            ->assertSee('Offered to participants')
            ->getContent();

        $this->assertMatchesRegularExpression('/<input\s+id="program-name"[^>]*value=""/', $form);
        $this->assertDoesNotMatchRegularExpression('/<input\s+id="program-offered"[^>]*\bchecked\b/', $form);
    }

    public function test_the_form_shows_a_failed_field_beside_it_and_keeps_the_typed_values(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $source = $this->sourceCurriculum();

        $response = $this->actingAs($admin)
            ->from(route('admin.programs.duplicate.create', $source))
            ->followingRedirects()
            ->post(route('admin.programs.duplicate', $source), ['name' => 'Spring Cohort', 'description' => 'Typed description', 'starts_on' => '2026-11-09', 'offered' => '1'])
            ->assertOk()
            ->assertSeeInOrder([
                'value="Spring Cohort"',
                'aria-invalid="true" aria-describedby="program-name-error"',
                '<p id="program-name-error" class="text-sm text-rose-200" role="alert">Another program already has this name.</p>',
                '>Typed description</textarea>',
                'value="2026-11-09"',
            ], false);

        $this->assertMatchesRegularExpression('/<input\s+id="program-offered"[^>]*\bchecked\b/', $response->getContent());
    }

    public function test_participants_and_coaches_are_forbidden_and_guests_are_sent_to_login(): void
    {
        $source = $this->sourceCurriculum();
        $coach = User::factory()->create(['role' => 'coach']);
        $source->mentors()->attach($coach);
        $participant = $source->participants()->sole();
        $input = ['name' => 'Winter Cohort'];

        $this->get(route('admin.programs.duplicate.create', $source))->assertRedirect(route('login'));
        $this->post(route('admin.programs.duplicate', $source), $input)->assertRedirect(route('login'));

        foreach ([$participant, $coach] as $user) {
            $this->actingAs($user)->get(route('admin.programs.duplicate.create', $source))->assertForbidden();
            $this->actingAs($user)->post(route('admin.programs.duplicate', $source), $input)->assertForbidden();
        }

        $this->assertDatabaseCount('programs', 1);
        $this->assertDatabaseCount('admin_audit_logs', 0);
    }

    /**
     * "Spring Cohort": three dated weeks (week 2 inactive, positions out of number order), activities active and not,
     * with scoring dimensions, submission fields (one removed) and evidence requirements, and checklists active and
     * not with items on them and retired; plus a participant with work, a mentor assignment and a resource.
     */
    private function sourceCurriculum(): Program
    {
        $participant = User::factory()->create(['role' => 'participant']);
        $coach = User::factory()->create(['role' => 'coach']);
        $source = Program::factory()
            ->withParticipants($participant)
            ->withMentors($coach)
            ->create(['name' => 'Spring Cohort', 'description' => 'Four weeks of training.']);

        $weekOne = TrainingWeek::factory()->for($source)->opensOn(Carbon::parse('2026-09-07'))->create(['week_number' => 1, 'position' => 1, 'title' => 'Foundations']);
        $weekTwo = TrainingWeek::factory()->for($source)->opensOn(Carbon::parse('2026-09-14'))->create(['week_number' => 2, 'position' => 3, 'title' => 'Retired week', 'is_active' => false]);
        TrainingWeek::factory()->for($source)->opensOn(Carbon::parse('2026-09-21'))->create(['week_number' => 3, 'position' => 2, 'title' => 'Testing', 'description' => null]);

        $scored = Activity::factory()->for($weekOne, 'week')->withScoringDimensions()->create(['position' => 2, 'title' => 'Build the API', 'requires_coach_review' => true]);
        Activity::factory()->for($weekOne, 'week')->create(['position' => 1, 'title' => 'Retired activity', 'is_active' => false]);
        Activity::factory()->for($weekTwo, 'week')->create(['position' => 1, 'title' => 'Inactive week activity']);

        $field = ActivityRequirement::factory()->for($scored)->create([
            'requirement_type' => 'submission_field',
            'position' => 1,
            'title' => 'Repository link',
            'configuration' => ['key' => 'repository_link', 'label' => 'Repository link', 'type' => 'github_url', 'required' => true],
        ]);
        ActivityRequirement::factory()->for($scored)->create([
            'requirement_type' => 'submission_field',
            'position' => 2,
            'title' => 'Old notes',
            'is_required' => false,
            'configuration' => ['key' => 'notes', 'label' => 'Old notes', 'type' => 'textarea', 'deprecated' => true],
        ]);
        EvidenceRequirement::factory()->for($field)->create(['position' => 1, 'evidence_type' => 'file', 'minimum_count' => 1, 'maximum_count' => 3, 'configuration' => ['accept' => ['image/png']]]);

        $checklist = TrainingGate::factory()->for($weekOne, 'week')->create(['position' => 1, 'title' => 'Week 1 checklist']);
        TrainingGate::factory()->for($weekOne, 'week')->create(['position' => 2, 'title' => 'Old checklist', 'is_active' => false]);
        GateCriterion::factory()->for($checklist, 'gate')->create(['position' => 1, 'criterion_type' => GateCriterion::TYPE_SESSION_LOGS]);
        GateCriterion::factory()->for($checklist, 'gate')->create(['position' => 2, 'criterion_type' => GateCriterion::TYPE_DELIVERABLE, 'is_required' => false, 'configuration' => ['expected_files' => ['README.md']]]);

        ActivitySubmission::factory()->for($scored)->for($participant, 'participant')->create();
        GateEvaluation::factory()->for($checklist, 'gate')->for($participant, 'participant')->create();
        MentorAssignment::factory()->create(['program_id' => $source->id, 'participant_id' => $participant->id, 'mentor_id' => $coach->id]);
        ProgramResource::factory()->for($source)->create();

        return $source;
    }

    /**
     * The curriculum's content as plain arrays, in number and position order, without ids, parent ids, timestamps or
     * week dates, so a copy compares equal to its source.
     *
     * @return list<array<string, mixed>>
     */
    private function contentOf(Program $program): array
    {
        return $program->weeks()
            ->with(['activities.requirements.evidenceRequirements', 'gates.criteria'])
            ->orderBy('week_number')
            ->get()
            ->map(fn (TrainingWeek $week): array => [
                ...$this->attributesOf($week, 'program_id', 'starts_on', 'ends_on'),
                'activities' => $week->activities->sortBy('position')->values()->map(fn (Activity $activity): array => [
                    ...$this->attributesOf($activity, 'training_week_id'),
                    'requirements' => $activity->requirements->sortBy('position')->values()->map(fn (ActivityRequirement $requirement): array => [
                        ...$this->attributesOf($requirement, 'activity_id'),
                        'evidence' => $requirement->evidenceRequirements->sortBy('position')->values()
                            ->map(fn (EvidenceRequirement $evidence): array => $this->attributesOf($evidence, 'activity_requirement_id'))
                            ->all(),
                    ])->all(),
                ])->all(),
                'gates' => $week->gates->sortBy('position')->values()->map(fn (TrainingGate $gate): array => [
                    ...$this->attributesOf($gate, 'training_week_id'),
                    'criteria' => $gate->criteria->sortBy('position')->values()
                        ->map(fn (GateCriterion $criterion): array => $this->attributesOf($criterion, 'training_gate_id'))
                        ->all(),
                ])->all(),
            ])
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    private function attributesOf(Model $model, string ...$except): array
    {
        return Arr::except($model->attributesToArray(), ['id', 'created_at', 'updated_at', ...$except]);
    }
}
