<?php

namespace Tests\Feature;

use App\Http\Controllers\Alpha\AttendanceController;
use App\Http\Controllers\Alpha\IlpController;
use App\Http\Controllers\Alpha\ProcessPageController;
use App\Http\Controllers\Alpha\SessionController;
use App\Http\Controllers\Alpha\WeeklyScheduleController;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class ControllerBoundaryTest extends TestCase
{
    public function test_weekly_schedule_mutations_use_dedicated_controller_without_changing_route_contracts(): void
    {
        $expected = [
            'alpha.process.schedules.store' => WeeklyScheduleController::class.'@store',
            'alpha.process.schedules.update' => WeeklyScheduleController::class.'@update',
            'alpha.process.schedules.toggle' => WeeklyScheduleController::class.'@toggle',
            'alpha.process.schedules.destroy' => WeeklyScheduleController::class.'@destroy',
        ];

        foreach ($expected as $routeName => $action) {
            $route = Route::getRoutes()->getByName($routeName);

            $this->assertNotNull($route, "Route {$routeName} harus tetap tersedia.");
            $this->assertSame($action, $route->getActionName());
        }
    }

    public function test_session_mutations_use_dedicated_controller_while_attendance_keeps_its_boundary(): void
    {
        $expected = [
            'alpha.sessions.create-from-schedule' => SessionController::class.'@createFromSchedule',
            'alpha.process.sessions.update' => SessionController::class.'@update',
            'alpha.process.sessions.note' => SessionController::class.'@updateNote',
            'alpha.process.sessions.close' => SessionController::class.'@close',
            'alpha.process.sessions.destroy' => SessionController::class.'@destroy',
        ];

        foreach ($expected as $routeName => $action) {
            $route = Route::getRoutes()->getByName($routeName);

            $this->assertNotNull($route, "Route {$routeName} harus tetap tersedia.");
            $this->assertSame($action, $route->getActionName());
        }

        $attendance = Route::getRoutes()->getByName('alpha.process.sessions.attendance');

        $this->assertNotNull($attendance);
        $this->assertSame(AttendanceController::class.'@update', $attendance->getActionName());
    }

    public function test_ilp_mutation_uses_dedicated_controller(): void
    {
        $update = Route::getRoutes()->getByName('alpha.process.ilp.update');

        $this->assertNotNull($update);
        $this->assertSame(IlpController::class.'@update', $update->getActionName());
        $this->assertSame('process/ilp/{ilpPlan}', $update->uri());
    }

    public function test_process_read_routes_use_process_page_controller_with_stable_contracts(): void
    {
        $expected = [
            'alpha.process' => [ProcessPageController::class.'@schedules', 'process'],
            'alpha.process.schedules' => [ProcessPageController::class.'@schedules', 'process/schedules'],
            'alpha.process.attendance' => [ProcessPageController::class.'@sessions', 'process/attendance'],
            'alpha.process.sessions' => [ProcessPageController::class.'@sessions', 'process/sessions'],
            'alpha.process.observations' => [ProcessPageController::class.'@observations', 'process/observations'],
            'alpha.process.ilp' => [ProcessPageController::class.'@ilp', 'process/ilp'],
        ];

        foreach ($expected as $routeName => [$action, $uri]) {
            $route = Route::getRoutes()->getByName($routeName);

            $this->assertNotNull($route, "Route {$routeName} harus tetap tersedia.");
            $this->assertSame($action, $route->getActionName());
            $this->assertSame($uri, $route->uri());
        }

        foreach (Route::getRoutes() as $route) {
            $this->assertStringNotContainsString(
                'ProcessController@',
                $route->getActionName(),
                "Route {$route->getName()} masih terhubung ke ProcessController legacy.",
            );
        }
    }
}
