<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\NormalizesLineEndings;
use App\Models\GateCriterion;
use App\Models\Program;
use App\Models\TrainingGate;
use App\Models\TrainingWeek;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator as ValidationValidator;

/**
 * An admin edits a checklist item's type, title and description on the week page's Checklist tab. An item that was
 * removed from the checklist, or that belongs to another Gate than the week's checklist, is not found. A checklist has
 * at most one session logs item and one weekly video item (A5). An item whose stored type is not offered keeps it.
 * Each item's errors go to its own bag (see errorBagFor()), so the tab shows them beside the right form, and the admin
 * returns to that tab.
 */
class UpdateChecklistItemRequest extends FormRequest
{
    use NormalizesLineEndings;

    public function authorize(): bool
    {
        abort_unless($this->item()->isOnChecklist() && $this->item()->training_gate_id === $this->checklist()?->id, 404);

        return $this->user()?->can('update', $this->program()) === true;
    }

    /**
     * One of the item types (`GateCriterion::TYPE_*`; an item whose stored type is not one of them may keep it
     * unchanged), a title and an optional description, both plain text.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'type' => ['required', 'string', Rule::in($this->allowedTypes())],
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:2000'],
        ];
    }

    /**
     * Reject a session logs or weekly video type when another item on the checklist already has it.
     *
     * @return list<callable(ValidationValidator): void>
     */
    public function after(): array
    {
        return [function (ValidationValidator $validator): void {
            $type = $this->input('type');

            if ($validator->errors()->has('type') || ! in_array($type, GateCriterion::ONE_PER_CHECKLIST_TYPES, true)) {
                return;
            }

            if ($this->checklist()->hasItemOfTypeOnChecklist($type, except: $this->item())) {
                $validator->errors()->add('type', self::duplicateTypeMessage($type));
            }
        }];
    }

    /**
     * The validated type, title and description. A description left out of the request is not included.
     *
     * @return array{type: string, title: string, description?: ?string}
     */
    public function details(): array
    {
        return $this->safe()->only(['type', 'title', 'description']);
    }

    /**
     * The error bag of a checklist item's form, e.g. "checklistItem12".
     */
    public static function errorBagFor(GateCriterion $item): string
    {
        return 'checklistItem'.$item->id;
    }

    /**
     * The error for a second item of a type a checklist has only one of, e.g. "This checklist already has a weekly
     * video item."
     */
    public static function duplicateTypeMessage(string $type): string
    {
        return 'This checklist already has a '.Str::lower(GateCriterion::TYPE_LABELS[$type]).' item.';
    }

    /**
     * Browsers send textarea line breaks as "\r\n", so the description stores "\n" instead and an untouched save of a
     * multi-line description changes nothing.
     */
    protected function prepareForValidation(): void
    {
        $this->normalizeLineEndings(['description']);
    }

    /**
     * Put the errors in the item's own bag before the request redirects back.
     */
    protected function failedValidation(Validator $validator): void
    {
        $this->errorBag = self::errorBagFor($this->item());

        parent::failedValidation($validator);
    }

    /**
     * A failed save returns to the week page's Checklist tab.
     */
    protected function getRedirectUrl(): string
    {
        return route('admin.programs.weeks.show', [$this->program(), $this->week()]).'#checklist';
    }

    /**
     * The types the item may take: the offered types (TYPE_LABELS), plus the item's own stored type when it is not
     * offered (such as the legacy `activity_completion`), which then cannot change.
     *
     * @return list<string>
     */
    private function allowedTypes(): array
    {
        $types = array_keys(GateCriterion::TYPE_LABELS);

        if ($this->item()->hasFixedType()) {
            $types[] = $this->item()->criterion_type;
        }

        return $types;
    }

    private function program(): Program
    {
        return $this->route('program');
    }

    private function week(): TrainingWeek
    {
        return $this->route('week');
    }

    private function checklist(): ?TrainingGate
    {
        return $this->week()->checklist;
    }

    private function item(): GateCriterion
    {
        return $this->route('checklistItem');
    }
}
