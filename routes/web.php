<?php

use App\Http\Controllers\Alpha\AuthController;
use App\Http\Controllers\Alpha\DashboardController;
use App\Http\Controllers\Alpha\MasterController;
use App\Http\Controllers\Alpha\ProcessController;
use App\Http\Controllers\Alpha\ReportController;
use App\Http\Controllers\Alpha\SettingController;
use Illuminate\Support\Facades\Route;

Route::middleware('guest')->group(function (): void {
    Route::get('/login', [AuthController::class, 'showLogin'])->name('login');
    Route::post('/login', [AuthController::class, 'login'])->name('login.store');
});

Route::middleware('auth')->group(function (): void {
    Route::post('/logout', [AuthController::class, 'logout'])->name('logout');
    Route::patch('/profile', [SettingController::class, 'updateProfile'])->name('alpha.profile.update');

    Route::get('/', DashboardController::class)->name('alpha.dashboard');

    Route::middleware('role:super_admin,admin')->group(function (): void {
        Route::get('/master', [MasterController::class, 'academicYears'])->name('alpha.master');
        Route::get('/master/academic-years', [MasterController::class, 'academicYears'])->name('alpha.master.academic-years');
        Route::get('/master/classes', [MasterController::class, 'classes'])->name('alpha.master.classes');
        Route::get('/master/levels', [MasterController::class, 'levels'])->name('alpha.master.levels');
        Route::get('/master/students', [MasterController::class, 'students'])->name('alpha.master.students');
        Route::get('/master/teachers', [MasterController::class, 'teachers'])->name('alpha.master.teachers');
        Route::get('/master/curriculum', [MasterController::class, 'curriculum'])->name('alpha.master.curriculum');
        Route::get('/master/import-template/{type}', [MasterController::class, 'downloadImportTemplate'])->name('alpha.master.import-template');

        Route::post('/master/classes', [MasterController::class, 'storeClass'])->name('alpha.master.classes.store');
        Route::post('/master/levels', [MasterController::class, 'storeLevel'])->name('alpha.master.levels.store');
        Route::post('/master/academic-years', [MasterController::class, 'storeAcademicYear'])->name('alpha.master.academic-years.store');
        Route::patch('/master/academic-years/{academicYear}', [MasterController::class, 'updateAcademicYear'])->name('alpha.master.academic-years.update');
        Route::patch('/master/academic-years/{academicYear}/activate', [MasterController::class, 'activateAcademicYear'])->name('alpha.master.academic-years.activate');
        Route::delete('/master/academic-years/{academicYear}', [MasterController::class, 'destroyAcademicYear'])->name('alpha.master.academic-years.destroy');
        Route::post('/master/terms', [MasterController::class, 'storeTerm'])->name('alpha.master.terms.store');
        Route::patch('/master/terms/{term}', [MasterController::class, 'updateTerm'])->name('alpha.master.terms.update');
        Route::patch('/master/terms/{term}/current', [MasterController::class, 'activateTerm'])->name('alpha.master.terms.activate');
        Route::delete('/master/terms/{term}', [MasterController::class, 'destroyTerm'])->name('alpha.master.terms.destroy');
        Route::patch('/master/classes/{schoolClass}', [MasterController::class, 'updateClass'])->name('alpha.master.classes.update');
        Route::post('/master/classes/{schoolClass}/copy', [MasterController::class, 'duplicateClass'])->name('alpha.master.classes.copy');
        Route::patch('/master/classes/{schoolClass}/toggle', [MasterController::class, 'toggleClass'])->name('alpha.master.classes.toggle');
        Route::delete('/master/classes/{schoolClass}', [MasterController::class, 'destroyClass'])->name('alpha.master.classes.destroy');
        Route::patch('/master/levels/{classLevel}', [MasterController::class, 'updateLevel'])->name('alpha.master.levels.update');
        Route::patch('/master/levels/{classLevel}/toggle', [MasterController::class, 'toggleLevel'])->name('alpha.master.levels.toggle');
        Route::delete('/master/levels/{classLevel}', [MasterController::class, 'destroyLevel'])->name('alpha.master.levels.destroy');
        Route::post('/master/students', [MasterController::class, 'storeStudent'])->name('alpha.master.students.store');
        Route::post('/master/students/import', [MasterController::class, 'importStudents'])->name('alpha.master.students.import');
        Route::patch('/master/students/{student}', [MasterController::class, 'updateStudent'])->name('alpha.master.students.update');
        Route::patch('/master/students/{student}/toggle', [MasterController::class, 'toggleStudent'])->name('alpha.master.students.toggle');
        Route::delete('/master/students/{student}', [MasterController::class, 'destroyStudent'])->name('alpha.master.students.destroy');
        Route::patch('/master/guardians/{guardian}', [MasterController::class, 'updateGuardian'])->name('alpha.master.guardians.update');
        Route::delete('/master/guardians/{guardian}', [MasterController::class, 'destroyGuardian'])->name('alpha.master.guardians.destroy');
        Route::post('/master/areas', [MasterController::class, 'storeArea'])->name('alpha.master.areas.store');
        Route::patch('/master/areas/{developmentArea}', [MasterController::class, 'updateArea'])->name('alpha.master.areas.update');
        Route::delete('/master/areas/{developmentArea}', [MasterController::class, 'destroyArea'])->name('alpha.master.areas.destroy');
        Route::post('/master/teachers', [MasterController::class, 'storeTeacher'])->name('alpha.master.teachers.store');
        Route::post('/master/teachers/import', [MasterController::class, 'importTeachers'])->name('alpha.master.teachers.import');
        Route::patch('/master/teachers/{teacher}', [MasterController::class, 'updateTeacher'])->name('alpha.master.teachers.update');
        Route::patch('/master/teachers/{teacher}/toggle', [MasterController::class, 'toggleTeacher'])->name('alpha.master.teachers.toggle');
        Route::delete('/master/teachers/{teacher}', [MasterController::class, 'destroyTeacher'])->name('alpha.master.teachers.destroy');
        Route::post('/master/indicators', [MasterController::class, 'storeIndicator'])->name('alpha.master.indicators.store');
        Route::post('/master/indicators/import', [MasterController::class, 'importIndicators'])->name('alpha.master.indicators.import');
        Route::patch('/master/indicators/{indicator}', [MasterController::class, 'updateIndicator'])->name('alpha.master.indicators.update');
        Route::patch('/master/indicators/{indicator}/toggle', [MasterController::class, 'toggleIndicator'])->name('alpha.master.indicators.toggle');
        Route::delete('/master/indicators/{indicator}', [MasterController::class, 'destroyIndicator'])->name('alpha.master.indicators.destroy');
        Route::post('/process/schedules', [ProcessController::class, 'storeSchedule'])->name('alpha.process.schedules.store');
        Route::patch('/process/schedules/{weeklySchedule}', [ProcessController::class, 'updateSchedule'])->name('alpha.process.schedules.update');
        Route::patch('/process/schedules/{weeklySchedule}/toggle', [ProcessController::class, 'toggleSchedule'])->name('alpha.process.schedules.toggle');
        Route::delete('/process/schedules/{weeklySchedule}', [ProcessController::class, 'destroySchedule'])->name('alpha.process.schedules.destroy');
    });

    Route::middleware('role:super_admin,admin,teacher,principal')->group(function (): void {
        Route::get('/process', [ProcessController::class, 'schedules'])->name('alpha.process');
        Route::get('/process/schedules', [ProcessController::class, 'schedules'])->name('alpha.process.schedules');
        Route::get('/process/attendance', [ProcessController::class, 'sessions'])->name('alpha.process.attendance');
        Route::get('/process/sessions', [ProcessController::class, 'sessions'])->name('alpha.process.sessions');
        Route::get('/process/observations', [ProcessController::class, 'observations'])->name('alpha.process.observations');
        Route::get('/process/ilp', [ProcessController::class, 'ilp'])->name('alpha.process.ilp');
    });

    Route::middleware('role:super_admin,admin,teacher')->group(function (): void {
        Route::post('/sessions/from-schedule', [ProcessController::class, 'createSession'])->name('alpha.sessions.create-from-schedule');
        Route::patch('/process/sessions/{classSession}', [ProcessController::class, 'updateSession'])->name('alpha.process.sessions.update');
        Route::patch('/process/sessions/{classSession}/note', [ProcessController::class, 'updateSessionNote'])->name('alpha.process.sessions.note');
        Route::patch('/process/sessions/{classSession}/attendance', [ProcessController::class, 'updateSessionAttendance'])->name('alpha.process.sessions.attendance');
        Route::patch('/process/sessions/{classSession}/close', [ProcessController::class, 'closeSession'])->name('alpha.process.sessions.close');
        Route::delete('/process/sessions/{classSession}', [ProcessController::class, 'destroySession'])->name('alpha.process.sessions.destroy');
        Route::post('/observations', [ProcessController::class, 'storeObservation'])->name('alpha.observations.store');
        Route::patch('/process/ilp/{ilpPlan}', [ProcessController::class, 'updateIlp'])->name('alpha.process.ilp.update');
        Route::post('/reports/generate', [ReportController::class, 'generate'])->name('alpha.reports.generate');
        Route::post('/reports/students/{student}/draft', [ReportController::class, 'buildStudentDraft'])->name('alpha.reports.students.draft');
    });

    Route::middleware('role:super_admin,admin,teacher,principal,parent')->group(function (): void {
        Route::get('/reports', [ReportController::class, 'index'])->name('alpha.reports');
        Route::get('/reports/students/{student}', [ReportController::class, 'student'])->name('alpha.reports.student');
        Route::get('/reports/{report}/print', [ReportController::class, 'print'])->name('alpha.reports.print');
        Route::get('/reports/{report}', [ReportController::class, 'show'])->name('alpha.reports.show');
    });

    Route::middleware('role:super_admin,admin,teacher,principal')->group(function (): void {
        Route::patch('/reports/students/{student}', [ReportController::class, 'saveStudentReport'])->name('alpha.reports.students.update');
    });

    Route::middleware('role:super_admin,admin')->group(function (): void {
        Route::patch('/reports/{report}/publish', [ReportController::class, 'publish'])->name('alpha.reports.publish');
    });

    Route::middleware('role:super_admin')->group(function (): void {
        Route::get('/settings/users', [SettingController::class, 'users'])->name('alpha.settings.users');
        Route::post('/settings/users', [SettingController::class, 'storeUser'])->name('alpha.settings.users.store');
        Route::patch('/settings/users/{user}', [SettingController::class, 'updateUser'])->name('alpha.settings.users.update');
        Route::delete('/settings/users/{user}', [SettingController::class, 'destroyUser'])->name('alpha.settings.users.destroy');
    });
});
