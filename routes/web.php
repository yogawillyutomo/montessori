<?php

use App\Http\Controllers\Alpha\AcademicCalendarController;
use App\Http\Controllers\Alpha\AttendanceController;
use App\Http\Controllers\Alpha\AuthController;
use App\Http\Controllers\Alpha\ClassStructureController;
use App\Http\Controllers\Alpha\CurriculumController;
use App\Http\Controllers\Alpha\CycleReportController;
use App\Http\Controllers\Alpha\DashboardController;
use App\Http\Controllers\Alpha\DevelopmentProgressController;
use App\Http\Controllers\Alpha\FamilyConferenceController;
use App\Http\Controllers\Alpha\FollowUpCandidateController;
use App\Http\Controllers\Alpha\IlpController;
use App\Http\Controllers\Alpha\MasterController;
use App\Http\Controllers\Alpha\ObservationController;
use App\Http\Controllers\Alpha\PresentationController;
use App\Http\Controllers\Alpha\ProcessPageController;
use App\Http\Controllers\Alpha\ReportController;
use App\Http\Controllers\Alpha\ReportEligibilityController;
use App\Http\Controllers\Alpha\SessionController;
use App\Http\Controllers\Alpha\SettingController;
use App\Http\Controllers\Alpha\StudentGuardianController;
use App\Http\Controllers\Alpha\TeacherController;
use App\Http\Controllers\Alpha\WeeklyScheduleController;
use App\Http\Middleware\EnsureTeacherSessionMutationScope;
use Illuminate\Support\Facades\Route;

