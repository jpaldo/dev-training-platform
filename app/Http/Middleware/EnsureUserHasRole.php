<?php

namespace App\Http\Middleware;

use App\Enums\UserRole;
use Closure;
use Illuminate\Http\Request;
use InvalidArgumentException;
use Symfony\Component\HttpFoundation\Response;

class EnsureUserHasRole
{
    /**
     * Allow the request only for a user with one of the `role:` parameters. The parameters are mapped to cases before
     * the user is checked, so a misconfigured route (an unknown role throws `ValueError`, no role throws
     * `InvalidArgumentException`) fails for every request instead of silently refusing or allowing it.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next, string ...$roles): Response
    {
        if ($roles === []) {
            throw new InvalidArgumentException('The role middleware needs at least one role.');
        }

        $allowedRoles = array_map(UserRole::from(...), $roles);

        abort_unless($request->user()?->hasAnyRole(...$allowedRoles) === true, 403);

        return $next($request);
    }
}
