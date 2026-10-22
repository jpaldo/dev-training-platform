<?php

namespace App\Services;

use App\Enums\TrainingPath;
use App\Models\Activity;
use App\Models\TrainingWeek;
use App\Models\User;
use App\Models\WeekThreeFinding;
use App\Models\WeekThreeTest;
use App\Models\WeekThreeWorkspace;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class WeekThreeWorkspaceService
{
    public const PATHS = ['manual', 'automation'];

    public function workspace(User $user, TrainingWeek $week): WeekThreeWorkspace
    {
        abort_unless($week->week_number === 3, 404);

        try {
            return WeekThreeWorkspace::query()->firstOrCreate([
                'user_id' => $user->id,
                'training_week_id' => $week->id,
            ], ['status' => 'draft']);
        } catch (QueryException $exception) {
            if (! str_contains(strtolower($exception->getMessage()), 'unique')) {
                throw $exception;
            }

            return WeekThreeWorkspace::query()->where('user_id', $user->id)->where('training_week_id', $week->id)->firstOrFail();
        }
    }

    public function save(User $user, TrainingWeek $week, array $payload): WeekThreeWorkspace
    {
        $workspace = $this->workspace($user, $week);
        $findings = array_slice($payload['findings'] ?? [], 0, 5, true);

        DB::transaction(function () use ($workspace, $findings, $payload, $user): void {
            $workspace->update(array_intersect_key($payload, array_flip(['regression_areas', 'regression_issues', 'coverage_gaps', 'final_conclusion'])));
            foreach ($findings as $position => $data) {
                $finding = $workspace->findings()->updateOrCreate(['position' => (int) $position + 1], array_intersect_key($data, array_flip([
                    'title', 'description', 'steps_to_reproduce', 'expected_result', 'actual_result', 'observed_evidence', 'ai_tool', 'ai_proposed_root_cause', 'human_validation', 'human_validation_explanation', 'severity', 'blast_radius', 'affected_areas', 'next_step',
                ])));
                foreach (($data['tests'] ?? []) as $path => $testData) {
                    if (! in_array($path, $this->requiredPaths($user), true)) {
                        continue;
                    }
                    $test = $finding->tests()->updateOrCreate(['path' => $path], array_merge(
                        array_intersect_key($testData, array_flip(['name', 'preconditions', 'steps', 'expected_result', 'actual_result', 'execution_result', 'framework', 'script_location', 'artifact_evidence', 'execution_evidence'])),
                        ['path' => $path],
                    ));
                    if (isset($testData['fix_validation'])) {
                        $test->fixValidation()->updateOrCreate([], array_intersect_key($testData['fix_validation'], array_flip(['buggy_result', 'bug_free_result', 'status', 'evidence', 'regression_issues', 'coverage_gaps'])));
                    }
                }
            }
        });

        return $workspace->fresh(['findings.tests.fixValidation']);
    }

    /**
     * The Week 3 test paths the participant must cover: both for an Automation / Manual participant, otherwise the
     * participant's own (Manual without a path).
     *
     * @return list<string>
     */
    public function requiredPaths(User $user): array
    {
        return match ($user->training_path) {
            TrainingPath::Automation => ['automation'],
            TrainingPath::AutomationManual => self::PATHS,
            default => ['manual'],
        };
    }

    public function validateForSubmission(User $user, Activity $activity): void
    {
        if ($activity->week?->week_number !== 3) {
            return;
        }

        $workspace = $this->workspace($user, $activity->week)->load(['findings.tests.fixValidation']);
        $findings = $workspace->findings;
        $complete = $findings->filter(fn (WeekThreeFinding $finding): bool => collect(['title', 'description', 'steps_to_reproduce', 'expected_result', 'actual_result', 'human_validation', 'human_validation_explanation', 'severity', 'blast_radius', 'affected_areas', 'next_step'])->every(fn (string $key): bool => filled($finding->{$key})));
        if ($activity->position === 1 && $complete->count() < 5) {
            throw ValidationException::withMessages(['week_three' => 'Complete five evidence-backed findings before submitting Activity 1.']);
        }
        if ($activity->position === 2) {
            $missing = $complete->filter(function (WeekThreeFinding $finding) use ($user): bool {
                return ! collect($this->requiredPaths($user))->every(function (string $path) use ($finding): bool {
                    $test = $finding->tests->firstWhere('path', $path);
                    $required = ['name', 'steps', 'expected_result', 'actual_result', 'execution_result', 'execution_evidence'];
                    if ($path === 'automation') {
                        $required = array_merge($required, ['framework', 'script_location', 'artifact_evidence']);
                    }

                    return $test && collect($required)->every(fn (string $key): bool => filled($test->{$key}));
                });
            })->count();
            if ($complete->count() < 5 || $missing > 0) {
                throw ValidationException::withMessages(['week_three' => 'Add complete path-specific tests for all five findings before submitting Activity 2.']);
            }
        }
        if ($activity->position === 3) {
            $missing = $complete->flatMap->tests->filter(fn (WeekThreeTest $test): bool => in_array($test->path, $this->requiredPaths($user), true))->filter(fn (WeekThreeTest $test): bool => ! $test->fixValidation || ! filled($test->fixValidation->buggy_result) || ! filled($test->fixValidation->bug_free_result) || ! filled($test->fixValidation->status) || ! filled($test->fixValidation->evidence))->count();
            if ($complete->count() < 5 || $missing > 0 || blank($workspace->regression_areas) || blank($workspace->regression_issues) || blank($workspace->coverage_gaps) || blank($workspace->final_conclusion)) {
                throw ValidationException::withMessages(['week_three' => 'Complete fix validation for every test and the regression/coverage conclusion before submitting Activity 3.']);
            }
        }
    }

    public function snapshot(User $user, TrainingWeek $week): array
    {
        $workspace = $this->workspace($user, $week)->load(['findings.tests.fixValidation']);

        return $workspace->toArray();
    }
}
