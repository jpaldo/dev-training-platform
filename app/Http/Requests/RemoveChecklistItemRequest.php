<?php

namespace App\Http\Requests;

use App\Models\GateCriterion;
use App\Models\Program;
use App\Models\TrainingGate;
use App\Models\TrainingWeek;
use App\Services\ChecklistService;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\Attributes\ErrorBag;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * An admin removes an item from a week's checklist on the week page's Checklist tab. An item already removed from the
 * checklist, or one of another Gate than the week's checklist, is not found. A checklist keeps at least one item (A6),
 * so its last item cannot be removed. Errors go to the `checklist` bag and the admin returns to the Checklist tab.
 */
#[ErrorBag('checklist')]
class RemoveChecklistItemRequest extends FormRequest
{
    public function authorize(): bool
    {
        abort_unless($this->item()->isOnChecklist() && $this->item()->training_gate_id === $this->checklist()?->id, 404);

        return $this->user()?->can('update', $this->program()) === true;
    }

    /**
     * Removing takes no input.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [];
    }

    /**
     * Reject removing the checklist's last item.
     *
     * @return list<callable(Validator): void>
     */
    public function after(): array
    {
        return [function (Validator $validator): void {
            if ($this->checklist()->criteria()->onChecklist()->whereKeyNot($this->item()->id)->doesntExist()) {
                $validator->errors()->add('item', ChecklistService::LAST_ITEM_MESSAGE);
            }
        }];
    }

    /**
     * A failed removal returns to the week page's Checklist tab.
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