Route::middleware('guest')->group(function (): void {
    Route::get('/login', [AuthController::class, 'showLogin'])->name('login');
    Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:5,1')->name('login.store');
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

        Route::post('/master/classes', [ClassStructureController::class, 'storeClass'])->name('alpha.master.classes.store');
        Route::post('/master/levels', [ClassStructureController::class, 'storeLevel'])->name('alpha.master.levels.store');
        Route::post('/master/academic-years', [AcademicCalendarController::class, 'storeAcademicYear'])->name('alpha.master.academic-years.store');
        Route::patch('/master/academic-years/{academicYear}', [AcademicCalendarController::class, 'updateAcademicYear'])->name('alpha.master.academic-years.update');
        Route::patch('/master/academic-years/{academicYear}/activate', [AcademicCalendarController::class, 'activateAcademicYear'])->name('alpha.master.academic-years.activate');
        Route::delete('/master/academic-years/{academicYear}', [AcademicCalendarController::class, 'destroyAcademicYear'])->name('alpha.master.academic-years.destroy');
        Route::post('/master/terms', [AcademicCalendarController::class, 'storeTerm'])->name('alpha.master.terms.store');
        Route::patch('/master/terms/{term}', [AcademicCalendarController::class, 'updateTerm'])->name('alpha.master.terms.update');
        Route::patch('/master/terms/{term}/current', [AcademicCalendarController::class, 'activateTerm'])->name('alpha.master.terms.activate');
        Route::delete('/master/terms/{term}', [AcademicCalendarController::class, 'destroyTerm'])->name('alpha.master.terms.destroy');
        Route::patch('/master/classes/{schoolClass}', [ClassStructureController::class, 'updateClass'])->name('alpha.master.classes.update');
        Route::post('/master/classes/{schoolClass}/copy', [ClassStructureController::class, 'duplicateClass'])->name('alpha.master.classes.copy');
        Route::patch('/master/classes/{schoolClass}/toggle', [ClassStructureController::class, 'toggleClass'])->name('alpha.master.classes.toggle');
        Route::delete('/master/classes/{schoolClass}', [ClassStructureController::class, 'destroyClass'])->name('alpha.master.classes.destroy');
        Route::patch('/master/levels/{classLevel}', [ClassStructureController::class, 'updateLevel'])->name('alpha.master.levels.update');
        Route::patch('/master/levels/{classLevel}/toggle', [ClassStructureController::class, 'toggleLevel'])->name('alpha.master.levels.toggle');
        Route::delete('/master/levels/{classLevel}', [ClassStructureController::class, 'destroyLevel'])->name('alpha.master.levels.destroy');
        Route::post('/master/students', [StudentGuardianController::class, 'storeStudent'])->name('alpha.master.students.store');
        Route::post('/master/students/import', [StudentGuardianController::class, 'importStudents'])->name('alpha.master.students.import');
        Route::patch('/master/students/{student}', [StudentGuardianController::class, 'updateStudent'])->name('alpha.master.students.update');
        Route::patch('/master/students/{student}/toggle', [StudentGuardianController::class, 'toggleStudent'])->name('alpha.master.students.toggle');
        Route::delete('/master/students/{student}', [StudentGuardianController::class, 'destroyStudent'])->name('alpha.master.students.destroy');
        Route::patch('/master/guardians/{guardian}', [StudentGuardianController::class, 'updateGuardian'])->name('alpha.master.guardians.update');
        Route::delete('/master/guardians/{guardian}', [StudentGuardianController::class, 'destroyGuardian'])->name('alpha.master.guardians.destroy');
        Route::post('/master/areas', [CurriculumController::class, 'storeArea'])->name('alpha.master.areas.store');
        Route::patch('/master/areas/{developmentArea}', [CurriculumController::class, 'updateArea'])->name('alpha.master.areas.update');
        Route::delete('/master/areas/{developmentArea}', [CurriculumController::class, 'destroyArea'])->name('alpha.master.areas.destroy');
        Route::post('/master/teachers', [TeacherController::class, 'store'])->name('alpha.master.teachers.store');
        Route::post('/master/teachers/import', [TeacherController::class, 'import'])->name('alpha.master.teachers.import');
        Route::patch('/master/teachers/{teacher}', [TeacherController::class, 'update'])->name('alpha.master.teachers.update');
        Route::patch('/master/teachers/{teacher}/toggle', [TeacherController::class, 'toggle'])->name('alpha.master.teachers.toggle');
        Route::delete('/master/teachers/{teacher}', [TeacherController::class, 'destroy'])->name('alpha.master.teachers.destroy');
        Route::post('/master/indicators', [CurriculumController::class, 'storeIndicator'])->name('alpha.master.indicators.store');
        Route::post('/master/indicators/import', [CurriculumController::class, 'importIndicators'])->name('alpha.master.indicators.import');
        Route::patch('/master/indicators/{indicator}', [CurriculumController::class, 'updateIndicator'])->name('alpha.master.indicators.update');
        Route::patch('/master/indicators/{indicator}/toggle', [CurriculumController::class, 'toggleIndicator'])->name('alpha.master.indicators.toggle');
        Route::delete('/master/indicators/{indicator}', [CurriculumController::class, 'destroyIndicator'])->name('alpha.master.indicators.destroy');
        Route::post('/process/schedules', [WeeklyScheduleController::class, 'store'])->name('alpha.process.schedules.store');
        Route::patch('/process/schedules/{weeklySchedule}', [WeeklyScheduleController::class, 'update'])->name('alpha.process.schedules.update');
        Route::patch('/process/schedules/{weeklySchedule}/toggle', [WeeklyScheduleController::class, 'toggle'])->name('alpha.process.schedules.toggle');
        Route::delete('/process/schedules/{weeklySchedule}', [WeeklyScheduleController::class, 'destroy'])->name('alpha.process.schedules.destroy');
    });

    Route::middleware('role:super_admin,admin,teacher,principal')->group(function (): void {
        Route::get('/process', [ProcessPageController::class, 'schedules'])->name('alpha.process');
        Route::get('/process/schedules', [ProcessPageController::class, 'schedules'])->name('alpha.process.schedules');
        Route::get('/process/attendance', [ProcessPageController::class, 'sessions'])->name('alpha.process.attendance');
        Route::get('/process/sessions', [ProcessPageController::class, 'sessions'])->name('alpha.process.sessions');
        Route::get('/process/observations', [ProcessPageController::class, 'observations'])->name('alpha.process.observations');
        Route::get('/process/follow-up', [FollowUpCandidateController::class, 'index'])->name('alpha.process.follow-up');
        Route::get('/process/ilp', [ProcessPageController::class, 'ilp'])->name('alpha.process.ilp');
    });

    Route::middleware('role:super_admin,admin,teacher')->group(function (): void {
        Route::post('/sessions/from-schedule', [SessionController::class, 'createFromSchedule'])->name('alpha.sessions.create-from-schedule');
        Route::patch('/process/sessions/{classSession}', [SessionController::class, 'update'])
            ->middleware(EnsureTeacherSessionMutationScope::class)
            ->name('alpha.process.sessions.update');
        Route::patch('/process/sessions/{classSession}/note', [SessionController::class, 'updateNote'])->name('alpha.process.sessions.note');
        Route::patch('/process/sessions/{classSession}/attendance', [AttendanceController::class, 'update'])
            ->middleware(EnsureTeacherSessionMutationScope::class)
            ->name('alpha.process.sessions.attendance');
        Route::patch('/process/sessions/{classSession}/close', [SessionController::class, 'close'])->name('alpha.process.sessions.close');
        Route::delete('/process/sessions/{classSession}', [SessionController::class, 'destroy'])->name('alpha.process.sessions.destroy');
        Route::post('/presentations', [PresentationController::class, 'store'])->name('alpha.presentations.store');
        Route::post('/development-progress', [DevelopmentProgressController::class, 'store'])->name('alpha.development-progress.store');
        Route::post('/observations', [ObservationController::class, 'store'])->name('alpha.observations.store');
        Route::post('/follow-up-candidates/{followUpCandidate}/confirm', [FollowUpCandidateController::class, 'confirm'])->name('alpha.follow-up-candidates.confirm');
        Route::post('/follow-up-candidates/{followUpCandidate}/dismiss', [FollowUpCandidateController::class, 'dismiss'])->name('alpha.follow-up-candidates.dismiss');
        Route::patch('/process/ilp/{ilpPlan}', [IlpController::class, 'update'])->name('alpha.process.ilp.update');
        Route::post('/reports/generate', [ReportController::class, 'generate'])->name('alpha.reports.generate');
        Route::post('/reports/students/{student}/draft', [ReportController::class, 'buildStudentDraft'])->name('alpha.reports.students.draft');
        Route::post('/reports/cycles/{reportCycle}/students/{student}/eligibility', [ReportEligibilityController::class, 'evaluate'])->name('alpha.report-eligibility.evaluate');
        Route::post('/report-eligibilities/{reportEligibility}/confirm', [ReportEligibilityController::class, 'confirm'])->name('alpha.report-eligibility.confirm');

        Route::post('/reports/cycles/{reportCycle}/students/{student}/draft', [CycleReportController::class, 'draft'])->name('alpha.cycle-reports.draft');
        Route::patch('/cycle-reports/{report}', [CycleReportController::class, 'update'])->name('alpha.cycle-reports.update');
        Route::post('/cycle-reports/{report}/submit', [CycleReportController::class, 'submit'])->name('alpha.cycle-reports.submit');

        Route::post('/students/{student}/family-conferences', [FamilyConferenceController::class, 'store'])->name('alpha.family-conferences.store');
        Route::patch('/family-conferences/{familyConference}', [FamilyConferenceController::class, 'update'])->name('alpha.family-conferences.update');
    });

    Route::middleware('role:super_admin,principal')->group(function (): void {
        Route::post('/cycle-reports/{report}/review', [CycleReportController::class, 'startReview'])->name('alpha.cycle-reports.review');
        Route::post('/cycle-reports/{report}/request-revision', [CycleReportController::class, 'requestRevision'])->name('alpha.cycle-reports.request-revision');
        Route::post('/cycle-reports/{report}/approve', [CycleReportController::class, 'approve'])->name('alpha.cycle-reports.approve');
    });

    Route::middleware('role:super_admin,admin,teacher,principal,parent')->group(function (): void {
        Route::get('/reports', [ReportController::class, 'index'])->name('alpha.reports');
        Route::get('/reports/students/{student}', [ReportController::class, 'student'])->name('alpha.reports.student');
        Route::get('/reports/{report}/print', [ReportController::class, 'print'])->name('alpha.reports.print');
        Route::get('/reports/{report}', [ReportController::class, 'show'])->name('alpha.reports.show');
        Route::get('/students/{student}/family-conferences', [FamilyConferenceController::class, 'index'])->name('alpha.family-conferences.index');
    });

    Route::middleware('role:super_admin,admin,teacher,principal')->group(function (): void {
        Route::patch('/reports/students/{student}', [ReportController::class, 'saveStudentReport'])->name('alpha.reports.students.update');
    });

    Route::middleware('role:super_admin,admin')->group(function (): void {
        Route::post('/report-eligibilities/{reportEligibility}/override', [ReportEligibilityController::class, 'override'])->name('alpha.report-eligibility.override');
        Route::patch('/reports/{report}/publish', [ReportController::class, 'publish'])->name('alpha.reports.publish');

        Route::post('/cycle-reports/{report}/publish', [CycleReportController::class, 'publish'])->name('alpha.cycle-reports.publish');
        Route::post('/cycle-reports/{report}/begin-revision', [CycleReportController::class, 'beginRevision'])->name('alpha.cycle-reports.begin-revision');
        Route::post('/cycle-reports/{report}/archive', [CycleReportController::class, 'archive'])->name('alpha.cycle-reports.archive');
    });

    Route::middleware('role:super_admin')->group(function (): void {
        Route::get('/settings/users', [SettingController::class, 'users'])->name('alpha.settings.users');
        Route::post('/settings/users', [SettingController::class, 'storeUser'])->name('alpha.settings.users.store');
        Route::patch('/settings/users/{user}', [SettingController::class, 'updateUser'])->name('alpha.settings.users.update');
        Route::delete('/settings/users/{user}', [SettingController::class, 'destroyUser'])->name('alpha.settings.users.destroy');
    });
});
