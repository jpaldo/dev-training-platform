<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\NormalizesLineEndings;
use App\Models\GateCriterion;
use App\Models\Program;
use App\Models\TrainingGate;
use App\Models\TrainingWeek;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\Attributes\ErrorBag;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * An admin adds an item to a week's checklist (its first active Gate) from the week page's Checklist tab, with a type,
 * a title and an optional description. Its position is set by `ChecklistService`. A week without a checklist is not
 * found. A checklist has at most one session logs item and one weekly video item (A5). Errors go to the
 * `addChecklistItem` bag and the admin returns to the Checklist tab.
 */
#[ErrorBag('addChecklistItem')]
class StoreChecklistItemRequest extends FormRequest
{
    use NormalizesLineEndings;

    public function authorize(): bool
    {
        abort_if($this->checklist() === null, 404);

        return $this->user()?->can('update', $this->program()) === true;
    }

    /**
     * One of the item types (`GateCriterion::TYPE_*`), a title and an optional description, both plain text.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'type' => ['required', 'string', Rule::in(array_keys(GateCriterion::TYPE_LABELS))],
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:2000'],
        ];
    }

    /**
     * Reject a session logs or weekly video type when an item on the checklist already has it.
     *
     * @return list<callable(Validator): void>
     */
    public function after(): array
    {
        return [function (Validator $validator): void {
            $type = $this->input('type');

            if ($validator->errors()->has('type') || ! in_array($type, GateCriterion::ONE_PER_CHECKLIST_TYPES, true)) {
                return;
            }

            if ($this->checklist()->hasItemOfTypeOnChecklist($type)) {
                $validator->errors()->add('type', UpdateChecklistItemRequest::duplicateTypeMessage($type));
            }
        }];
    }

    /**
     * The validated type, title and description of the new item. A description left out is null.
     *
     * @return array{type: string, title: string, description: ?string}
     */
    public function details(): array
    {
        return [
            'type' => $this->validated('type'),
            'title' => $this->validated('title'),
            'description' => $this->validated('description'),
        ];
    }

    /**
     * The week's checklist: its first active Gate.
     */
    public function checklist(): ?TrainingGate
    {
        return $this->week()->checklist;
    }

    /**
     * Browsers send textarea line breaks as "\r\n", so the description stores "\n" instead.
     */
    protected function prepareForValidation(): void
    {
        $this->normalizeLineEndings(['description']);
    }

    /**
     * A failed add returns to the week page's Checklist tab.
     */
    protected function getRedirectUrl(): string
    {
        return route('admin.programs.weeks.show', [$this->program(), $this->week()]).'#checklist';
    }

    private function program(): Program
    {
        return $this->route('program');
    }

    private function week(): TrainingWeek
    {
        return $this->route('week');
    }
}
