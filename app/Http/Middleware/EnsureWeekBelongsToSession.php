<?php

namespace App\Http\Middleware;

use App\Models\TrainingSession;
use App\Models\TrainingWeek;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureWeekBelongsToSession
{
    /**
     * Allow a `training/sessions/{trainingSession}/weeks/{trainingWeek}` request only for one of the session's weeks
     * (TrainingSession::includesWeek()): a week of another program, an inactive week or a week beyond the session's
     * length is not found.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $trainingSession = $request->route('trainingSession');
        $trainingWeek = $request->route('trainingWeek');

        abort_unless(
            $trainingSession instanceof TrainingSession
                && $trainingWeek instanceof TrainingWeek
                && $trainingSession->includesWeek($trainingWeek),
            404,
        );

        return $next($request);
    }
}
