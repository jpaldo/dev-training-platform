<?php

use Carbon\CarbonImmutable;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Moves each program's single training session onto the program: its participants, mentors and resources are
     * copied, its mentor assignments get the program, and the program's weeks 1..`weeks_count` are dated from the
     * session start (week N runs `starts_on + 7×(N−1)` days to 6 days later, inactive weeks included). A program with
     * two or more sessions aborts the migration before anything is written. The session tables are left untouched.
     */
    public function up(): void
    {
        $this->ensureEveryProgramHasAtMostOneSession();

        DB::transaction(function (): void {
            DB::table('training_sessions')->orderBy('id')->get()->each(function (stdClass $session): void {
                $this->moveParticipants($session);
                $this->moveMentors($session);
                $this->moveResources($session);

                DB::table('mentor_assignments')
                    ->where('training_session_id', $session->id)
                    ->update(['program_id' => $session->program_id]);

                $this->dateWeeks($session);
            });
        });
    }

    /**
     * Clears what up() wrote: every program participant, mentor and resource, every assignment's program and every
     * week's dates. The data is not moved back; the session tables were never changed, so nothing needs restoring.
     */
    public function down(): void
    {
        DB::transaction(function (): void {
            DB::table('program_participants')->delete();
            DB::table('program_mentors')->delete();
            DB::table('program_resources')->delete();
            DB::table('mentor_assignments')->update(['program_id' => null]);
            DB::table('training_weeks')->update(['starts_on' => null, 'ends_on' => null]);
        });
    }

    /**
     * @throws RuntimeException when a program has two or more training sessions
     */
    private function ensureEveryProgramHasAtMostOneSession(): void
    {
        $sessionCounts = DB::table('training_sessions')
            ->orderBy('program_id')
            ->pluck('program_id')
            ->countBy()
            ->filter(fn (int $count): bool => $count > 1);

        $programId = $sessionCounts->keys()->first();

        if ($programId !== null) {
            throw new RuntimeException("Program {$programId} has {$sessionCounts[$programId]} training sessions; merge them by hand before running this migration.");
        }
    }

    private function moveParticipants(stdClass $session): void
    {
        DB::table('program_participants')->insert(
            DB::table('training_session_participants')
                ->where('training_session_id', $session->id)
                ->orderBy('id')
                ->get()
                ->map(fn (stdClass $participant): array => [
                    'program_id' => $session->program_id,
                    'user_id' => $participant->user_id,
                    'joined_at' => $participant->joined_at,
                    'added_by' => $participant->added_by,
                    'repository_url' => $participant->repository_url,
                    'created_at' => $participant->created_at,
                    'updated_at' => $participant->updated_at,
                ])
                ->all(),
        );
    }

    private function moveMentors(stdClass $session): void
    {
        DB::table('program_mentors')->insert(
            DB::table('training_session_mentors')
                ->where('training_session_id', $session->id)
                ->orderBy('id')
                ->get()
                ->map(fn (stdClass $mentor): array => [
                    'program_id' => $session->program_id,
                    'user_id' => $mentor->user_id,
                    'created_at' => $mentor->created_at,
                    'updated_at' => $mentor->updated_at,
                ])
                ->all(),
        );
    }

    private function moveResources(stdClass $session): void
    {
        DB::table('program_resources')->insert(
            DB::table('training_session_resources')
                ->where('training_session_id', $session->id)
                ->orderBy('id')
                ->get()
                ->map(fn (stdClass $resource): array => [
                    'program_id' => $session->program_id,
                    'week_number' => $resource->week_number,
                    'title' => $resource->title,
                    'url' => $resource->url,
                    'added_by' => $resource->added_by,
                    'created_at' => $resource->created_at,
                    'updated_at' => $resource->updated_at,
                ])
                ->all(),
        );
    }

    /**
     * Dates the program's weeks 1..`weeks_count` one after another from the session start.
     */
    private function dateWeeks(stdClass $session): void
    {
        $sessionStartsOn = CarbonImmutable::parse($session->starts_on)->startOfDay();

        DB::table('training_weeks')
            ->where('program_id', $session->program_id)
            ->where('week_number', '<=', $session->weeks_count)
            ->orderBy('week_number')
            ->get(['id', 'week_number'])
            ->each(function (stdClass $week) use ($sessionStartsOn): void {
                $startsOn = $sessionStartsOn->addDays(7 * ($week->week_number - 1));

                DB::table('training_weeks')->where('id', $week->id)->update([
                    'starts_on' => $startsOn->toDateString(),
                    'ends_on' => $startsOn->addDays(6)->toDateString(),
                ]);
            });
    }
};
