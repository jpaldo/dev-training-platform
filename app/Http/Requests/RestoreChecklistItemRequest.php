<?php

namespace App\Http\Requests;

use App\Models\GateCriterion;
use App\Models\Program;
use App\Models\TrainingGate;
use App\Models\TrainingWeek;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\Attributes\ErrorBag;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * An admin puts a removed item back on a week's checklist from the "Removed items" list of the week page's Checklist
 * tab. An item of another Gate than the week's checklist is not found. A checklist has at most one session logs item
 * and one weekly video item (A5), so such an item cannot come back while another item of its type is on the checklist.
 * Errors go to the `checklist` bag and the admin returns to the Checklist tab.
 */
#[ErrorBag('checklist')]
class RestoreChecklistItemRequest extends FormRequest
{
    public function authorize(): bool
    {
        abort_unless($this->item()->training_gate_id === $this->checklist()?->id, 404);

        return $this->user()?->can('update', $this->program()) === true;
    }

    /**
     * Restoring takes no input.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [];
    }

    /**
     * Reject restoring a session logs or weekly video item while another item of its type is on the checklist.
     *
     * @return list<callable(Validator): void>
     */
    public function after(): array
    {
        return [function (Validator $validator): void {
            $type = $this->item()->criterion_type;

            if (! in_array($type, GateCriterion::ONE_PER_CHECKLIST_TYPES, true)) {
                return;
            }

            if ($this->checklist()->hasItemOfTypeOnChecklist($type, except: $this->item())) {
                $validator->errors()->add('item', UpdateChecklistItemRequest::duplicateTypeMessage($type));
            }
        }];
    }

    /**
     * A failed restore returns to the week page's Checklist tab.
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

    private function checklist(): ?TrainingGate
    {
        return $this->week()->checklist;
    }

    private function item(): GateCriterion
    {
        return $this->route('checklistItem');
    }
}
