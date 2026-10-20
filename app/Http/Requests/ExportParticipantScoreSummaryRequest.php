<?php

namespace App\Http\Requests;

use App\Models\Program;
use Illuminate\Contracts\Validation\ValidationRule;

class ExportParticipantScoreSummaryRequest extends FilterScoringReportRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * Without an active program the filters are not validated, so the controller
     * answers with its "no active program" 404 before any validation error.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        if (! Program::query()->where('is_active', true)->exists()) {
            return [];
        }

        return parent::rules();
    }
}
