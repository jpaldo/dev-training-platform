<?php

namespace App\Services;

use App\Enums\TrainingPath;
use App\Enums\UserRole;
use App\Models\AttendanceSession;
use App\Models\User;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Pagination\LengthAwarePaginator;

class CoachParticipantListPageService
{
    private const PARTICIPANTS_PER_PAGE = 25;

    public function __construct(
        private readonly CoachReportService $reports,
    ) {}

    /**
     * Everything the coach participants page renders: the filter values and the matching participant rows. The page
     * has no single main action, so every button is secondary.
     *
     * @param  array{search?: ?string, path?: ?string}  $filters
     * @return array{search: string, pathOptions: list<array{value: string, label: string, is_selected: bool}>, participants: LengthAwarePaginator<int, array{name: string, email: string, path: string, access_label: ?string, coach_names: string, drive_url: ?string, google_drive_account: ?string, attendance: ?array{is_open: bool, status: string, time: string, duration: string}, submission_count: int, gate_count: int, inspect_url: string}>}
     */
    public function indexData(User $viewer, array $filters): array
    {
        return [
            'search' => (string) ($filters['search'] ?? ''),
            'pathOptions' => collect(TrainingPath::options())
                ->map(fn (string $label, string $value): array => [
                    'value' => $value,
                    'label' => $label,
                    'is_selected' => ($filters['path'] ?? null) === $value,
                ])
                ->values()
                ->all(),
            'participants' => $this->reports->participants($filters)
                ->with(['attendanceSessions' => fn (Relation $sessions): Relation => $sessions->latest('check_in_at'), 'googleDriveConnection'])
                ->paginate(self::PARTICIPANTS_PER_PAGE)
                ->withQueryString()
                ->through(fn (User $participant): array => $this->participantRow($viewer, $participant)),
        ];
    }

    /**
     * One table row: the viewer's access to the participant (admin access, assigned to the viewer, or null when not
     * assigned), the Drive links, the counts and the open (or else latest) attendance session.
     *
     * @return array{name: string, email: string, path: string, access_label: ?string, coach_names: string, drive_url: ?string, google_drive_account: ?string, attendance: ?array{is_open: bool, status: string, time: string, duration: string}, submission_count: int, gate_count: int, inspect_url: string}
     */
    private function participantRow(User $viewer, User $participant): array
    {
        return [
            'name' => $participant->name,
            'email' => $participant->email,
            'path' => "{$participant->trainingPathLabel()} QA",
            'access_label' => match (true) {
                $viewer->hasAnyRole(UserRole::Admin) => 'Admin access',
                $participant->assignedCoaches->contains('id', $viewer->id) => 'Assigned to me',
                default => null,
            },
            'coach_names' => $participant->assignedCoaches->pluck('name')->join(', '),
            'drive_url' => $participant->drive_link ?: null,
            'google_drive_account' => $participant->googleDriveConnection
                ? ($participant->googleDriveConnection->google_email ?: 'Account connected')
                : null,
            'attendance' => $this->attendanceSummary($participant),
            'submission_count' => $participant->activity_submissions_count,
            'gate_count' => $participant->gate_evaluations_count,
            'inspect_url' => route('coach.participants.show', $participant),
        ];
    }

    /**
     * The session still checked in (with its check-in time and elapsed time), or else the latest session (with its
     * check-out time and duration), or null when the participant never checked in.
     *
     * @return ?array{is_open: bool, status: string, time: string, duration: string}
     */
    private function attendanceSummary(User $participant): ?array
    {
        $openSession = $participant->attendanceSessions->firstWhere('check_out_at', null);

        if ($openSession instanceof AttendanceSession) {
            return [
                'is_open' => true,
                'status' => 'CHECKED IN',
                'time' => $openSession->check_in_at->format('M j · g:i A'),
                'duration' => "{$openSession->durationLabel()} elapsed",
            ];
        }

        $latestSession = $participant->attendanceSessions->first();

        if (! $latestSession instanceof AttendanceSession) {
            return null;
        }

        return [
            'is_open' => false,
            'status' => 'CHECKED OUT',
            'time' => (string) $latestSession->check_out_at?->format('M j · g:i A'),
            'duration' => $latestSession->durationLabel(),
        ];
    }
}
