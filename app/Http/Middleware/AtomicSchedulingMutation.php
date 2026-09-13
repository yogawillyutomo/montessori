<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

class AtomicSchedulingMutation
{
    /**
     * @var array<int, string>
     */
    private const ROUTES = [
        'alpha.process.schedules.store',
        'alpha.process.schedules.update',
        'alpha.process.schedules.toggle',
        'alpha.process.schedules.destroy',
        'alpha.sessions.create-from-schedule',
        'alpha.process.sessions.update',
        'alpha.process.sessions.destroy',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        $routeName = $request->route()?->getName();

        if (! is_string($routeName) || ! in_array($routeName, self::ROUTES, true)) {
            return $next($request);
        }

        return DB::transaction(fn (): Response => $next($request));
    }
}
