<?php

namespace App\Http\Controllers;

use App\Http\Requests\ScheduleProgramRequest;
use App\Models\Program;
use App\Services\CurriculumScheduleService;
use Illuminate\Http\RedirectResponse;

/**
 * Admins date a curriculum's weeks from the first day of Week 1.
 */
class AdminCurriculumScheduleController extends Controller
{
    public function update(ScheduleProgramRequest $request, Program $program, CurriculumScheduleService $schedule): RedirectResponse
    {
        $startsOn = $request->date('starts_on');

        $schedule->scheduleFromStart($program, $startsOn, $request->user());

        return redirect()
            ->to(route('admin.programs.show', $program).'#schedule')
            ->with('status', "Scheduled the weeks from {$startsOn->format('j M Y')}.");
    }
}
