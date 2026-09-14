<?php

namespace Tests\Feature;

use App\Http\Controllers\Alpha\ProcessController;
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
}
