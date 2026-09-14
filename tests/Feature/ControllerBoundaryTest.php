<?php

namespace Tests\Feature;

use App\Http\Controllers\Alpha\AttendanceController;
use App\Http\Controllers\Alpha\ProcessController;
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

        $scheduleIndex = Route::getRoutes()->getByName('alpha.process.schedules');

        $this->assertNotNull($scheduleIndex);
        $this->assertSame(ProcessController::class.'@schedules', $scheduleIndex->getActionName());
        $this->assertSame('process/schedules', $scheduleIndex->uri());
    }

    public function test_session_mutations_use_dedicated_controller_while_attendance_and_read_routes_keep_their_boundaries(): void
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
        $sessionIndex = Route::getRoutes()->getByName('alpha.process.sessions');

        $this->assertNotNull($attendance);
        $this->assertSame(AttendanceController::class.'@update', $attendance->getActionName());
        $this->assertNotNull($sessionIndex);
        $this->assertSame(ProcessController::class.'@sessions', $sessionIndex->getActionName());
        $this->assertSame('process/sessions', $sessionIndex->uri());
    }
}
