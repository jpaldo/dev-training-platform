<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureEmployeeIdIsSet
{
    /**
     * The routes a signed-in user without an employee ID can still reach: the prompt itself and logging out.
     *
     * @var list<string>
     */
    private const EXEMPT_ROUTES = [
        'account.employee-id.edit',
        'account.employee-id.update',
        'logout',
    ];

    /**
     * Send a signed-in user who has no employee ID (an account created before it was required) to the employee ID
     * prompt before any other page. Requests expecting JSON, such as draft autosave, get a 403 instead of a redirect.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user === null || filled($user->employee_id) || $request->routeIs(...self::EXEMPT_ROUTES)) {
            return $next($request);
        }

        abort_if($request->expectsJson(), 403, 'Add your employee ID to continue.');

        return redirect()->route('account.employee-id.edit');
    }
}
