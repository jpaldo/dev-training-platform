<?php

namespace App\Services;

use App\Models\TrainingSession;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class TrainingSessionService
{
    public function __construct(private AdminAuditService $audit) {}

    /**
     * The end date of a session: the last day of its last week, the start date plus weeks × 7 − 1 days (4 weeks from
     * Mon 9 Nov 2026 end on Sun 6 Dec 2026). The one place this rule lives; the form request and the factory use it.
     */
    public static function endsOn(CarbonInterface $startsOn, int $weeksCount): CarbonInterface
    {
        return $startsOn->copy()->startOfDay()->addDays($weeksCount * 7 - 1);
    }

    /**
     * Create a session of the program with its end date computed from the start date and weeks, and record
     * `training_session_created` for the admin who created it.
     *
     * @param  array{program_id: int|string, title: string, weeks_count: int|string, starts_on: string}  $details
     */
    public function create(array $details, User $creator): TrainingSession
    {
        $startsOn = Carbon::parse($details['starts_on'])->startOfDay();
        $weeksCount = (int) $details['weeks_count'];

        return DB::transaction(function () use ($details, $creator, $startsOn, $weeksCount): TrainingSession {
            $session = TrainingSession::query()->create([
                'program_id' => $details['program_id'],
                'title' => $details['title'],
                'weeks_count' => $weeksCount,
                'starts_on' => $startsOn,
                'ends_on' => self::endsOn($startsOn, $weeksCount),
                'created_by' => $creator->id,
            ]);

            $this->audit->record($creator, 'training_session_created', metadata: ['training_session_id' => $session->id]);

            return $session;
        });
    }
}
