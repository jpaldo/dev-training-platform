<?php

namespace App\Http\Controllers;

use App\Models\ActivitySubmission;
use App\Models\GateEvaluation;
use App\Models\Program;
use App\Models\User;
use App\Models\WeekFourInterviewPair;
use App\Models\WeekFourMentorInterviewSchedule;
use App\Services\AdminSettingsStatusService;
use Illuminate\View\View;

class AdminDashboardController extends Controller
{
    public function index(AdminSettingsStatusService $settings): View
    {
        $participants = User::query()->where('role', 'participant');
        $program = Program::query()->where('is_active', true)->first();
        $week4ActivityId = $program?->weeks()->where('week_number', 4)->first()?->activities()->where('position', 2)->value('id');

        $system = [
            'participants' => (clone $participants)->count(),
            'coaches' => User::query()->where('role', 'coach')->count(),
            'admins' => User::query()->where('role', 'admin')->count(),
        ];
        $training = [
            'general' => (clone $participants)->whereHas('dailyLogs')->count(),
            'week2' => $this->participantsWithWeek($program, 2),
            'week3' => $this->participantsWithWeek($program, 3),
            'week4' => $this->participantsWithWeek($program, 4),
        ];
        $review = [
            'awaiting' => ActivitySubmission::query()->whereIn('status', ['submitted', 'resubmitted', 'under_review'])->count(),
            'needs_revision' => ActivitySubmission::query()->where('status', 'needs_revision')->count(),
            'pending_gates' => GateEvaluation::query()->whereNotIn('status', ['passed'])->count(),
        ];
        $weekFour = [
            'paired' => WeekFourInterviewPair::query()->where('status', 'active')->count() * 2,
            'waiting_pair' => max(0, $system['participants'] - (WeekFourInterviewPair::query()->where('status', 'active')->count() * 2)),
            'scheduled' => WeekFourMentorInterviewSchedule::query()->where('status', WeekFourMentorInterviewSchedule::SCHEDULED)->count(),
            'calendar_attention' => WeekFourMentorInterviewSchedule::query()->whereIn('status', [WeekFourMentorInterviewSchedule::FAILED, WeekFourMentorInterviewSchedule::PENDING])->count(),
            'activity_id' => $week4ActivityId,
        ];

        return view('admin.dashboard', ['system' => $system, 'training' => $training, 'review' => $review, 'weekFour' => $weekFour, 'integrations' => $settings->overview()]);
    }

    private function participantsWithWeek(?Program $program, int $weekNumber): int
    {
        if (! $program) {
            return 0;
        }

        $week = $program->weeks()->where('week_number', $weekNumber)->first();

        return $week ? User::query()->where('role', 'participant')->whereHas('activitySubmissions', fn ($query) => $query->whereHas('activity', fn ($activity) => $activity->where('training_week_id', $week->id)))->count() : 0;
    }
}
