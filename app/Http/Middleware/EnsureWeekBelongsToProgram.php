<?php

namespace App\Http\Middleware;

use App\Models\Program;
use App\Models\TrainingWeek;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureWeekBelongsToProgram
{
    /**
     * Allow a `training/programs/{program}/weeks/{trainingWeek}` request only for one of the curriculum's weeks
     * (Program::includesWeek()): a week of another curriculum or an inactive week is not found. The curriculum's
     * schedule bounds are read once here, so the status checks later in the request need no further query.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $program = $request->route('program');
        $trainingWeek = $request->route('trainingWeek');

        abort_unless(
            $program instanceof Program
                && $trainingWeek instanceof TrainingWeek
                && $program->includesWeek($trainingWeek),
            404,
        );

        $program->loadScheduleBounds();

        return $next($request);
    }
}
