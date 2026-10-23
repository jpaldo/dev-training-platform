<?php

namespace App\Http\Requests;

use App\Models\SubmissionVersion;
use App\Services\ScoringService;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class StoreActivityScoreRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request: an admin or the participant's current mentor in the
     * submission's session.
     */
    public function authorize(): bool
    {
        $submission = $this->route('submissionVersion')?->loadMissing(['submission.participant', 'submission.trainingSession'])->submission;

        return $submission !== null && $this->user()?->mentorsParticipantInSession($submission->participant, $submission->trainingSession) === true;
    }

    /**
     * Points and evidence / reasoning for exactly the scoring dimensions of the version's activity, each within 0 and
     * the dimension's maximum.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $dimensions = $this->scoringDimensions();
        $rules = $dimensions === [] ? [] : ['dimensions' => ['required', 'array:'.implode(',', array_column($dimensions, 'key'))]];
        foreach ($dimensions as $dimension) {
            $rules["dimensions.{$dimension['key']}.points"] = ['required', 'integer', 'min:0', "max:{$dimension['max']}"];
            $rules["dimensions.{$dimension['key']}.evidence_reasoning"] = ['required', 'string', 'max:10000'];
        }
        $rules['overall_feedback'] = ['nullable', 'string', 'max:10000'];
        $rules['history_reason'] = ['nullable', 'string', 'max:2000'];

        return $rules;
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'dimensions.array' => 'Score only the dimensions of this activity: '.implode(', ', array_column($this->scoringDimensions(), 'key')).'.',
        ];
    }

    /**
     * Name each dimension's fields after its label.
     *
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return collect($this->scoringDimensions())
            ->flatMap(fn (array $dimension): array => [
                "dimensions.{$dimension['key']}.points" => "{$dimension['label']} points",
                "dimensions.{$dimension['key']}.evidence_reasoning" => "{$dimension['label']} evidence / reasoning",
            ])
            ->all();
    }

    /**
     * An activity without scoring dimensions cannot be scored.
     *
     * @return list<callable(Validator): void>
     */
    public function after(): array
    {
        return [function (Validator $validator): void {
            if ($this->scoringDimensions() === []) {
                $validator->errors()->add('dimensions', ScoringService::NO_DIMENSIONS_MESSAGE);
            }
        }];
    }

    /**
     * The scoring dimensions of the version's activity.
     *
     * @return list<array{key: string, label: string, max: int}>
     */
    private function scoringDimensions(): array
    {
        /** @var SubmissionVersion $version */
        $version = $this->route('submissionVersion');

        return $version->loadMissing('submission.activity')->submission->activity->scoringDimensions();
    }
}
