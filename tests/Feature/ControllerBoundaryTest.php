<?php

namespace Tests\Feature;

use App\Http\Controllers\Alpha\AcademicCalendarController;
use App\Http\Controllers\Alpha\AttendanceController;
use App\Http\Controllers\Alpha\ClassStructureController;
use App\Http\Controllers\Alpha\CurriculumController;
use App\Http\Controllers\Alpha\IlpController;
use App\Http\Controllers\Alpha\MasterPageController;
use App\Http\Controllers\Alpha\ProcessPageController;
use App\Http\Controllers\Alpha\SessionController;
use App\Http\Controllers\Alpha\StudentGuardianController;
use App\Http\Controllers\Alpha\TeacherController;
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

    public function test_academic_calendar_mutations_use_dedicated_controller(): void
    {
        $expected = [
            'alpha.master.academic-years.store' => AcademicCalendarController::class.'@storeAcademicYear',
            'alpha.master.academic-years.update' => AcademicCalendarController::class.'@updateAcademicYear',
            'alpha.master.academic-years.activate' => AcademicCalendarController::class.'@activateAcademicYear',
            'alpha.master.academic-years.destroy' => AcademicCalendarController::class.'@destroyAcademicYear',
            'alpha.master.terms.store' => AcademicCalendarController::class.'@storeTerm',
            'alpha.master.terms.update' => AcademicCalendarController::class.'@updateTerm',
            'alpha.master.terms.activate' => AcademicCalendarController::class.'@activateTerm',
            'alpha.master.terms.destroy' => AcademicCalendarController::class.'@destroyTerm',
        ];

        foreach ($expected as $routeName => $action) {
            $route = Route::getRoutes()->getByName($routeName);
            $this->assertNotNull($route, "Route {$routeName} harus tetap tersedia.");
            $this->assertSame($action, $route->getActionName());
        }
    }

    public function test_class_structure_mutations_use_dedicated_controller(): void
    {
        $expected = [
            'alpha.master.classes.store' => ClassStructureController::class.'@storeClass',
            'alpha.master.classes.update' => ClassStructureController::class.'@updateClass',
            'alpha.master.classes.copy' => ClassStructureController::class.'@duplicateClass',
            'alpha.master.classes.toggle' => ClassStructureController::class.'@toggleClass',
            'alpha.master.classes.destroy' => ClassStructureController::class.'@destroyClass',
            'alpha.master.levels.store' => ClassStructureController::class.'@storeLevel',
            'alpha.master.levels.update' => ClassStructureController::class.'@updateLevel',
            'alpha.master.levels.toggle' => ClassStructureController::class.'@toggleLevel',
            'alpha.master.levels.destroy' => ClassStructureController::class.'@destroyLevel',
        ];

        foreach ($expected as $routeName => $action) {
            $route = Route::getRoutes()->getByName($routeName);
            $this->assertNotNull($route, "Route {$routeName} harus tetap tersedia.");
            $this->assertSame($action, $route->getActionName());
        }
    }

    public function test_student_and_guardian_mutations_use_dedicated_controller(): void
    {
        $expected = [
            'alpha.master.students.store' => StudentGuardianController::class.'@storeStudent',
            'alpha.master.students.import' => StudentGuardianController::class.'@importStudents',
            'alpha.master.students.update' => StudentGuardianController::class.'@updateStudent',
            'alpha.master.students.toggle' => StudentGuardianController::class.'@toggleStudent',
            'alpha.master.students.destroy' => StudentGuardianController::class.'@destroyStudent',
            'alpha.master.guardians.update' => StudentGuardianController::class.'@updateGuardian',
            'alpha.master.guardians.destroy' => StudentGuardianController::class.'@destroyGuardian',
        ];

        foreach ($expected as $routeName => $action) {
            $route = Route::getRoutes()->getByName($routeName);
            $this->assertNotNull($route, "Route {$routeName} harus tetap tersedia.");
            $this->assertSame($action, $route->getActionName());
        }
    }

    public function test_teacher_mutations_use_dedicated_controller(): void
    {
        $expected = [
            'alpha.master.teachers.store' => TeacherController::class.'@store',
            'alpha.master.teachers.import' => TeacherController::class.'@import',
            'alpha.master.teachers.update' => TeacherController::class.'@update',
            'alpha.master.teachers.toggle' => TeacherController::class.'@toggle',
            'alpha.master.teachers.destroy' => TeacherController::class.'@destroy',
        ];

        foreach ($expected as $routeName => $action) {
            $route = Route::getRoutes()->getByName($routeName);
            $this->assertNotNull($route, "Route {$routeName} harus tetap tersedia.");
            $this->assertSame($action, $route->getActionName());
        }
    }

    public function test_curriculum_mutations_use_dedicated_controller(): void
    {
        $expected = [
            'alpha.master.areas.store' => CurriculumController::class.'@storeArea',
            'alpha.master.areas.update' => CurriculumController::class.'@updateArea',
            'alpha.master.areas.destroy' => CurriculumController::class.'@destroyArea',
            'alpha.master.indicators.store' => CurriculumController::class.'@storeIndicator',
            'alpha.master.indicators.import' => CurriculumController::class.'@importIndicators',
            'alpha.master.indicators.update' => CurriculumController::class.'@updateIndicator',
            'alpha.master.indicators.toggle' => CurriculumController::class.'@toggleIndicator',
            'alpha.master.indicators.destroy' => CurriculumController::class.'@destroyIndicator',
        ];

        foreach ($expected as $routeName => $action) {
            $route = Route::getRoutes()->getByName($routeName);
            $this->assertNotNull($route, "Route {$routeName} harus tetap tersedia.");
            $this->assertSame($action, $route->getActionName());
        }
    }

    public function test_master_read_routes_use_master_page_controller_and_legacy_controller_is_retired(): void
    {
        $expected = [
            'alpha.master' => [MasterPageController::class.'@academicYears', 'master'],
            'alpha.master.academic-years' => [MasterPageController::class.'@academicYears', 'master/academic-years'],
            'alpha.master.classes' => [MasterPageController::class.'@classes', 'master/classes'],
            'alpha.master.levels' => [MasterPageController::class.'@levels', 'master/levels'],
            'alpha.master.students' => [MasterPageController::class.'@students', 'master/students'],
            'alpha.master.teachers' => [MasterPageController::class.'@teachers', 'master/teachers'],
            'alpha.master.curriculum' => [MasterPageController::class.'@curriculum', 'master/curriculum'],
            'alpha.master.import-template' => [MasterPageController::class.'@downloadImportTemplate', 'master/import-template/{type}'],
        ];

        foreach ($expected as $routeName => [$action, $uri]) {
            $route = Route::getRoutes()->getByName($routeName);
            $this->assertNotNull($route, "Route {$routeName} harus tetap tersedia.");
            $this->assertSame($action, $route->getActionName());
            $this->assertSame($uri, $route->uri());
        }

        foreach (Route::getRoutes() as $route) {
            $this->assertStringNotContainsString(
                'MasterController@',
                $route->getActionName(),
                "Route {$route->getName()} masih terhubung ke MasterController legacy.",
            );
        }
    }
}
