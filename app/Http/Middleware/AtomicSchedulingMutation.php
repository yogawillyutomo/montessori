<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

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

        $connection = DB::connection();
        $startingLevel = $connection->transactionLevel();
        $connection->beginTransaction();

        try {
            $response = $next($request);

            if ($this->hasNewValidationErrors($request)) {
                $this->rollbackToLevel($connection, $startingLevel);

                return $response;
            }

            $connection->commit();

            return $response;
        } catch (Throwable $exception) {
            $this->rollbackToLevel($connection, $startingLevel);

            throw $exception;
        }
    }

    private function hasNewValidationErrors(Request $request): bool
    {
        if (! $request->hasSession()) {
            return false;
        }

        $newFlashKeys = (array) $request->session()->get('_flash.new', []);

        return in_array('errors', $newFlashKeys, true)
            && $request->session()->has('errors');
    }

    private function rollbackToLevel(ConnectionInterface $connection, int $startingLevel): void
    {
        while ($connection->transactionLevel() > $startingLevel) {
            $connection->rollBack();
        }
    }
}
