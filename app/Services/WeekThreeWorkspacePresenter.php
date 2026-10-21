<?php

namespace App\Services;

use App\Models\Activity;
use App\Models\TrainingWeek;
use App\Models\User;
use App\Models\WeekThreeFinding;
use App\Models\WeekThreeTest;

class WeekThreeWorkspacePresenter
{
    /** @var array<string, array{string, bool, int}> The Week 3 Activity 1 finding fields: key => [label, required, rows]; `human_validation` is a select. */
    private const WEEK_THREE_FINDING_FIELDS = [
        'title' => ['Issue title', true, 2],
        'description' => ['Description', true, 4],
        'steps_to_reproduce' => ['Steps to reproduce', true, 4],
        'expected_result' => ['Expected result', true, 4],
        'actual_result' => ['Actual result', true, 4],
        'observed_evidence' => ['Observed behavior / evidence', false, 4],
        'ai_tool' => ['AI tool used (optional)', false, 4],
        'ai_proposed_root_cause' => ['AI-proposed root cause (optional)', false, 4],
        'human_validation' => ['Human validation', true, 1],
        'human_validation_explanation' => ['Evidence supporting your assessment', true, 4],
        'severity' => ['Severity', true, 2],
        'blast_radius' => ['Blast radius', true, 2],
        'affected_areas' => ['Affected features / areas', true, 4],
        'next_step' => ['Suggested fix or next diagnostic step', true, 4],
    ];

    /** @var array<string, string> The human validation assessments of a Week 3 finding. */
    private const WEEK_THREE_HUMAN_VALIDATIONS = ['supported' => 'Supported', 'partially_supported' => 'Partially Supported', 'unsupported' => 'Unsupported'];

    /** @var array<string, array{string, int}> The fields of a Week 3 test on every path: key => [label, rows]. */
    private const WEEK_THREE_TEST_FIELDS = ['name' => ['Test case name', 2], 'preconditions' => ['Preconditions', 3], 'steps' => ['Test steps', 3], 'expected_result' => ['Expected result', 3], 'actual_result' => ['Actual result', 3], 'execution_result' => ['Execution result / status', 3]];

    /** @var array<string, array<string, array{string, int}>> The path-specific fields of a Week 3 test: path => key => [label, rows]. */
    private const WEEK_THREE_PATH_TEST_FIELDS = [
        'automation' => ['framework' => ['Framework', 2], 'script_location' => ['Script / test location', 2], 'artifact_evidence' => ['Artifact evidence', 2], 'execution_evidence' => ['Execution report / evidence', 2]],
        'manual' => ['execution_evidence' => ['Test evidence', 3]],
    ];

    /** @var array<string, string> The text fields of a Week 3 fix validation: key => label. */
    private const WEEK_THREE_FIX_FIELDS = ['buggy_result' => 'Buggy branch result (l3/week-3)', 'bug_free_result' => 'Bug-free branch result (l3/week-3-bug-free)', 'evidence' => 'Supporting evidence'];

    /** @var array<string, string> The statuses of a Week 3 fix validation. */
    private const WEEK_THREE_FIX_STATUSES = ['fixed' => 'Fixed', 'partially_fixed' => 'Partially Fixed', 'not_fixed' => 'Not Fixed', 'unable_to_verify' => 'Unable to Verify'];

    /** @var array<string, string> The Week 3 Activity 3 regression summary fields: key => label. */
    private const WEEK_THREE_REGRESSION_FIELDS = ['regression_areas' => 'Areas tested', 'regression_issues' => 'Regression issues discovered', 'coverage_gaps' => 'Coverage gaps — what might these tests still miss?', 'final_conclusion' => 'Final conclusion'];

    public function __construct(private readonly WeekThreeWorkspaceService $weekThree) {}

