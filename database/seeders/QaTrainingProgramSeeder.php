<?php

namespace Database\Seeders;

use App\Models\Activity;
use App\Models\ActivityRequirement;
use App\Models\EvidenceRequirement;
use App\Models\GateCriterion;
use App\Models\Program;
use App\Models\TrainingGate;
use App\Models\TrainingWeek;
use Illuminate\Database\Seeder;

class QaTrainingProgramSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $program = Program::query()->updateOrCreate(
            ['name' => 'AI-Assisted QA Level 3'],
            ['description' => 'Official Week 2 through Week 4 QA training sequence.', 'is_active' => true],
        );

        $week2 = $this->week($program, 2, 'AI-Output Validation', 'Validate AI before you trust it.');
        $week3 = $this->week($program, 3, 'AI-Assisted Failure Analysis, Test Generation & Regression Intelligence', 'AI assists the work; the tester owns the conclusion.');
        $week4 = $this->week($program, 4, 'Reusable & Sustained Level 3 Practice', 'Demonstrate that your Level 3 QA + AI practices can be reused, explained to another person, and sustained independently.');

        $activity1 = $this->activity($week2, 1, 'Validation Checklist', 'Apply all six checks to a real AI-generated QA artifact from your own work.', 'One documented six-check validation run against a real AI-generated QA artifact.', 'Open the existing QA Validation Lab for the controlled OTP demonstration, then record the real-work artifact, every check outcome, and evidence here.', true, ['lab_route' => 'lab.activity']);
        $activity2 = $this->activity($week2, 2, 'AI Output Error Detection', 'Find and document at least one concrete AI mistake.', 'One async failure case proving what AI said, what was true, and what changed afterward.', 'Capture the exact AI output, actual behavior, evidence, checklist signal, and whether you fixed, rejected, or re-prompted it.', true);
        $activity3 = $this->activity($week2, 3, 'Pre-acceptance Check', 'Validate AI output before it enters shared QA assets or affects CI.', 'One documented pre-acceptance check on a higher-stakes QA action.', 'Record what the change would touch, where it would run, what you inspected or executed, and the outcome.', true);

        $activity4 = $this->activity($week3, 1, 'Test-Failure Root-Cause Analysis', 'Analyze five failed runs and identify evidence-backed root causes.', 'One RCA report covering five failed runs, severity, blast radius, and next step.', 'Pull five failed runs. Trace every AI claim to logs, stack traces, error messages, or code. State a shared cause or explicitly state that none was found.', true);
        $activity5 = $this->activity($week3, 2, 'Script from Description', 'Turn a real requirement into a working, validated test.', 'An executed AI-assisted test and Jira-to-framework-to-run-to-investigate write-up.', 'Start from a real requirement, generate a framework-specific test or structured manual steps, execute it, investigate the result, and record what was fixed or rejected.', true);
        $activity6 = $this->activity($week3, 3, 'Regression Prioritization and Coverage Gaps', 'Use a real PR and requirements to prioritize regression and expose missing coverage.', 'One regression-prioritization and coverage-gap report.', 'Compare changed files and the regression suite with real requirements. Validate AI priorities, identify gaps, and add missing scenarios.', true);
        $activity7 = $this->activity($week4, 1, 'Build & Validate a Reusable AI Asset', 'Create one reusable QA + AI asset and prove that it works on a second real task.', 'Demonstrate that the asset can be reused, explained, and improved.', 'Create or choose a prompt template, saved agent/configuration, checklist, test-generation template, RCA template, or regression checklist. Explain its purpose, use it on an original task and a second real task, compare the result, reflect, and attach supporting evidence.', true);
        $activity8 = $this->activity($week4, 2, 'Mock Level 3 Interview', 'Practice explaining your Level 3 QA + AI work to another participant, exchange feedback, and receive QA Coach feedback.', 'Completed mock interview with a registered partner, feedback on at least two answers, and Coach review.', 'Select a registered QA participant (not yourself), complete the interview questions, record feedback received on at least two answers, and submit it for separate QA Coach review.', true);
        $activity9 = $this->activity($week4, 3, 'Strict-mode Leveling Assessment', 'Complete the formal Strict-mode Leveling interview and record the result in the assessment tracker.', 'Completed transcript, tracker result, level, and confidence.', 'Complete the formal interview, retain the required transcript, append the result to the existing assessment tracker, and record the authoritative level and confidence.', true);

        $this->fieldsAndEvidence($activity1, [
            ['key' => 'validation_submission_id', 'label' => 'QA Validation Lab Record', 'type' => 'text', 'required' => true, 'guidance' => ['what' => 'Select the QA Validation Lab record for the artifact you are validating.', 'why' => 'The Lab record is the authoritative source for shared AI and artifact context.', 'example' => 'Select the submitted Lab record containing the artifact you checked.', 'avoid' => 'Do not select a record belonging to another participant.']],
            ['key' => 'issue_caught', 'label' => 'Issue Caught', 'type' => 'textarea'],
            ['key' => 'action_taken', 'label' => 'Action Taken', 'type' => 'textarea'],
            ['key' => 'finding_and_decision', 'label' => 'Concrete finding and resulting decision', 'type' => 'textarea', 'deprecated' => true],
        ], 'Validation evidence or reference');
        $this->pathRequirement($activity1, 'manual', 'Validate test steps, expected results, requirements, real behavior, and evidence.');
        $this->pathRequirement($activity1, 'automation', 'Validate selectors, assertions, scripts, framework conventions, execution, and evidence.');

        $this->fieldsAndEvidence($activity2, [
            ['key' => 'validation_submission_id', 'label' => 'QA Validation Lab Record', 'type' => 'text', 'required' => true, 'guidance' => ['what' => 'Select the QA Validation Lab record for the AI-generated artifact you are investigating.', 'why' => 'The Lab record is the authoritative artifact context for this connected Week 2 workflow.', 'example' => 'Select the Lab record containing the artifact you checked.', 'avoid' => 'Do not select a record belonging to another participant.']],
            ['key' => 'actual_truth', 'label' => 'What Was Actually True', 'type' => 'textarea', 'guidance' => ['what' => 'Describe the behavior, requirement, or result you confirmed after checking the AI output.', 'why' => 'This separates verified facts from the AI claim.', 'example' => 'The requirement states that the OTP expires after two minutes.', 'avoid' => 'Do not repeat an unverified AI assumption.']],
            ['key' => 'how_caught', 'label' => 'How I Caught It', 'type' => 'textarea', 'guidance' => ['what' => 'Explain how you discovered the issue, such as checking requirements, executing the test, reviewing implementation, or comparing expected and actual results.', 'why' => 'Your investigation method makes the error reproducible.', 'example' => 'I compared the output with the requirement and executed the expiry scenario.', 'avoid' => 'Do not claim checks you did not perform.']],
            ['key' => 'what_i_did', 'label' => 'What I Did', 'type' => 'textarea', 'guidance' => ['what' => 'Describe what you did after finding the issue, such as correcting the artifact, re-prompting AI, investigating further, or rejecting the output.', 'why' => 'The response shows how you handled the risk.', 'example' => 'I corrected the expected result and documented the supporting evidence.', 'avoid' => 'Do not leave the risk without an action or decision.']],
            ['key' => 'final_result', 'label' => 'Final Result', 'type' => 'textarea', 'guidance' => ['what' => 'Summarize the outcome after your validation and correction.', 'why' => 'This records the final QA-owned conclusion.', 'example' => 'The corrected test now matches the two-minute requirement.', 'avoid' => 'Do not summarize only the AI output.']],
            ['key' => 'detection_and_action', 'label' => 'How you caught it and what you did afterward', 'type' => 'textarea', 'deprecated' => true],
            ['key' => 'thread_drive_link', 'label' => 'Thread / Drive Link', 'type' => 'url', 'required' => false, 'deprecated' => true],
            ['key' => 'ai_tool', 'label' => 'AI Tool', 'type' => 'text', 'deprecated' => true],
            ['key' => 'artifact_type', 'label' => 'Artifact Type', 'type' => 'text', 'deprecated' => true],
            ['key' => 'prompt', 'label' => 'Prompt', 'type' => 'textarea', 'deprecated' => true],
            ['key' => 'exact_ai_output', 'label' => 'Exact AI output that was wrong', 'type' => 'textarea', 'deprecated' => true],
        ], 'Proof of the AI-wrong case');
        $this->pathRequirement($activity2, 'manual', 'Prove a wrong expected result, missing edge case, or other manual-QA AI error.');
        $this->pathRequirement($activity2, 'automation', 'Prove a fabricated selector, wrong assertion, flaky output, or other automation AI error.');

        $this->fieldsAndEvidence($activity3, [
            ['key' => 'validation_submission_id', 'label' => 'QA Validation Lab Record', 'type' => 'text', 'required' => true, 'guidance' => ['what' => 'Select the QA Validation Lab record for the AI-generated artifact you are evaluating before accepting it into your QA work.', 'why' => 'The selected Lab record identifies the artifact without re-entering it here.', 'example' => 'Select the Lab record you validated in Activities 1 and 2.', 'avoid' => 'Do not select a record belonging to another participant.']],
            ['key' => 'affected_scope', 'label' => 'What Could It Affect?', 'type' => 'textarea', 'guidance' => ['what' => 'Explain where this AI-generated work may be used and what could be affected if it is incorrect.', 'why' => 'Impact awareness supports a safe acceptance decision.', 'example' => 'It could affect shared test cases, expected results, and other QAs relying on the regression suite.', 'avoid' => 'Do not claim that CI is required; describe only applicable impact.']],
            ['key' => 'validation_performed', 'label' => 'Pre-Acceptance Check', 'type' => 'textarea', 'guidance' => ['what' => 'Describe what you verified before accepting the AI-generated work. Include requirements, implementation, framework conventions, execution results, or other evidence when applicable.', 'why' => 'This records the checks supporting your decision.', 'example' => 'I checked the requirement, reviewed selectors, and executed the relevant scenario.', 'avoid' => 'Do not claim every check if it was not applicable or performed.']],
            ['key' => 'decision', 'label' => 'Decision', 'type' => 'select', 'options' => ['Accept', 'Revise', 'Re-prompt AI', 'Reject'], 'guidance' => ['what' => 'Choose the decision that matches your verified result.', 'why' => 'The decision records whether this artifact is safe to use.', 'example' => 'Accept — Validation passed; no changes needed.', 'avoid' => 'Do not choose a result that is not supported by your checks.']],
            ['key' => 'validation_outcome', 'label' => 'Validation performed and result', 'type' => 'textarea', 'deprecated' => true],
            ['key' => 'final_notes', 'label' => 'Final Notes', 'type' => 'textarea', 'guidance' => ['what' => 'Briefly explain your decision and any changes or next steps required before the artifact can be used.', 'why' => 'Clear notes make follow-up ownership explicit.', 'example' => 'Revise the expiry assertion, rerun the scenario, and attach the corrected evidence.', 'avoid' => 'Do not leave unresolved risks unexplained.']],
            ['key' => 'higher_stakes_change', 'label' => 'Higher-stakes AI-generated test, fix, or QA change', 'type' => 'textarea', 'deprecated' => true],
            ['key' => 'ai_tool', 'label' => 'AI Tool', 'type' => 'text', 'deprecated' => true],
            ['key' => 'artifact_type', 'label' => 'Artifact Type', 'type' => 'text', 'deprecated' => true],
            ['key' => 'prompt', 'label' => 'Prompt', 'type' => 'textarea', 'deprecated' => true],
            ['key' => 'ai_output', 'label' => 'AI Output', 'type' => 'textarea', 'deprecated' => true],
            ['key' => 'pre_acceptance_question', 'label' => 'Pre-acceptance Question', 'type' => 'textarea', 'deprecated' => true],
        ], 'Pre-acceptance validation evidence');
        $this->weeklyLoomEvidence($activity3, 2);
        $this->pathRequirement($activity3, 'manual', 'Check affected tests, requirements, environment, and real behavior before acceptance.');
        $this->pathRequirement($activity3, 'automation', 'Check affected tests, helpers, framework components, environment, and CI before acceptance.');

        $this->fieldsAndEvidence($activity4, [
            ['key' => 'feature_area', 'label' => 'Feature / Area', 'type' => 'text'],
            ['key' => 'environment', 'label' => 'Environment', 'type' => 'text'],
            ['key' => 'test_scenario', 'label' => 'Test / Scenario', 'type' => 'textarea'],
            ['key' => 'failure_description', 'label' => 'Failure Description', 'type' => 'textarea'],
            ['key' => 'expected_result', 'label' => 'Expected Result', 'type' => 'textarea'],
            ['key' => 'actual_result', 'label' => 'Actual Result', 'type' => 'textarea'],
            ['key' => 'ai_tool', 'label' => 'AI Tool', 'type' => 'text'],
            ['key' => 'artifact_type', 'label' => 'Artifact Type', 'type' => 'text'],
            ['key' => 'exact_prompt', 'label' => 'Exact Prompt', 'type' => 'textarea', 'help' => 'Paste the exact prompt used for the failure analysis.'],
            ['key' => 'exact_ai_output', 'label' => 'Exact AI Output', 'type' => 'textarea', 'help' => 'Paste the complete AI analysis, including hypotheses you rejected.'],
            ['key' => 'failed_runs', 'label' => 'Five Failed Runs and Evidence-Backed Analysis', 'type' => 'textarea'],
            ['key' => 'diagnostic_steps', 'label' => 'Diagnostic Steps Performed', 'type' => 'textarea'],
            ['key' => 'evidence_reviewed', 'label' => 'Evidence Reviewed', 'type' => 'textarea'],
            ['key' => 'ai_suggested_root_causes', 'label' => 'AI-Suggested Root Cause(s)', 'type' => 'textarea'],
            ['key' => 'actual_root_cause', 'label' => 'Actual Root Cause', 'type' => 'textarea'],
            ['key' => 'shared_cause', 'label' => 'Shared Cause or Explicit No-Shared-Cause Conclusion', 'type' => 'textarea'],
            ['key' => 'root_cause_validation', 'label' => 'How Root Cause Was Validated', 'type' => 'textarea'],
            ['key' => 'severity', 'label' => 'Severity', 'type' => 'text'],
            ['key' => 'blast_radius', 'label' => 'Blast Radius', 'type' => 'textarea'],
            ['key' => 'affected_areas', 'label' => 'Affected Areas', 'type' => 'textarea'],
            ['key' => 'action_taken', 'label' => 'Action Taken', 'type' => 'textarea'],
            ['key' => 'impact_next_step', 'label' => 'Fix or Next Diagnostic Step', 'type' => 'textarea'],
            ['key' => 'final_result', 'label' => 'Final Result', 'type' => 'textarea'],
            ['key' => 'qa_conclusion', 'label' => 'QA Conclusion / Recommendation', 'type' => 'textarea'],
        ], 'RCA report (PDF)', 'file');
        $this->pathRequirement($activity4, 'manual', 'Validate failure evidence against logs, screenshots, behavior, and affected manual test areas.');
        $this->pathRequirement($activity4, 'automation', 'Validate failure evidence against logs, stack traces, code, fixtures, and affected automated tests.');

        $this->fieldsAndEvidence($activity5, [
            ['key' => 'feature_requirement_description', 'label' => 'Feature / Requirement Description', 'type' => 'textarea'],
            ['key' => 'requirement_mapping', 'label' => 'Requirement and Jira-to-test Mapping', 'type' => 'textarea'],
            ['key' => 'acceptance_criteria', 'label' => 'Acceptance Criteria / Expected Behavior', 'type' => 'textarea'],
            ['key' => 'ai_tool', 'label' => 'AI Tool', 'type' => 'text'],
            ['key' => 'artifact_type', 'label' => 'Artifact Type', 'type' => 'text'],
            ['key' => 'exact_prompt', 'label' => 'Exact Prompt', 'type' => 'textarea', 'help' => 'Paste the exact prompt used to generate the test.'],
            ['key' => 'exact_ai_output', 'label' => 'Exact AI Output', 'type' => 'textarea', 'help' => 'Paste the complete generated response.'],
            ['key' => 'generated_test', 'label' => 'Generated Script / Test Cases', 'type' => 'textarea'],
            ['key' => 'ai_assumptions', 'label' => 'Assumptions Made by AI', 'type' => 'textarea'],
            ['key' => 'validation_performed', 'label' => 'Validation Performed', 'type' => 'textarea'],
            ['key' => 'issues_corrections', 'label' => 'Issues / Corrections Identified', 'type' => 'textarea'],
            ['key' => 'corrected_script', 'label' => 'Corrected Script / Final Version', 'type' => 'textarea'],
            ['key' => 'execution_result', 'label' => 'Execution Result', 'type' => 'text'],
            ['key' => 'test_results_observations', 'label' => 'Test Results / Observations', 'type' => 'textarea'],
            ['key' => 'final_qa_decision', 'label' => 'Final QA Decision', 'type' => 'textarea'],
            ['key' => 'execution_investigation', 'label' => 'Execution Evidence and Investigation', 'type' => 'textarea', 'deprecated' => true],
            ['key' => 'repository_thread_link', 'label' => 'Repository / Thread / Drive Link', 'type' => 'url', 'required' => false],
        ], 'Executed AI-assisted test and write-up', 'file');
        $this->pathRequirement($activity5, 'manual', 'Generate structured test steps and expected results, validate data and behavior, and retain a validated artifact if automation is unavailable.');
        $this->pathRequirement($activity5, 'automation', 'Generate existing-framework code, reuse helpers/page objects, follow conventions, review selectors/assertions, and execute it.');

        $this->fieldsAndEvidence($activity6, [
            ['key' => 'feature_change', 'label' => 'Feature / Change Being Tested', 'type' => 'textarea'],
            ['key' => 'affected_areas', 'label' => 'Affected Areas', 'type' => 'textarea'],
            ['key' => 'risk_business_impact', 'label' => 'Risk / Business Impact', 'type' => 'textarea'],
            ['key' => 'ai_tool', 'label' => 'AI Tool', 'type' => 'text'],
            ['key' => 'artifact_type', 'label' => 'Artifact Type', 'type' => 'text'],
            ['key' => 'exact_prompt', 'label' => 'Exact Prompt', 'type' => 'textarea', 'help' => 'Paste the exact prompt used for regression analysis.'],
            ['key' => 'exact_ai_output', 'label' => 'Exact AI Output', 'type' => 'textarea', 'help' => 'Paste the complete AI recommendation.'],
            ['key' => 'ai_generated_prioritization', 'label' => 'AI-Generated Regression Prioritization', 'type' => 'textarea'],
            ['key' => 'ai_risk_reasoning', 'label' => 'AI Risk Reasoning', 'type' => 'textarea'],
            ['key' => 'validation_cross_check', 'label' => 'Validation / Cross-Check Performed', 'type' => 'textarea'],
            ['key' => 'qa_adjusted_priority', 'label' => 'QA-Adjusted Priority', 'type' => 'textarea'],
            ['key' => 'priority_change_reason', 'label' => 'Reason for Priority Changes', 'type' => 'textarea'],
            ['key' => 'pr_and_requirements', 'label' => 'PR, Changed Files, and Requirements Reviewed', 'type' => 'textarea', 'deprecated' => true],
            ['key' => 'coverage_gaps', 'label' => 'Coverage Gaps Identified', 'type' => 'textarea'],
            ['key' => 'high_risk_missing_tests', 'label' => 'High-Risk Missing Tests', 'type' => 'textarea'],
            ['key' => 'regression_priorities', 'label' => 'Validated Regression Priorities and Flaky-Test Analysis', 'type' => 'textarea', 'deprecated' => true],
            ['key' => 'final_regression_plan', 'label' => 'Regression Test Matrix / Final Regression Plan', 'type' => 'textarea'],
            ['key' => 'final_recommendation', 'label' => 'Final Recommendation', 'type' => 'textarea'],
            ['key' => 'final_notes', 'label' => 'Final Notes', 'type' => 'textarea'],
        ], 'Regression prioritization and coverage-gap report', 'file');
        $this->weeklyLoomEvidence($activity6, 3);
        $this->pathRequirement($activity6, 'manual', 'Compare requirements, exploratory charters, manual coverage, and PR behavior.');
        $this->pathRequirement($activity6, 'automation', 'Compare requirements, automated coverage, changed code, flaky tests, and framework execution.');

        $this->fieldsAndEvidence($activity7, [
            ['key' => 'asset_name_purpose', 'label' => 'Legacy asset notes', 'type' => 'textarea', 'deprecated' => true],
            ['key' => 'asset_inputs_instructions', 'label' => 'Legacy asset instructions', 'type' => 'textarea', 'deprecated' => true],
            ['key' => 'asset_validation_reuse', 'label' => 'Legacy reuse notes', 'type' => 'textarea', 'deprecated' => true],
            ['key' => 'team_library_location', 'label' => 'Legacy team-library link', 'type' => 'url', 'deprecated' => true],
            ['key' => 'asset_name', 'label' => 'Asset name', 'type' => 'text', 'required' => true],
            ['key' => 'asset_type', 'label' => 'Asset type', 'type' => 'select', 'required' => true, 'options' => ['Prompt Template', 'Saved Agent / Configuration', 'Checklist', 'Other Reusable QA Asset']],
            ['key' => 'asset_purpose', 'label' => 'Purpose', 'type' => 'textarea', 'required' => true, 'help' => 'Explain when another QA tester would use this asset and what it helps them accomplish.'],
            ['key' => 'asset_content', 'label' => 'Asset content', 'type' => 'textarea', 'required' => true, 'help' => 'Paste the reusable prompt, checklist, configuration notes, or template.'],
            ['key' => 'original_task', 'label' => 'Original task / scenario', 'type' => 'textarea', 'required' => true],
            ['key' => 'original_use', 'label' => 'How the asset was first used', 'type' => 'textarea', 'required' => true],
            ['key' => 'second_task', 'label' => 'Second real task / scenario', 'type' => 'textarea', 'required' => true],
            ['key' => 'reuse_method', 'label' => 'How did you reuse the asset?', 'type' => 'textarea', 'required' => true],
            ['key' => 'reuse_result', 'label' => 'What result did you get?', 'type' => 'textarea', 'required' => true],
            ['key' => 'reuse_modified', 'label' => 'Did you need to modify the asset?', 'type' => 'select', 'required' => true, 'options' => ['No — I reused it as-is', 'Yes — I made adjustments']],
            ['key' => 'reuse_changes', 'label' => 'What did you change and why?', 'type' => 'textarea'],
            ['key' => 'reusability_learning', 'label' => 'What made this asset reusable?', 'type' => 'textarea', 'required' => true],
            ['key' => 'reusability_improvement', 'label' => 'What would you improve before using it again?', 'type' => 'textarea', 'required' => true],
        ], 'Reusable AI Asset', 'asset');
        $this->pathRequirement($activity7, 'manual', 'Build a reusable checklist or prompt for repeated manual QA work and show a second-task result.');
        $this->pathRequirement($activity7, 'automation', 'Build a reusable script-generation, triage, or regression asset that follows framework conventions and show a second-task result.');

        $this->fieldsAndEvidence($activity8, [
            ['key' => 'interview_evidence', 'label' => 'Full Interview Guide answers grounded in real Weeks 1–4 work', 'type' => 'textarea'],
            ['key' => 'partner_feedback', 'label' => 'Partner feedback on at least two answers', 'type' => 'textarea'],
            ['key' => 'coach_feedback', 'label' => 'QA Coach feedback on at least one answer', 'type' => 'textarea'],
            ['key' => 'daily_log_reference', 'label' => 'Daily-log entry reference', 'type' => 'text'],
        ], 'Completed mock interview and feedback notes');
        $this->configureWeekFourInterviewFields($activity8);
        $this->pathRequirement($activity8, 'manual', 'Ground answers in validated manual QA work and explain concrete evidence.');
        $this->pathRequirement($activity8, 'automation', 'Ground answers in validated automation QA work and explain framework evidence.');

        $this->fieldsAndEvidence($activity9, [
            ['key' => 'strict_interview_transcript', 'label' => 'Strict-mode Leveling interview transcript', 'type' => 'textarea'],
            ['key' => 'assessment_tracker_result', 'label' => 'Assessment tracker result appended', 'type' => 'textarea'],
            ['key' => 'updated_level_confidence', 'label' => 'Updated level and confidence recorded for the next dashboard build', 'type' => 'textarea'],
        ], 'Strict-mode transcript and tracker result', 'file');
        $this->configureWeekFourAssessmentFields($activity9);
        $this->weeklyLoomEvidence($activity9, 4);
        $this->pathRequirement($activity9, 'manual', 'Use real manual QA work from Weeks 1–4 in the strict interview evidence.');
        $this->pathRequirement($activity9, 'automation', 'Use real automation QA work from Weeks 1–4 in the strict interview evidence.');

        $gate2 = $this->gate($week2, 'Week 2 Gate', 'Evidence that the validation skill has been internalized.');
        foreach ([
            ['daily_logs', 'Daily-log entries for all five sessions.'],
            ['six_check', 'One complete six-check validation run.'],
            ['ai_wrong_case', 'One concrete AI-wrong case with evidence.'],
            ['pre_acceptance', 'One pre-acceptance check.'],
            ['coach_review', 'Checklist recall and coach review passed.'],
        ] as $position => [$type, $title]) {
            GateCriterion::query()->updateOrCreate(['training_gate_id' => $gate2->id, 'position' => $position + 1], ['criterion_type' => $type, 'title' => $title, 'description' => $title, 'is_required' => true]);
        }
        $this->weeklyVideoCriterion($gate2, 6, 'Upload a video recording demonstrating your actual AI-output validation work and explaining your validation process, evidence, issue caught, and final QA decision.');
        $gate3 = $this->gate($week3, 'Week 3 Gate', 'Coach-reviewed full packet for failure analysis, generated testing, and regression intelligence.');
        foreach ([
            ['tracker_complete', 'Tracker complete with validation outcomes.'],
            ['rca_pdf', 'RCA PDF covering five failed runs.'],
            ['executed_test', 'Executed AI-assisted test with evidence.'],
            ['regression_report', 'Regression prioritization and coverage-gap report.'],
            ['loom_link', 'Loom walkthrough explaining prompts and iterations.'],
            ['drive_folder', 'Drive folder complete with the full packet.'],
        ] as $position => [$type, $title]) {
            GateCriterion::query()->updateOrCreate(['training_gate_id' => $gate3->id, 'position' => $position + 1], ['criterion_type' => $type, 'title' => $title, 'description' => $title, 'is_required' => true]);
        }
        $this->weeklyVideoCriterion($gate3, 5, 'Upload a video recording demonstrating your actual AI-assisted QA work and explaining your failure analysis, test generation and/or regression reasoning, evidence reviewed, and final conclusion.');
        $gate4 = $this->gate($week4, 'Week 4 Gate', 'Confirm the Level 3 habit is sustained beyond one interview.');
        foreach ([
            ['tracker_use', 'Validation Checklist use visible across Weeks 2–4.'],
            ['strict_interview', 'Strict-mode Leveling interview completed.'],
            ['asset_live', 'Reusable asset is live in the team library.'],
            ['tracker_updated', 'Reassessment result appended to the assessment tracker.'],
            ['dashboard_updated', 'Next dashboard build reflects updated level and confidence.'],
            ['level3', 'Assessment result is Level 3.'],
            ['confidence', 'Assessment confidence is Medium or higher.'],
            ['workflow_integration', 'Workflow Integration score is at least 3.'],
            ['validation_score', 'Validation score is at least 3.'],
            ['yesterday_example', 'At least one concrete “yesterday” example is recorded.'],
            ['no_rule5_red_flag', 'No Rule-5 red-flag phrase is present.'],
            ['coach_review', 'QA Coach Gate review passed.'],
        ] as $position => [$type, $title]) {
            GateCriterion::query()->updateOrCreate(['training_gate_id' => $gate4->id, 'position' => $position + 1], ['criterion_type' => $type, 'title' => $title, 'description' => $title, 'is_required' => true]);
        }
        $this->weeklyVideoCriterion($gate4, 13, 'Upload a video recording demonstrating your actual Level 3 QA work, reusable asset/workflow, and QA reasoning.');
    }

    private function week(Program $program, int $number, string $title, string $description): TrainingWeek
    {
        return TrainingWeek::query()->updateOrCreate(['program_id' => $program->id, 'week_number' => $number], ['title' => $title, 'description' => $description, 'position' => $number - 1, 'is_active' => true]);
    }

    private function activity(TrainingWeek $week, int $position, string $title, string $description, string $objectives, string $instructions, bool $review, array $configuration = []): Activity
    {
        return Activity::query()->updateOrCreate(['training_week_id' => $week->id, 'position' => $position], compact('title', 'description', 'objectives', 'instructions') + ['requires_coach_review' => $review, 'configuration' => $configuration, 'is_active' => true]);
    }

    private function pathRequirement(Activity $activity, string $path, string $description): void
    {
        ActivityRequirement::query()->updateOrCreate(['activity_id' => $activity->id, 'participant_path' => $path, 'requirement_type' => 'path_guidance', 'position' => $path === 'manual' ? 20 : 21], ['title' => ucfirst($path).' QA path', 'description' => $description, 'is_required' => true]);
    }

    private function weeklyLoomEvidence(Activity $activity, int $weekNumber): void
    {
        // Retained as a compatibility shim for old seed call sites. Weekly
        // recordings are now owned by the week/gate, never Activity 3.
    }

    /**
     * Replace the old free-form interview packet with guided, participant-scoped fields.
     */
    private function configureWeekFourInterviewFields(Activity $activity): void
    {
        $this->deprecateFields($activity, ['interview_evidence', 'partner_feedback', 'coach_feedback', 'daily_log_reference']);
        $this->upsertConfiguredFields($activity, array_merge([
            ['key' => 'partner_user_id', 'label' => 'Interview partner', 'type' => 'participant_selector', 'required' => true, 'help' => 'Select a registered QA participant. You cannot select yourself.'],
            ['key' => 'interview_date', 'label' => 'Interview date', 'type' => 'date', 'required' => true],
            ['key' => 'interview_notes', 'label' => 'Optional interview notes', 'type' => 'textarea'],
            ['key' => 'question_1_answer', 'label' => 'Question 1 — Tell your partner about a real QA issue where AI helped you investigate the problem.', 'type' => 'textarea', 'required' => true],
            ['key' => 'question_1_partner_feedback', 'label' => 'Partner feedback on Question 1', 'type' => 'textarea'],
            ['key' => 'question_2_answer', 'label' => 'Question 2 — How did you validate that the AI output was correct instead of trusting it directly?', 'type' => 'textarea', 'required' => true],
            ['key' => 'question_2_partner_feedback', 'label' => 'Partner feedback on Question 2', 'type' => 'textarea'],
            ['key' => 'question_3_answer', 'label' => 'Question 3 — Describe something you changed or improved after validating the AI output.', 'type' => 'textarea', 'required' => true],
            ['key' => 'question_3_partner_feedback', 'label' => 'Partner feedback on Question 3', 'type' => 'textarea'],
        ], $this->weekFourInterviewQuestionFields()));
        ActivityRequirement::query()->where('activity_id', $activity->id)->where('requirement_type', 'submission_field')->whereIn('configuration->key', ['question_1_answer', 'question_2_answer', 'question_3_answer'])->update(['is_required' => false]);
    }

    /** @return array<int, array<string, mixed>> */
    private function weekFourInterviewQuestionFields(): array
    {
        $fields = [['key' => 'about_you', 'label' => 'About You — Name, current role, and primary responsibilities', 'type' => 'textarea', 'required' => true, 'position' => 10]];
        foreach (config('training.week_four_interview_questions', []) as $number => $question) {
            $fields[] = ['key' => $question['key'], 'label' => 'Question '.$number.' — '.$question['title'], 'type' => filled($question['options']) ? 'select' : 'textarea', 'required' => true, 'options' => $question['options'], 'position' => 10 + ($number * 2), 'help' => $question['prompt']];
            if (filled($question['explanation_key'])) {
                $fields[] = ['key' => $question['explanation_key'], 'label' => 'Question '.$number.' explanation', 'type' => 'textarea', 'position' => 11 + ($number * 2)];
            }
        }

        return $fields;
    }

    /**
     * Keep the authoritative assessment fields while making level and confidence explicit.
     */
    private function configureWeekFourAssessmentFields(Activity $activity): void
    {
        $this->deprecateFields($activity, ['updated_level_confidence']);
        $this->upsertConfiguredFields($activity, [
            ['key' => 'assessment_level', 'label' => 'Assessment level', 'type' => 'select', 'required' => true, 'position' => 3, 'options' => ['Level 1', 'Level 2', 'Level 3', 'Level 4']],
            ['key' => 'assessment_confidence', 'label' => 'Assessment confidence', 'type' => 'select', 'required' => true, 'position' => 4, 'options' => ['Low', 'Medium', 'High']],
        ]);
    }

    /** @param array<int, string> $keys */
    private function deprecateFields(Activity $activity, array $keys): void
    {
        foreach ($keys as $key) {
            $requirement = ActivityRequirement::query()->where('activity_id', $activity->id)->where('requirement_type', 'submission_field')->whereJsonContains('configuration->key', $key)->first();
            if ($requirement) {
                $requirement->update(['configuration' => array_merge($requirement->configuration ?? [], ['deprecated' => true]), 'is_required' => false]);
            }
        }
    }

    /** @param array<int, array<string, mixed>> $fields */
    private function upsertConfiguredFields(Activity $activity, array $fields): void
    {
        foreach ($fields as $position => $field) {
            $field['guidance'] = $field['guidance'] ?? $this->fieldGuidance($activity->title, $field['key']);
            $field['guidance']['evidence'] = $field['guidance']['evidence'] ?? 'Use the activity evidence controls to support this entry with a real artifact, result, link, or record when applicable.';
            $existing = ActivityRequirement::query()->where('activity_id', $activity->id)->whereNull('participant_path')->where('requirement_type', 'submission_field')->whereJsonContains('configuration->key', $field['key'])->first();
            $attributes = ['title' => $field['label'], 'description' => $field['label'], 'is_required' => $field['required'] ?? false, 'configuration' => $field, 'position' => $field['position'] ?? $position + 1];
            if ($existing) {
                $existing->update($attributes);
            } else {
                ActivityRequirement::query()->create(['activity_id' => $activity->id, 'participant_path' => null, 'requirement_type' => 'submission_field'] + $attributes);
            }
        }
    }

    public function fieldGuidance(string $activityTitle, string $key): array
    {
        $guidance = [
            'Validation Checklist' => [
                'artifact_context' => ['what' => 'Name the real AI-generated QA artifact and the work situation in which you used it.', 'why' => 'The coach needs enough context to judge whether your validation addressed a real QA decision.', 'example' => 'AI-generated OTP test cases for the Elycis staging login flow.', 'avoid' => 'Do not describe a hypothetical artifact or copy the six Lab checks here.'],
                'feature_area' => ['what' => 'Identify the feature, service, or risk area covered by the artifact.', 'why' => 'A precise scope lets the coach connect your checks to the correct product behavior.', 'example' => 'User Login / OTP Verification.', 'avoid' => 'Avoid vague labels such as “the app”.'],
                'environment' => ['what' => 'Record where the artifact was validated, including environment or build details when useful.', 'why' => 'Results can differ between local, staging, and production-like systems.', 'example' => 'Elycis staging, build 2026.09.07.', 'avoid' => 'Do not claim an environment you did not use.'],
                'ai_tool' => ['what' => 'Enter the AI tool that produced this artifact.', 'why' => 'The training evaluates responsible use of the actual tool, not an assumed tool.', 'example' => 'Claude.', 'avoid' => 'Never select a tool you did not use.'],
                'artifact_type' => ['what' => 'Describe the kind of artifact you received from AI.', 'why' => 'Artifact type determines which risks and checks are relevant.', 'example' => 'AI-generated test case with expected results.', 'avoid' => 'Do not call an unvalidated suggestion a verified test.'],
                'exact_prompt' => ['what' => 'Paste the exact prompt sent to the AI, including constraints and context.', 'why' => 'The prompt explains what the AI was asked to assume and produce.', 'example' => 'Create five positive and five negative test cases for OTP verification. OTP expires after 2 minutes.', 'avoid' => 'Do not summarize or rewrite the prompt.'],
                'exact_ai_output' => ['what' => 'Paste the complete AI response that you validated.', 'why' => 'The original output is the evidence for what AI actually claimed.', 'example' => 'The complete generated test cases and expected results.', 'avoid' => 'Do not paste only the part that supports your conclusion or fabricate missing output.'],
                'issue_caught' => ['what' => 'Explain the incorrect, incomplete, or risky claim you found.', 'why' => 'Finding the defect demonstrates that you did not blindly trust AI.', 'example' => 'AI stated that OTPs remain valid for five minutes; the specification says two.', 'avoid' => 'Do not report an issue without explaining how it was verified.'],
                'action_taken' => ['what' => 'Describe what you changed, rejected, re-prompted, or escalated after finding the issue.', 'why' => 'A Level 3 tester turns a finding into a controlled QA decision.', 'example' => 'Rejected the incorrect expected result and rewrote the case for the two-minute boundary.', 'avoid' => 'Do not claim a fix or execution that did not happen.'],
                'finding_and_decision' => ['what' => 'Summarize the evidence-backed finding and your final QA decision.', 'why' => 'This is the conclusion the coach will assess against your evidence.', 'example' => 'Finding: expiry was wrong. Decision: reject the AI output and keep the corrected test case.', 'avoid' => 'Do not make the decision before checking the Lab evidence.'],
                'validation_submission_id' => ['what' => 'Select the completed QA Validation Lab record used for this activity.', 'why' => 'The Lab record is the authoritative source for Source, Scope, Spec, Cross-check, Run, and Log.', 'example' => 'Your own completed Lab Record #8.', 'avoid' => 'Do not copy the six checks into this form or select another participant’s record.'],
            ],
            'AI Output Error Detection' => [
                'ai_tool' => ['what' => 'Name the AI tool used for this separate error-detection exercise.', 'why' => 'The coach must distinguish the actual AI interaction from the verified facts.', 'example' => 'ChatGPT.', 'avoid' => 'Do not enter a tool that did not produce this output.'],
                'artifact_type' => ['what' => 'Identify the artifact or interaction in which the concrete error appeared.', 'why' => 'It establishes what kind of AI output was wrong.', 'example' => 'AI-generated API test scenarios.', 'avoid' => 'Do not reuse Activity 1’s artifact without doing a separate exercise.'],
                'prompt' => ['what' => 'Paste the exact prompt used for this error case.', 'why' => 'The prompt shows the constraints that may have led AI to the wrong answer.', 'example' => 'Generate boundary tests for a two-minute OTP expiry rule.', 'avoid' => 'Do not write “asked AI for tests” instead of the actual prompt.'],
                'exact_ai_output' => ['what' => 'Paste the complete AI output containing the error.', 'why' => 'The coach needs to compare the claim with what was actually true.', 'example' => 'The generated case asserting a five-minute expiry.', 'avoid' => 'Do not edit the output to make the mistake look larger or smaller.'],
                'actual_truth' => ['what' => 'State the verified behavior or requirement and how you know it is true.', 'why' => 'AI is not the source of truth; QA evidence is.', 'example' => 'The OTP expires after 120 seconds according to the requirement and observed response.', 'avoid' => 'Do not use another unverified AI answer as proof.'],
                'how_caught' => ['what' => 'Explain the check, test, comparison, or observation that exposed the error.', 'why' => 'This demonstrates your detection method and QA reasoning.', 'example' => 'Compared the output with the specification, then executed at 119 and 121 seconds.', 'avoid' => 'Do not say only “I reviewed it” without the actual check.'],
                'what_i_did' => ['what' => 'Record the corrective action taken after confirming the error.', 'why' => 'The response to an AI error is part of responsible AI-assisted QA.', 'example' => 'Corrected the expected result and added the missing expiry boundary case.', 'avoid' => 'Do not claim a ticket, fix, or rerun that you did not perform.'],
                'final_result' => ['what' => 'Give the final state of the artifact and the QA conclusion.', 'why' => 'It shows whether the corrected output is safe to use.', 'example' => 'Corrected test passes at the boundary and is ready for coach review.', 'avoid' => 'Do not label it accepted while evidence is still missing.'],
                'thread_drive_link' => ['what' => 'Add the URL to the AI thread, Drive evidence, or other supporting record when available.', 'why' => 'A reviewer can trace the interaction and supporting material.', 'example' => 'https://.../ai-error-review.', 'avoid' => 'Do not submit a private or unrelated link.'],
                'detection_and_action' => ['what' => 'Summarize how you detected the error and what you did afterward.', 'why' => 'This provides a concise audit trail across the separate detail fields.', 'example' => 'Specification comparison exposed the wrong expiry; I corrected and reran the boundary test.', 'avoid' => 'Do not replace the detailed evidence with a generic statement.'],
            ],
            'Pre-acceptance Check' => [
                'ai_tool' => ['what' => 'Name the AI tool used to produce the higher-stakes artifact.', 'why' => 'The reviewer must know which AI interaction is being accepted or rejected.', 'example' => 'Claude.', 'avoid' => 'Do not claim a different tool or hide the tool used.'],
                'artifact_type' => ['what' => 'Describe the AI-generated test, fix, script, or QA change being considered.', 'why' => 'Risk depends on what the artifact could affect.', 'example' => 'AI-generated regression test for payment authorization.', 'avoid' => 'Do not describe an unrelated low-risk example.'],
                'prompt' => ['what' => 'Paste the exact prompt that produced the proposed change.', 'why' => 'The prompt reveals assumptions and constraints before acceptance.', 'example' => 'Create a regression test for failed card authorization using the existing Cypress helpers.', 'avoid' => 'Do not summarize the request.'],
                'ai_output' => ['what' => 'Paste the complete AI response or generated artifact under review.', 'why' => 'The acceptance decision must be based on the actual output.', 'example' => 'The full test code, steps, and expected results returned by AI.', 'avoid' => 'Do not submit a shortened or regenerated response.'],
                'pre_acceptance_question' => ['what' => 'Write the specific question you must answer before accepting the artifact.', 'why' => 'A clear question focuses validation on risk, correctness, and impact.', 'example' => 'Does this test use the real authorization fixture and cover the declined-card boundary?', 'avoid' => 'Avoid a vague question such as “Is this okay?”.'],
                'validation_performed' => ['what' => 'Describe the inspections, executions, comparisons, and evidence used to answer the question.', 'why' => 'AI output becomes acceptable only after human validation.', 'example' => 'Reviewed changed selectors, ran the test with approved fixtures, and compared the requirement.', 'avoid' => 'Do not list checks you did not perform.'],
                'decision' => ['what' => 'Record Accept, revise, or reject and the evidence-based reason.', 'why' => 'The tester owns the final decision when AI assists the work.', 'example' => 'Reject: the generated test bypasses the required authorization fixture.', 'avoid' => 'Do not accept solely because the output looks plausible.'],
                'higher_stakes_change' => ['what' => 'Describe the change or action that could affect shared QA assets, CI, or important behavior.', 'why' => 'The reviewer needs to understand the consequence of acceptance.', 'example' => 'A new CI regression test and fixture update for payment failures.', 'avoid' => 'Do not minimize the scope.'],
                'affected_scope' => ['what' => 'List the systems, tests, environments, files, or users touched by the change.', 'why' => 'Scope determines the validation depth and rollback risk.', 'example' => 'Cypress checkout suite, staging fixtures, and the payment authorization endpoint.', 'avoid' => 'Do not omit indirect dependencies.'],
                'validation_outcome' => ['what' => 'State what validation showed and whether the result supports your decision.', 'why' => 'It connects the performed checks to the acceptance outcome.', 'example' => 'Test passed with valid fixtures; the selector was corrected before acceptance.', 'avoid' => 'Do not report a green result without evidence.'],
                'final_notes' => ['what' => 'Capture remaining risks, follow-up actions, and the final context for the reviewer.', 'why' => 'Good notes preserve the reasoning after the decision.', 'example' => 'Accepted after rerun; add mobile coverage in the next iteration.', 'avoid' => 'Do not leave unresolved risks hidden.'],
            ],
            'Test-Failure Root-Cause Analysis' => [
                'feature_area' => ['what' => 'Name the product feature or QA area where the failure occurred.', 'why' => 'A precise scope lets the coach connect the failure to its real risk.', 'example' => 'User login and OTP verification.', 'avoid' => 'Do not use a vague label such as “the test suite”.'],
                'environment' => ['what' => 'Record the environment, build, browser, or runner where you observed the failure.', 'why' => 'Failure behavior can depend on environment and execution context.', 'example' => 'Staging, Chrome 140, CI runner build 482.', 'avoid' => 'Do not claim an environment you did not use.'],
                'test_scenario' => ['what' => 'Describe the test, user journey, or scenario that failed.', 'why' => 'The scenario identifies what behavior must be investigated.', 'example' => 'Verify that a valid OTP is accepted after login.', 'avoid' => 'Do not provide only a test ID without explaining its purpose.'],
                'failure_description' => ['what' => 'Describe the failure symptoms and when they occur.', 'why' => 'A clear symptom separates observation from root-cause theory.', 'example' => 'The test reports “invalid OTP” even though the approved OTP is entered.', 'avoid' => 'Do not state the root cause as if it were already proven.'],
                'expected_result' => ['what' => 'State the behavior the requirement or test expects.', 'why' => 'Root-cause analysis compares the observed result with a trusted expectation.', 'example' => 'The current approved OTP is accepted within the two-minute window.', 'avoid' => 'Do not use an AI assumption as the expected result.'],
                'actual_result' => ['what' => 'Record what the application, test runner, or system actually did.', 'why' => 'The actual result is the starting evidence for investigation.', 'example' => 'The request returned 401 and the browser redirected to login.', 'avoid' => 'Do not rewrite the result to match the expectation.'],
                'ai_tool' => ['what' => 'Name the AI tool used to assist this analysis.', 'why' => 'The coach must distinguish AI hypotheses from your verified investigation.', 'example' => 'Claude.', 'avoid' => 'Do not claim an AI tool you did not use.'],
                'artifact_type' => ['what' => 'Identify the AI artifact produced for analysis.', 'why' => 'It tells the coach whether AI analyzed logs, code, a failure report, or another source.', 'example' => 'AI-assisted failure hypothesis analysis.', 'avoid' => 'Do not call an unverified hypothesis a root-cause report.'],
                'exact_prompt' => ['what' => 'Paste the complete prompt sent to AI, including the failure context and constraints.', 'why' => 'The prompt shows what AI was allowed to assume.', 'example' => 'Review these five OTP failures and list hypotheses, separating evidence from speculation.', 'avoid' => 'Do not summarize the prompt.'],
                'exact_ai_output' => ['what' => 'Paste the complete AI response, including hypotheses you did not accept.', 'why' => 'This proves how AI assisted and where your judgment differed.', 'example' => 'The full list of suggested causes and recommended checks.', 'avoid' => 'Do not paste only the suggestion that matched your final answer.'],
                'failed_runs' => ['what' => 'Document five real failed runs, their symptoms, and the evidence you inspected.', 'why' => 'Repeated failures require evidence-based analysis rather than an AI guess.', 'example' => 'Run IDs, error messages, timestamps, affected test, and log excerpts for five failures.', 'avoid' => 'Do not invent runs or paste only AI-generated explanations.'],
                'diagnostic_steps' => ['what' => 'List the inspections and executions you performed to narrow the cause.', 'why' => 'The steps show how you moved from symptom to proof.', 'example' => 'Checked credentials, inspected the network response, reviewed the fixture, and reran manually.', 'avoid' => 'Do not list checks you did not perform.'],
                'evidence_reviewed' => ['what' => 'Describe the logs, reports, screenshots, network data, code, or fixtures reviewed.', 'why' => 'A Level 3 conclusion must be traceable to evidence.', 'example' => 'CI report, browser console, 401 response, and test credential fixture.', 'avoid' => 'Do not treat an unverified AI response as evidence.'],
                'ai_suggested_root_causes' => ['what' => 'Record the possible causes AI suggested and label them as hypotheses.', 'why' => 'AI assists analysis; the tester owns the root cause.', 'example' => 'AI suggested a selector issue, expired session, or stale credentials.', 'avoid' => 'Do not present every AI suggestion as a confirmed cause.'],
                'actual_root_cause' => ['what' => 'State the cause you proved was responsible for the failure.', 'why' => 'This is the central outcome of the RCA and must be evidence-backed.', 'example' => 'The automation used stale test credentials; updating the fixture made the test pass.', 'avoid' => 'Do not claim a root cause without a confirming check or controlled rerun.'],
                'shared_cause' => ['what' => 'State the common root cause, or explicitly explain why no shared cause was found.', 'why' => 'The conclusion must distinguish correlation from a proven cause.', 'example' => 'All five failures share an expired fixture token; no shared cause beyond that was found.', 'avoid' => 'Do not call the first plausible symptom the root cause.'],
                'root_cause_validation' => ['what' => 'Explain the controlled check, change, or rerun that confirmed the root cause.', 'why' => 'Validation separates a proven cause from a plausible theory.', 'example' => 'Replaced stale credentials and reran without changing selectors; the test passed.', 'avoid' => 'Do not claim validation based only on correlation.'],
                'severity' => ['what' => 'Assign a severity supported by user, business, or test risk.', 'why' => 'Severity helps the team prioritize response to the failure.', 'example' => 'High: login verification is blocked for every automation run.', 'avoid' => 'Do not choose severity just because the failure is inconvenient.'],
                'blast_radius' => ['what' => 'Describe how broadly the failure affects tests, users, environments, or releases.', 'why' => 'Blast radius makes impact concrete for triage.', 'example' => 'All CI login smoke tests fail; manual staging login is unaffected.', 'avoid' => 'Do not claim production impact without evidence.'],
                'affected_areas' => ['what' => 'List workflows, services, tests, roles, or environments touched by the cause.', 'why' => 'Affected areas guide regression and follow-up testing.', 'example' => 'Login fixture, OTP helper, CI smoke suite, and staging account.', 'avoid' => 'Do not omit dependencies you discovered.'],
                'action_taken' => ['what' => 'Record what you actually corrected, reported, isolated, or escalated.', 'why' => 'The team needs a traceable response to the RCA.', 'example' => 'Updated the approved credential fixture and opened a follow-up for rotation.', 'avoid' => 'Do not claim a code fix or ticket that does not exist.'],
                'impact_next_step' => ['what' => 'Describe severity, blast radius, affected areas, and the next fix or diagnostic step.', 'why' => 'Root-cause analysis must guide safe prioritization.', 'example' => 'High impact on checkout smoke tests; rotate the fixture and rerun the affected suite.', 'avoid' => 'Do not assign severity without evidence.'],
                'final_result' => ['what' => 'State the outcome after investigation or the current investigation state.', 'why' => 'The result tells the coach whether the failure is resolved, confirmed, or still open.', 'example' => 'Test passed after correcting test data; no selector change was needed.', 'avoid' => 'Do not call an unresolved investigation complete.'],
                'qa_conclusion' => ['what' => 'Give your professional recommendation and next owner/action.', 'why' => 'Level 3 QA converts evidence into a decision the team can act on.', 'example' => 'Accept the fixture correction, monitor the next five CI runs, and keep the defect closed.', 'avoid' => 'Do not let AI make the final recommendation for you.'],
            ],
            'Script from Description' => [
                'feature_requirement_description' => ['what' => 'Describe the real feature and requirement you were asked to automate.', 'why' => 'The source description anchors the generated test in real product behavior.', 'example' => 'Verify declined card authorization shows an actionable error and does not create an order.', 'avoid' => 'Do not use a fictional story or vague feature name.'],
                'requirement_mapping' => ['what' => 'Map the real requirement through Jira/story, test intent, framework, and execution target.', 'why' => 'Traceability prevents AI from generating a technically correct but irrelevant test.', 'example' => 'PAY-214 → failed-card requirement → Cypress checkout test → staging run.', 'avoid' => 'Do not use a fictional requirement.'],
                'acceptance_criteria' => ['what' => 'List the expected behavior and acceptance conditions the test must prove.', 'why' => 'Expected behavior is the oracle used to validate the generated script.', 'example' => 'No order is created; the error is visible; retry remains available.', 'avoid' => 'Do not copy assumptions that are not in the requirement.'],
                'ai_tool' => ['what' => 'Name the AI tool used to generate the script or test cases.', 'why' => 'The coach needs to trace the origin of the generated artifact.', 'example' => 'ChatGPT.', 'avoid' => 'Do not name a tool that did not produce this output.'],
                'artifact_type' => ['what' => 'Identify the generated artifact type and framework.', 'why' => 'Framework and artifact type determine which conventions must be checked.', 'example' => 'Cypress end-to-end test with existing checkout helpers.', 'avoid' => 'Do not call prose steps a working script.'],
                'exact_prompt' => ['what' => 'Paste the exact prompt used to turn the requirement into a test.', 'why' => 'The prompt exposes constraints, assumptions, and requested framework behavior.', 'example' => 'Create a Cypress test for PAY-214 using existing login and checkout helpers.', 'avoid' => 'Do not summarize the prompt.'],
                'exact_ai_output' => ['what' => 'Paste the complete AI response before your corrections.', 'why' => 'The original output lets the coach see what required QA review.', 'example' => 'Full generated code, steps, selectors, fixtures, and assertions.', 'avoid' => 'Do not overwrite the original with the corrected script.'],
                'generated_test' => ['what' => 'Paste the AI-assisted test or steps and explain the framework conventions you verified.', 'why' => 'The artifact must fit the real test suite and be maintainable.', 'example' => 'Cypress test using the existing login helper, fixtures, selectors, and assertions.', 'avoid' => 'Do not accept invented helpers or selectors without checking them.'],
                'ai_assumptions' => ['what' => 'List assumptions AI made about credentials, selectors, APIs, messages, or workflows.', 'why' => 'Unverified assumptions are common causes of brittle or misleading tests.', 'example' => 'AI assumed qa@example.com exists and that the error text is “Payment failed”.', 'avoid' => 'Do not silently accept assumptions as facts.'],
                'validation_performed' => ['what' => 'Explain how you checked the script against the real application and framework.', 'why' => 'AI assists generation; the tester validates the generated script.', 'example' => 'Inspected the DOM, ran the script, checked the API response, and compared the requirement.', 'avoid' => 'Do not claim execution when you only reviewed the code.'],
                'issues_corrections' => ['what' => 'Document each meaningful defect and the correction you made or rejected.', 'why' => 'The coach needs to see your independent QA judgment.', 'example' => 'Replaced invented credentials and corrected the selector for the current OTP field.', 'avoid' => 'Do not hide failures or list cosmetic edits as validation.'],
                'corrected_script' => ['what' => 'Provide the final script or test artifact after your QA corrections.', 'why' => 'This is the version the team could review or execute.', 'example' => 'Final Cypress test using the approved fixture and stable data-testid selector.', 'avoid' => 'Do not paste the uncorrected AI version.'],
                'execution_result' => ['what' => 'Record whether the corrected script passed, failed, partially passed, or was blocked.', 'why' => 'Execution status is essential evidence of practical validity.', 'example' => 'Passed.', 'avoid' => 'Do not mark passed if the test was not executed.'],
                'test_results_observations' => ['what' => 'Describe what happened during execution, including failures, logs, and observations.', 'why' => 'Results show whether the script proves the intended behavior.', 'example' => 'The first run exposed a stale fixture; after correction, the decline message and no-order assertion passed.', 'avoid' => 'Do not report only a status without observations.'],
                'final_qa_decision' => ['what' => 'Decide whether to accept, accept after corrections, reject, or investigate further.', 'why' => 'The tester owns the final quality decision.', 'example' => 'Accepted after corrections; the final script matches PAY-214 and passed in staging.', 'avoid' => 'Do not accept solely because AI produced plausible code.'],
                'repository_thread_link' => ['what' => 'Provide an authorized repository, AI thread, report, or Drive link when applicable.', 'why' => 'A reviewer can trace the final artifact and supporting work.', 'example' => 'https://github.com/example/qa-tests/pull/214.', 'avoid' => 'Do not submit private secrets or an unrelated link.'],
                'execution_investigation' => ['what' => 'Record how you executed the test, what happened, and what you fixed or rejected.', 'why' => 'Execution proves whether the generated test works in the real environment.', 'example' => 'The first run failed on a stale selector; I corrected it and reran successfully.', 'avoid' => 'Do not claim execution from a code review alone.'],
            ],
            'Regression Prioritization and Coverage Gaps' => [
                'feature_change' => ['what' => 'Describe the feature, PR, or release change being tested.', 'why' => 'Regression scope must begin with the actual change.', 'example' => 'OTP throttling changed to lock the account after three failures.', 'avoid' => 'Do not prioritize from a title without reviewing the change.'],
                'affected_areas' => ['what' => 'List product areas, services, roles, and integrations that may be affected.', 'why' => 'Affected areas define the candidate regression scope.', 'example' => 'Login, account lockout, recovery, admin support, and notification service.', 'avoid' => 'Do not ignore indirect dependencies.'],
                'risk_business_impact' => ['what' => 'Explain the user, security, revenue, or release impact if the change is wrong.', 'why' => 'Risk is the basis for prioritization.', 'example' => 'A lockout defect could block valid users and create support volume.', 'avoid' => 'Do not assign risk without a business or technical reason.'],
                'ai_tool' => ['what' => 'Name the AI tool used to analyze regression risk.', 'why' => 'The coach must distinguish AI recommendations from your final decision.', 'example' => 'Claude.', 'avoid' => 'Do not claim a tool you did not use.'],
                'artifact_type' => ['what' => 'Identify the AI artifact, such as a regression list or risk analysis.', 'why' => 'Artifact type gives context to the recommendation being validated.', 'example' => 'AI-generated regression prioritization matrix.', 'avoid' => 'Do not call a draft recommendation a validated plan.'],
                'exact_prompt' => ['what' => 'Paste the exact prompt used to ask AI for regression analysis.', 'why' => 'The prompt reveals the inputs and constraints behind the prioritization.', 'example' => 'Review these changed files and requirements and rank regression tests by user and security risk.', 'avoid' => 'Do not summarize the prompt.'],
                'exact_ai_output' => ['what' => 'Paste the complete AI regression analysis and proposed priorities.', 'why' => 'The coach needs to compare the recommendation with your adjustments.', 'example' => 'Full table of prioritized tests, rationale, and identified risks.', 'avoid' => 'Do not paste only the items you agreed with.'],
                'ai_generated_prioritization' => ['what' => 'Document the priorities AI recommended before your independent adjustment.', 'why' => 'This makes the AI contribution and your judgment traceable.', 'example' => 'AI ranked login lockout P0, profile copy P2, and email formatting P2.', 'avoid' => 'Do not present AI rankings as final QA priorities.'],
                'ai_risk_reasoning' => ['what' => 'Explain the reasoning AI gave for its ranking and which parts need checking.', 'why' => 'Understanding the recommendation prevents copy-and-paste prioritization.', 'example' => 'AI emphasized authentication because changed code affects session state and lockout.', 'avoid' => 'Do not accept reasoning that is unsupported by the change.'],
                'validation_cross_check' => ['what' => 'Describe the independent review, code inspection, test review, and execution used to validate priorities.', 'why' => 'The tester owns the final risk and coverage decision.', 'example' => 'Reviewed changed service code, existing lockout tests, role boundaries, and historical defects.', 'avoid' => 'Do not validate only by rereading the AI output.'],
                'qa_adjusted_priority' => ['what' => 'Provide the final QA-owned P0, P1, and P2 regression priorities.', 'why' => 'This is the executable scope the team will use.', 'example' => 'P0: lockout and valid-login recovery; P1: notifications; P2: copy and analytics.', 'avoid' => 'Do not leave priorities at AI’s unverified ranking.'],
                'priority_change_reason' => ['what' => 'Explain every important disagreement between your priority and AI’s recommendation.', 'why' => 'The reasoning demonstrates human risk ownership.', 'example' => 'AI grouped all roles together; I added separate participant and coach authorization tests.', 'avoid' => 'Do not omit changes without explaining the evidence.'],
                'pr_and_requirements' => ['what' => 'Identify the real PR/change and requirements you reviewed, including changed areas.', 'why' => 'Regression priority must be tied to actual change and product risk.', 'example' => 'PR-482 changed OTP throttling and affected login, recovery, and admin flows.', 'avoid' => 'Do not prioritize from the title alone.'],
                'regression_priorities' => ['what' => 'List validated high/medium/low regression priorities and explain flaky-test findings.', 'why' => 'AI suggestions need human risk assessment before the suite is selected.', 'example' => 'Run login and lockout first; defer low-risk copy checks; quarantine a flaky network test.', 'avoid' => 'Do not accept AI ranking without checking requirements and changed code.'],
                'coverage_gaps' => ['what' => 'Document missing scenarios, boundary cases, and the evidence that proves each gap.', 'why' => 'Coverage intelligence is valuable only when gaps are actionable and evidenced.', 'example' => 'No test covers three failed OTP attempts followed by a valid OTP.', 'avoid' => 'Do not list a gap that the existing suite already covers.'],
                'high_risk_missing_tests' => ['what' => 'List the tests that must be added before release, prioritizing the highest risks.', 'why' => 'A gap becomes actionable when the team knows what to add.', 'example' => 'Add disabled-user direct URL, cross-role authorization, and session-expiry tests.', 'avoid' => 'Do not list vague “more testing” without scenarios.'],
                'final_regression_plan' => ['what' => 'Provide the final test matrix, environments, roles, and execution order.', 'why' => 'The plan turns analysis into a repeatable release activity.', 'example' => 'Run P0 auth tests in staging first, then P1 recovery and notification tests.', 'avoid' => 'Do not omit environment or ownership for high-risk tests.'],
                'final_recommendation' => ['what' => 'State whether the change is ready, needs broader regression, or requires more validation.', 'why' => 'The recommendation is the final QA decision.', 'example' => 'Not ready for acceptance until P0 lockout and role-boundary coverage passes.', 'avoid' => 'Do not let an AI recommendation replace your conclusion.'],
                'final_notes' => ['what' => 'Capture remaining risks, assumptions, and follow-up ownership.', 'why' => 'Notes preserve the reasoning behind the final scope.', 'example' => 'Monitor the first production release and add the missing recovery test to the suite.', 'avoid' => 'Do not hide unresolved risk in a generic note.'],
            ],
            'Build a Reusable Asset' => [
                'asset_name_purpose' => ['what' => 'Name the asset and explain its audience, purpose, and when to use it.', 'why' => 'A reusable asset needs a clear owner and use case.', 'example' => 'OTP Boundary Test Prompt — for generating expiry and retry scenarios.', 'avoid' => 'Do not submit an unlabeled collection of notes.'],
                'asset_inputs_instructions' => ['what' => 'Give the required inputs, exact instructions/prompt/checklist, and expected output.', 'why' => 'Another tester must be able to use the asset consistently.', 'example' => 'Inputs: expiry rule and endpoint; prompt; expected table of positive, negative, and boundary tests.', 'avoid' => 'Do not leave hidden assumptions in your own head.'],
                'asset_validation_reuse' => ['what' => 'Show validation evidence from a second different real task and explain what you improved.', 'why' => 'Reuse must be demonstrated, not merely claimed.', 'example' => 'Used the prompt for password reset, corrected an omitted lockout case, and recorded the result.', 'avoid' => 'Do not reuse the original task as the second proof.'],
                'team_library_location' => ['what' => 'Provide the authorized team-library or repository location of the asset.', 'why' => 'The team must be able to find and reuse the result.', 'example' => 'https://drive.google.com/.../otp-boundary-prompt.md.', 'avoid' => 'Do not submit a private, broken, or unrelated link.'],
            ],
            'Mock Level 3 Interview' => [
                'interview_evidence' => ['what' => 'Record complete Interview Guide answers grounded in your real Weeks 2–4 work.', 'why' => 'Level 3 reasoning must be demonstrated with concrete decisions and evidence.', 'example' => 'Explain how you detected a wrong OTP expectation and proved the actual behavior.', 'avoid' => 'Do not give generic textbook answers or invent examples.'],
                'partner_feedback' => ['what' => 'Capture feedback from your partner on at least two answers and your response to it.', 'why' => 'Feedback shows reflection and ability to improve communication.', 'example' => 'Partner noted that my evidence was unclear; I added the exact boundary result.', 'avoid' => 'Do not submit praise without actionable feedback.'],
                'coach_feedback' => ['what' => 'Record the QA Coach feedback on at least one answer and the resulting action.', 'why' => 'Coach assessment is part of demonstrating readiness for Level 3 work.', 'example' => 'Coach asked me to separate AI claims from verified facts; I revised the explanation.', 'avoid' => 'Do not fabricate coach feedback.'],
                'daily_log_reference' => ['what' => 'Reference the daily-log entries that support your interview examples.', 'why' => 'Logs make your examples traceable to actual training work.', 'example' => 'Week 3 logs from September 10–12, entries on RCA and regression priority.', 'avoid' => 'Do not reference logs that do not contain the claimed work.'],
            ],
            'Formal Reassessment' => [
                'strict_interview_transcript' => ['what' => 'Provide the complete Strict-mode Leveling interview transcript or authorized record.', 'why' => 'The formal reassessment is the evidence for the final capability decision.', 'example' => 'Full transcript with questions, answers, follow-ups, and assessor notes.', 'avoid' => 'Do not submit a summary that omits difficult questions.'],
                'assessment_tracker_result' => ['what' => 'Record the result appended to the assessment tracker, including date and level outcome.', 'why' => 'The tracker is the authoritative record of the reassessment result.', 'example' => '2026-09-10 — Level 3 — confidence Medium — validation score 4.', 'avoid' => 'Do not enter an expected result before the assessment occurs.'],
                'updated_level_confidence' => ['what' => 'State the updated level and confidence, with the reasoning or evidence supporting it.', 'why' => 'Confidence must reflect demonstrated work, not optimism.', 'example' => 'Level 3, Medium confidence; supported by validated RCA, reusable asset, and coach feedback.', 'avoid' => 'Do not claim Level 3 without the formal result.'],
            ],
        ];

        return $guidance[$activityTitle][$key] ?? ['what' => 'Enter the requested information from your actual QA work.', 'why' => 'This gives your coach traceable context for reviewing your submission.', 'example' => 'A concise, evidence-backed entry from the activity.', 'avoid' => 'Do not invent work, evidence, or results.'];
    }

    private function weeklyVideoCriterion(TrainingGate $gate, int $position, string $description): void
    {
        $criterion = $gate->criteria()->whereIn('criterion_type', ['loom_link', 'weekly_loom', 'weekly_video'])->first();
        if ($criterion) {
            $criterion->update(['criterion_type' => 'weekly_video', 'title' => 'Required Weekly Video Recording', 'description' => $description, 'is_required' => true, 'position' => $position, 'configuration' => []]);

            return;
        }
        GateCriterion::query()->updateOrCreate(
            ['training_gate_id' => $gate->id, 'criterion_type' => 'weekly_video'],
            ['position' => $position, 'title' => 'Required Weekly Video Recording', 'description' => $description, 'is_required' => true, 'configuration' => []],
        );
    }

    private function fieldsAndEvidence(Activity $activity, array $fields, string $evidenceTitle, string $evidenceType = 'text'): void
    {
        $artifactContextKeys = [
            'Validation Checklist' => ['feature_area', 'environment', 'ai_tool', 'artifact_type', 'exact_prompt', 'exact_ai_output'],
            'AI Output Error Detection' => ['ai_tool', 'artifact_type', 'prompt', 'exact_ai_output'],
            'Pre-acceptance Check' => ['ai_tool', 'artifact_type', 'prompt', 'ai_output'],
            'Test-Failure Root-Cause Analysis' => ['feature_area', 'environment', 'ai_tool', 'artifact_type', 'exact_prompt', 'exact_ai_output'],
            'Script from Description' => ['ai_tool', 'artifact_type', 'exact_prompt', 'exact_ai_output'],
            'Regression Prioritization and Coverage Gaps' => ['ai_tool', 'artifact_type', 'exact_prompt', 'exact_ai_output'],
        ];
        $contextKeys = $artifactContextKeys[$activity->title] ?? [];
        usort($fields, function (array $left, array $right) use ($contextKeys): int {
            return (int) ! in_array($left['key'], $contextKeys, true) <=> (int) ! in_array($right['key'], $contextKeys, true);
        });
        foreach ($fields as $index => $field) {
            if (in_array($field['key'], $artifactContextKeys[$activity->title] ?? [], true)) {
                $field['section'] = 'artifact_context';
            }
            $field['guidance'] = $field['guidance'] ?? $this->fieldGuidance($activity->title, $field['key']);
            $field['guidance']['evidence'] = $field['guidance']['evidence'] ?? 'Use the activity evidence controls to support this entry with a real artifact, result, link, or record when applicable. Never invent evidence.';
            $existing = ActivityRequirement::query()->where('activity_id', $activity->id)->whereNull('participant_path')->where('requirement_type', 'submission_field')->whereJsonContains('configuration->key', $field['key'])->first();
            $attributes = ['title' => $field['label'], 'description' => $field['label'], 'is_required' => $field['required'] ?? true, 'configuration' => $field, 'position' => $index + 1];
            if ($existing) {
                $existing->update($attributes);
            } else {
                ActivityRequirement::query()->create(['activity_id' => $activity->id, 'participant_path' => null, 'requirement_type' => 'submission_field'] + $attributes);
            }
        }
        $requirement = ActivityRequirement::query()->updateOrCreate(['activity_id' => $activity->id, 'participant_path' => null, 'requirement_type' => 'deliverable', 'position' => 10], ['title' => 'Documented activity evidence', 'description' => $evidenceTitle, 'is_required' => true]);
        EvidenceRequirement::query()->updateOrCreate(['activity_requirement_id' => $requirement->id, 'position' => 1], ['title' => $evidenceTitle, 'description' => 'Upload or attach the required deliverable proving this activity.', 'evidence_type' => $evidenceType, 'is_required' => true, 'minimum_count' => 1, 'maximum_count' => 1]);
        EvidenceRequirement::query()->updateOrCreate(['activity_requirement_id' => $requirement->id, 'position' => 2], ['title' => 'Optional supporting link or file', 'description' => 'Attach a screenshot, report, log, repository, or other source where useful.', 'evidence_type' => 'link', 'is_required' => false, 'minimum_count' => 0, 'maximum_count' => 5]);
    }

    private function gate(TrainingWeek $week, string $title, string $description): TrainingGate
    {
        return TrainingGate::query()->updateOrCreate(['training_week_id' => $week->id, 'position' => 1], compact('title', 'description') + ['is_active' => true]);
    }
}
