<?php

namespace App\Http\Controllers\Alpha;

use App\Http\Controllers\Alpha\Concerns\ProvidesAlphaShell;
use App\Http\Controllers\Controller;
use App\Http\Requests\Alpha\Report\SaveStudentReportRequest;
use App\Models\Report;
use App\Models\ReportCycle;
use App\Models\ReportEligibility;
use App\Models\SchoolClass;
use App\Models\Student;
use App\Models\Teacher;
use App\Models\Term;
use App\Services\Alpha\AccessScopeService;
use App\Services\Alpha\ObservationSummaryService;
use App\Services\Alpha\ReportBuilderService;
use App\Services\Alpha\ReportStudentListService;
use App\Support\Alpha\Role;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class ReportController extends Controller
{
    use ProvidesAlphaShell;

    public function index(Request $request, ReportStudentListService $studentList): View
    {
        $user = $request->user();
        $scope = app(AccessScopeService::class);
        $term = $this->termFromRequest($request);
        $filters = [
            'q' => $request->string('q')->trim()->toString(),
            'school_class_id' => $request->integer('school_class_id') ?: null,
            'teacher_id' => $request->integer('teacher_id') ?: null,
            'status' => $request->string('status')->toString(),
        ];

        return view('alpha.reports', [
            ...$this->shell($request, 'reports'),
            'students' => $studentList->paginateForUser($user, $filters, $term),
            'currentTerm' => $term,
            'terms' => Term::query()->with('academicYear')->latest('starts_on')->get(),
            'classes' => SchoolClass::naturalSort(
                SchoolClass::query()
                    ->with('classLevel')
                    ->whereIn('id', $scope->accessibleClassIds($user))
                    ->get()
            ),
            'teachers' => Teacher::query()
                ->whereIn('id', $scope->accessibleTeacherIds($user))
                ->orderBy('name')
                ->get(),
            'reportFilters' => [
                ...$filters,
                'term_id' => $term->id,
                'status' => $user->role === Role::PARENT ? 'published' : $filters['status'],
            ],
            'statusOptions' => [
                'not_created' => 'Belum Dibuat',
                'draft' => 'Draft',
                'ready' => 'Siap Direview',
                'published' => 'Dipublish',
                'archived' => 'Diarsipkan',
            ],
            'canGenerateReport' => $scope->canGenerateReport($user),
            'canUseTeacherFilter' => Role::hasGlobalAccess($user->role),
            'isParentView' => $user->role === Role::PARENT,
        ]);
    }

    public function student(Request $request, Student $student, ObservationSummaryService $observationSummary): View
    {
        $term = $this->termFromRequest($request);
        $report = Report::query()
            ->where('student_id', $student->id)
            ->where('term_id', $term->id)
            ->whereNull('report_cycle_id')
            ->first();

        return $this->studentReportView($request, $student, $term, $observationSummary, $report);
    }

    public function show(Request $request, Report $report, ObservationSummaryService $observationSummary): View
    {
        $report->loadMissing([
            'student.guardian',
            'student.schoolClass.classLevel',
            'term.academicYear',
            'reportCycle.reportPolicy',
            'homeroomTeacher',
            'publishedVersion',
        ]);
        $scope = app(AccessScopeService::class);

        abort_if(! $request->user() || ! $scope->canViewReport($request->user(), $report), 403);

        return $this->studentReportView($request, $report->student, $report->term, $observationSummary, $report);
    }

    public function buildStudentDraft(Request $request, Student $student, ReportBuilderService $builder): RedirectResponse
    {
        $scope = app(AccessScopeService::class);
        $user = $request->user();
        $term = $this->termFromRequest($request);

        abort_if(! $user || ! $scope->canGenerateReport($user) || ! $scope->canViewStudent($user, $student), 403);

        $builder->buildDraft($student, $term, $user);

        return redirect()
            ->route('alpha.reports.student', ['student' => $student, 'term_id' => $term->id])
            ->with('status', 'Draft rapor siswa berhasil dibuat dari bahan observasi.');
    }

    public function saveStudentReport(SaveStudentReportRequest $request, Student $student, ReportBuilderService $builder): RedirectResponse
    {
        $term = $this->termFromRequest($request);
        $report = $builder->saveManualReport($student, $term, $request->user(), $request->validated());

        return redirect()
            ->route('alpha.reports.student', ['student' => $student, 'term_id' => $term->id])
            ->with('status', "Rapor {$report->student->name} berhasil disimpan.");
    }

    public function publish(Request $request, Report $report, ReportBuilderService $builder): RedirectResponse
    {
        $user = $request->user();

        abort_if(! $user || ! in_array($user->role, [Role::SUPER_ADMIN, Role::ADMIN], true), 403);

        $builder->publish($report, $user);

        return redirect()
            ->route('alpha.reports.student', ['student' => $report->student_id, 'term_id' => $report->term_id])
            ->with('status', 'Rapor berhasil dipublish untuk orang tua.');
    }

    public function print(Request $request, Report $report, ObservationSummaryService $observationSummary): View
    {
        $report->loadMissing([
            'student.guardian',
            'student.schoolClass.classLevel',
            'term.academicYear',
            'reportCycle.reportPolicy',
            'homeroomTeacher',
            'publishedVersion',
        ]);
        $scope = app(AccessScopeService::class);
        $user = $request->user();

        abort_if(! $user || ! $scope->canViewReport($user, $report), 403);

        $displayReport = $user->role === Role::PARENT
            ? $report->publishedDisplayReport()
            : $report;
        abort_if(! $displayReport, 403);

        $summaryObservation = (array) ($displayReport->summary['observation'] ?? []);
        if ($summaryObservation === []) {
            $summaryObservation = $report->reportCycle
                ? $observationSummary->summarizeForCycle($report->student, $report->reportCycle)
                : $observationSummary->summarizeForStudent($report->student, $report->term);
        }

        return view('alpha.report-print', [
            'report' => $displayReport,
            'student' => $report->student,
            'term' => $report->term,
            'reportCycle' => $report->reportCycle,
            'attendance' => $displayReport->manualAttendanceSummary(),
            'observationSummary' => $summaryObservation,
            'statusLabels' => $this->statusLabels(),
        ]);
    }

    public function generate(Request $request, ReportBuilderService $builder): RedirectResponse
    {
        $scope = app(AccessScopeService::class);
        $user = $request->user();

        abort_if(! $user || ! $scope->canGenerateReport($user), 403);

        $term = $this->termFromRequest($request);

        $scope->accessibleStudentsQuery($user)
            ->with(['guardian', 'schoolClass.classLevel'])
            ->orderBy('name')
            ->each(fn (Student $student) => $builder->buildDraft($student, $term, $user));

        return back()->with('status', 'Draft rapor berhasil diperbarui dari bahan observasi. Presensi rapor tetap diisi manual.');
    }

    private function studentReportView(
        Request $request,
        Student $student,
        Term $term,
        ObservationSummaryService $observationSummary,
        ?Report $report = null
    ): View {
        $user = $request->user();
        $scope = app(AccessScopeService::class);

        abort_if(! $user || ! $scope->canViewStudent($user, $student), 403);

        $student->loadMissing(['guardian', 'schoolClass.classLevel']);
        $report?->loadMissing([
            'student.guardian',
            'student.schoolClass.classLevel',
            'term.academicYear',
            'reportCycle.reportPolicy',
            'homeroomTeacher',
            'publishedVersion',
        ]);

        if ($user->role === Role::PARENT) {
            abort_if(! $report || ! $scope->canViewReport($user, $report), 403);
            $displayReport = $report->publishedDisplayReport();
            abort_if(! $displayReport, 403);
        } else {
            $displayReport = $report;
        }

        $isCycleReport = $report?->isCycleReport() ?? false;
        $workingStatus = $report?->status ?? 'not_created';
        $isPublished = $workingStatus === 'published';
        $isEditableCycleState = $isCycleReport && in_array($workingStatus, ['draft', 'revision_requested'], true);
        $canGenerate = $scope->canGenerateReport($user);
        $canReview = $scope->canApproveReport($user);
        $isPublisher = in_array($user->role, [Role::SUPER_ADMIN, Role::ADMIN], true);

        $summaryObservation = (array) ($displayReport?->summary['observation'] ?? []);
        if ($summaryObservation === []) {
            $summaryObservation = $report?->reportCycle
                ? $observationSummary->summarizeForCycle($student, $report->reportCycle)
                : $observationSummary->summarizeForStudent($student, $term);
        }

        return view('alpha.report-student', [
            ...$this->shell($request, 'reports'),
            'student' => $student,
            'term' => $term,
            'terms' => Term::query()->with('academicYear')->latest('starts_on')->get(),
            'report' => $displayReport,
            'workingReport' => $report,
            'reportCycle' => $report?->reportCycle,
            'isCycleReport' => $isCycleReport,
            'cycleRows' => $user->role === Role::PARENT ? [] : $this->cycleRowsForStudent($student),
            'reportStatus' => $displayReport?->status ?? 'not_created',
            'workingReportStatus' => $workingStatus,
            'observationSummary' => $summaryObservation,
            'attendance' => $displayReport?->manualAttendanceSummary() ?? [
                'recorded' => 0,
                'present' => 0,
                'late' => 0,
                'sick' => 0,
                'excused' => 0,
                'absent' => 0,
                'attendance_rate' => 0,
            ],
            'canBuildDraft' => ! $isCycleReport && $canGenerate && ! $isPublished,
            'canGenerateCycleReport' => $canGenerate,
            'canEditReport' => $isCycleReport
                ? $canGenerate && $isEditableCycleState
                : $user->role !== Role::PARENT && ! $isPublished,
            'canPublishReport' => ! $isCycleReport && $isPublisher && $report !== null && ! $isPublished,
            'canSubmitCycleReport' => $isCycleReport && $canGenerate && in_array($workingStatus, ['draft', 'revision_requested'], true),
            'canStartCycleReview' => $isCycleReport && $canReview && $workingStatus === 'submitted_for_review',
            'canRequestCycleRevision' => $isCycleReport && $canReview && $workingStatus === 'under_review',
            'canApproveCycleReport' => $isCycleReport && $canReview && $workingStatus === 'under_review',
            'canPublishCycleReport' => $isCycleReport && $isPublisher && $workingStatus === 'approved',
            'canBeginCycleRevision' => $isCycleReport && $isPublisher && $workingStatus === 'published',
            'canArchiveCycleReport' => $isCycleReport && $isPublisher && $workingStatus === 'published',
            'isParentView' => $user->role === Role::PARENT,
        ]);
    }

    /**
     * @return array<int, array{cycle: ReportCycle, report: ?Report, eligibility: ?ReportEligibility}>
     */
    private function cycleRowsForStudent(Student $student): array
    {
        $enrollments = $student->childEnrollments()->get();
        if ($enrollments->isEmpty()) {
            return [];
        }

        $enrollmentIds = $enrollments->pluck('id')->all();
        $levelIds = $enrollments->pluck('class_level_id')->unique()->values()->all();
        $reports = Report::query()
            ->where('student_id', $student->id)
            ->whereNotNull('report_cycle_id')
            ->get()
            ->keyBy('report_cycle_id');
        $eligibilities = ReportEligibility::query()
            ->whereIn('child_enrollment_id', $enrollmentIds)
            ->latest('evaluated_at')
            ->get()
            ->groupBy('report_cycle_id')
            ->map(fn ($rows) => $rows->first());

        return ReportCycle::query()
            ->with('reportPolicy')
            ->whereHas('reportPolicy', fn ($query) => $query->whereIn('class_level_id', $levelIds))
            ->orderByDesc('cutoff_date')
            ->get()
            ->filter(function (ReportCycle $cycle) use ($enrollments): bool {
                return $enrollments->contains(function ($enrollment) use ($cycle): bool {
                    if ((int) $enrollment->class_level_id !== (int) $cycle->reportPolicy->class_level_id) {
                        return false;
                    }

                    return $enrollment->starts_on->toDateString() <= $cycle->cutoff_date->toDateString()
                        && ($enrollment->ends_on === null
                            || $enrollment->ends_on->toDateString() >= $cycle->window_start->toDateString());
                });
            })
            ->map(fn (ReportCycle $cycle): array => [
                'cycle' => $cycle,
                'report' => $reports->get($cycle->id),
                'eligibility' => $eligibilities->get($cycle->id),
            ])
            ->values()
            ->all();
    }

    private function termFromRequest(Request $request): Term
    {
        if ($request->integer('term_id') > 0) {
            return Term::query()->with('academicYear')->findOrFail($request->integer('term_id'));
        }

        return Term::query()
            ->with('academicYear')
            ->where('is_current', true)
            ->firstOrFail();
    }
}