    /**
     * The Week 3 workspace that the three activities share: the activity's stage (1–3), the findings / tests / fix
     * validations progress, one entry per finding slot, and on Activity 3 the regression summary fields. Activity 1
     * edits the findings; Activities 2 and 3 show each finding read-only and edit one test per required path, and
     * Activity 3 also edits the fix validation of each saved test.
     *
     * @return array{stage: int, progress: list<array{label: string, count: int, is_complete: bool}>, findings: list<array{number: int, finding: ?WeekThreeFinding, fields: list<array<string, mixed>>, tests: list<array{heading: string, fields: list<array<string, mixed>>, fix_fields: list<array<string, mixed>>}>}>, regression_fields: list<array<string, mixed>>}
     */
    public function present(User $participant, TrainingWeek $trainingWeek, Activity $activity): array
    {
        $workspace = $this->weekThree->workspace($participant, $trainingWeek)->load(['findings.tests.fixValidation']);
        $stage = max(1, min(3, $activity->position));
        $paths = $this->weekThree->requiredPaths($participant);
        $tests = $workspace->findings->flatMap->tests;
        $counts = [
            'Findings' => $workspace->findings->filter(fn (WeekThreeFinding $finding): bool => filled($finding->title))->count(),
            'Tests' => $tests->count(),
            'Fix Validations' => $tests->filter(fn (WeekThreeTest $test): bool => $test->fixValidation !== null)->count(),
        ];

        $findings = [];
        foreach (range(0, 4) as $index) {
            /** @var ?WeekThreeFinding $finding */
            $finding = $workspace->findings->get($index);
            $findings[] = [
                'number' => $index + 1,
                'finding' => $finding,
                'fields' => $stage === 1 ? $this->weekThreeFindingFields($index, $finding) : [],
                'tests' => $stage === 1 ? [] : array_map(
                    fn (string $path): array => $this->weekThreeTest($index, $path, $finding?->tests->firstWhere('path', $path), $stage === 3),
                    $paths,
                ),
            ];
        }

        $regressionFields = [];
        if ($stage === 3) {
            foreach (self::WEEK_THREE_REGRESSION_FIELDS as $key => $label) {
                $regressionFields[] = $this->weekThreeField($key, $label, true, 4, $workspace->{$key});
            }
        }

        return [
            'stage' => $stage,
            'progress' => array_map(fn (string $label, int $count): array => ['label' => $label, 'count' => $count, 'is_complete' => $count >= 5], array_keys($counts), array_values($counts)),
            'findings' => $findings,
            'regression_fields' => $regressionFields,
        ];
    }

    /**
     * The Activity 1 fields of a finding slot, filled from the saved finding.
     *
     * @return list<array<string, mixed>>
     */
    private function weekThreeFindingFields(int $index, ?WeekThreeFinding $finding): array
    {
        $fields = [];
        foreach (self::WEEK_THREE_FINDING_FIELDS as $key => [$label, $isRequired, $rows]) {
            $isSelect = $key === 'human_validation';
            $fields[] = $this->weekThreeField(
                "findings[{$index}][{$key}]",
                $label,
                $isRequired,
                $rows,
                $finding?->{$key},
                options: $isSelect ? self::WEEK_THREE_HUMAN_VALIDATIONS : null,
                placeholder: $isSelect ? 'Select an assessment' : '',
            );
        }

        return $fields;
    }

    /**
     * The test of a finding on one path: its fields, and when fix validation applies and the test is saved, the fix
     * validation fields.
     *
     * @return array{heading: string, fields: list<array<string, mixed>>, fix_fields: list<array<string, mixed>>}
     */
    private function weekThreeTest(int $index, string $path, ?WeekThreeTest $test, bool $validatesFix): array
    {
        $prefix = "findings[{$index}][tests][{$path}]";
        $fields = [];
        foreach ([...self::WEEK_THREE_TEST_FIELDS, ...self::WEEK_THREE_PATH_TEST_FIELDS[$path]] as $key => [$label, $rows]) {
            $fields[] = $this->weekThreeField("{$prefix}[{$key}]", $label, true, $rows, $test?->{$key});
        }

        $fixFields = [];
        if ($validatesFix && $test) {
            $fix = $test->fixValidation;
            foreach (self::WEEK_THREE_FIX_FIELDS as $key => $label) {
                $fixFields[] = $this->weekThreeField("{$prefix}[fix_validation][{$key}]", $label, true, 3, $fix?->{$key});
            }
            $fixFields[] = $this->weekThreeField("{$prefix}[fix_validation][status]", 'Fix validation status', true, 1, $fix?->status, self::WEEK_THREE_FIX_STATUSES, 'Select status');
        }

        return ['heading' => ucfirst($path).' test', 'fields' => $fields, 'fix_fields' => $fixFields];
    }

    /**
     * A Week 3 workspace control: its input name with the matching old-input key and element id, the label and required
     * marker, the textarea rows, the saved value, and for a select its options and placeholder option.
     *
     * @param  ?array<string, string>  $options
     * @return array{id: string, name: string, old_key: string, label: string, is_required: bool, rows: int, value: string, options: ?array<string, string>, placeholder: string}
     */
    private function weekThreeField(string $name, string $label, bool $isRequired, int $rows, ?string $value, ?array $options = null, string $placeholder = ''): array
    {
        $oldKey = str_replace(['][', '[', ']'], ['.', '.', ''], $name);

        return [
            'id' => 'week-three-'.str_replace(['.', '_'], '-', $oldKey),
            'name' => $name,
            'old_key' => $oldKey,
            'label' => $label,
            'is_required' => $isRequired,
            'rows' => $rows,
            'value' => $value ?? '',
            'options' => $options,
            'placeholder' => $placeholder,
        ];
    }
}
