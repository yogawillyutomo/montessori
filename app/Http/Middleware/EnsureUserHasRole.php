<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureUserHasRole
{
    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next, string ...$roles): Response
    {
        $user = $request->user();

        abort_if(! $user || ! $user->is_active, 403);

        $allowedRoles = collect($roles)
            ->flatMap(fn (string $role): array => explode(',', $role))
            ->map(fn (string $role): string => trim($role))
            ->filter()
            ->values()
            ->all();

        abort_if($allowedRoles !== [] && ! in_array($user->role, $allowedRoles, true), 403);

        return $next($request);
    }
}
