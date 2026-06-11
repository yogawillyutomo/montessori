<?php

namespace App\Http\Controllers\Alpha;

use App\Http\Controllers\Alpha\Concerns\ProvidesAlphaShell;
use App\Http\Controllers\Controller;
use App\Models\Attendance;
use App\Models\DevelopmentArea;
use App\Models\IlpPlan;
use App\Models\Report;
use App\Models\SchoolClass;
use App\Models\Student;
use App\Models\Teacher;
use App\Models\Term;
use App\Services\Alpha\AccessScopeService;
use App\Support\Alpha\Role;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class ReportController extends Controller
{
    use ProvidesAlphaShell;

    public function index(Request $request): View
    {
        $user = $request->user();
        $scope = app(AccessScopeService::class);
        $currentTerm = Term::query()->where('is_current', true)->with('academicYear')->first();
        $startsOn = $request->date('starts_on') ?? $currentTerm?->starts_on;
        $endsOn = $request->date('ends_on') ?? $currentTerm?->ends_on;
        $classId = $request->integer('school_class_id') ?: null;
        $accessibleStudentIds = $user ? $scope->accessibleStudentIds($user) : [];
        $teacher = $user ? $scope->teacherFor($user) : null;
        $canUseTeacherFilter = $user && $scope->hasGlobalDataAccess($user);
        $reportTeacherId = $teacher?->id ?? ($canUseTeacherFilter ? ($request->integer('teacher_id') ?: null) : null);
        $reportStatus = $request->string('status')->toString() ?: null;
        $reportSearch = $request->string('q')->trim()->toString() ?: null;
        $reportStudentIds = $reportTeacherId
            ? $scope->studentIdsScheduledWithTeacher($reportTeacherId)
            : $accessibleStudentIds;
        $isParent = $user?->role === Role::PARENT;

        return view('alpha.reports', [
            ...$this->shell($request, 'reports'),
            'reports' => Report::query()
                ->with(['student.schoolClass', 'student.guardian', 'term.academicYear', 'homeroomTeacher'])
                ->whereIn('student_id', $reportStudentIds)
                ->when($classId, fn ($query) => $query->whereHas('student', fn ($studentQuery) => $studentQuery->where('school_class_id', $classId)))
                ->when($isParent, fn ($query) => $query->where('status', 'published'))
                ->when(! $isParent && $reportStatus, fn ($query) => $query->where('status', $reportStatus))
                ->when($reportSearch, fn ($query) => $query->whereHas('student', function ($studentQuery) use ($reportSearch): void {
                    $studentQuery
                        ->where('name', 'like', "%{$reportSearch}%")
                        ->orWhere('code', 'like', "%{$reportSearch}%");
                }))
                ->orderByRaw("case status when 'draft' then 1 when 'reviewed' then 2 when 'approved' then 3 when 'published' then 4 when 'empty' then 5 else 6 end")
                ->orderByDesc('generated_at')
                ->get(),
            'currentTerm' => $currentTerm,
            'classes' => SchoolClass::naturalSort(
                SchoolClass::query()
                    ->with('classLevel')
                    ->whereHas('students', fn ($studentQuery) => $studentQuery->whereIn('students.id', $reportStudentIds))
                    ->get()
            ),
            'teachers' => Teacher::query()
                ->where('is_active', true)
                ->when(! $canUseTeacherFilter, fn ($query) => $query->whereKey($teacher?->id ?? 0))
                ->orderBy('name')
                ->get(),
            'reportFilters' => [
                'q' => $reportSearch,
                'status' => $isParent ? 'published' : $reportStatus,
                'teacher_id' => $reportTeacherId,
                'school_class_id' => $classId,
            ],
            'isTeacherScoped' => $teacher !== null,
            'attendanceFilters' => [
                'starts_on' => $startsOn?->toDateString(),
                'ends_on' => $endsOn?->toDateString(),
                'school_class_id' => $classId,
            ],
            'attendanceRecap' => $this->attendanceRecap($startsOn?->toDateString(), $endsOn?->toDateString(), $classId, $reportStudentIds),
            'canGenerateReport' => $user ? $scope->canGenerateReport($user) : false,
            'canUseTeacherFilter' => $canUseTeacherFilter,
            'isParentView' => $isParent,
        ]);
    }

    public function show(Request $request, Report $report): View
    {
        $report->load(['student.guardian', 'student.schoolClass.classLevel', 'term.academicYear', 'homeroomTeacher']);
        $scope = app(AccessScopeService::class);

        abort_if(! $request->user() || ! $scope->canViewReport($request->user(), $report), 403);

        $summary = $report->summary ?? [];

        if (! isset($summary['rubric'], $summary['biodata'], $summary['attendance'], $summary['ilp_plans'])) {
            $summary = $this->buildReportSummary($report->student, $report->term);
        }

        return view('alpha.report-show', [
            ...$this->shell($request, 'reports'),
            'report' => $report,
            'summary' => $summary,
            'areas' => collect($summary['areas'] ?? [])->values(),
            'rubric' => collect($summary['rubric'] ?? [])->values(),
            'ilpPlans' => collect($summary['ilp_plans'] ?? [])->values(),
            'attendance' => $summary['attendance'] ?? [],
            'biodata' => $summary['biodata'] ?? [],
        ]);
    }

    public function generate(Request $request): RedirectResponse
    {
        $scope = app(AccessScopeService::class);
        abort_if(! $request->user() || ! $scope->canGenerateReport($request->user()), 403);

        $term = Term::query()->where('is_current', true)->firstOrFail();
        $teacher = $scope->teacherFor($request->user()) ?? Teacher::query()->orderBy('id')->first();
        $accessibleStudentIds = $scope->accessibleStudentIds($request->user());

        Student::query()
            ->with(['guardian', 'schoolClass.classLevel'])
            ->whereIn('id', $accessibleStudentIds)
            ->each(function (Student $student) use ($term, $teacher): void {
                $summary = $this->buildReportSummary($student, $term);

                Report::query()->updateOrCreate(
                    [
                        'student_id' => $student->id,
                        'term_id' => $term->id,
                    ],
                    [
                        'homeroom_teacher_id' => $teacher?->id,
                        'status' => $summary['observation_count'] > 0 ? 'draft' : 'empty',
                        'summary' => $summary,
                        'teacher_narrative' => $this->draftNarrative($student, $summary),
                        'generated_at' => now(),
                    ]
                );
            });

        return back()->with('status', 'Draft rapor berhasil digenerate ulang dari observasi.');
    }

    /**
     * @return array<string, mixed>
     */
    private function buildReportSummary(Student $student, ?Term $term = null): array
    {
        $student->loadMissing(['guardian', 'schoolClass.classLevel']);

        $levelName = $student->schoolClass?->classLevel?->name ?? $student->schoolClass?->level;
        $latestObservations = $student->observations()
            ->with(['indicator.developmentArea', 'teacher'])
            ->where('status', '!=', 'archived')
            ->when($term?->starts_on, fn ($query) => $query->whereDate('observed_on', '>=', $term->starts_on))
            ->when($term?->ends_on, fn ($query) => $query->whereDate('observed_on', '<=', $term->ends_on))
            ->orderBy('observed_on')
            ->orderBy('id')
            ->get()
            ->groupBy('indicator_id')
            ->map(fn ($observations) => $observations->last());

        $areas = DevelopmentArea::query()
            ->with(['indicators' => function ($query) use ($levelName): void {
                $query
                    ->where('is_active', true)
                    ->when($levelName, function ($indicatorQuery) use ($levelName): void {
                        $indicatorQuery->where(function ($levelQuery) use ($levelName): void {
                            $levelQuery
                                ->whereNull('level')
                                ->orWhere('level', '')
                                ->orWhere('level', 'like', "%{$levelName}%");
                        });
                    })
                    ->orderBy('code');
            }])
            ->orderBy('sort_order')
            ->get()
            ->map(function (DevelopmentArea $area) use ($latestObservations): array {
                $indicators = $area->indicators->map(function ($indicator) use ($latestObservations): array {
                    $observation = $latestObservations->get($indicator->id);
                    $level = $observation?->level ?? $observation?->status;
                    $status = $this->observationStatusMeta($level, $observation?->score);

                    return [
                        'id' => $indicator->id,
                        'code' => $indicator->code,
                        'sub_area' => $indicator->sub_area,
                        'description' => $indicator->description,
                        'level' => $indicator->level,
                        'observed_on' => $observation?->observed_on?->toDateString(),
                        'note' => $observation?->note,
                        'score' => (int) ($observation?->score ?? 0),
                        'status' => $level ?? 'not_observed',
                        'needs_follow_up' => (bool) ($observation?->needs_follow_up ?? false),
                        ...$status,
                    ];
                });

                $observedIndicators = $indicators->where('observed', true);
                $subAreas = $indicators
                    ->groupBy('sub_area')
                    ->map(function ($rows, string $subArea) use ($area): array {
                        $observedRows = $rows->where('observed', true);
                        $score = $observedRows->count() > 0 ? (int) round($observedRows->avg('score')) : 0;
                        $status = $this->statusMetaFromScore($score, $observedRows->count() > 0);

                        return [
                            'area' => $area->name,
                            'sub_area' => $subArea,
                            'header' => "{$area->name}: {$subArea}",
                            'score' => $score,
                            'observed' => $observedRows->count(),
                            'needs_support' => $observedRows->where('needs_follow_up', true)->count(),
                            'indicators' => $rows->values()->all(),
                            ...$status,
                        ];
                    })
                    ->values();

                return [
                    'id' => $area->id,
                    'name' => $area->name,
                    'color' => $area->color,
                    'score' => $observedIndicators->count() > 0 ? (int) round($observedIndicators->avg('score')) : 0,
                    'observed' => $observedIndicators->count(),
                    'needs_support' => $observedIndicators->where('needs_follow_up', true)->count(),
                    'sub_areas' => $subAreas->all(),
                    'indicators' => $indicators->values()->all(),
                ];
            })
            ->values()
            ->all();

        $allObservations = $student->observations()
            ->where('status', '!=', 'archived')
            ->when($term?->starts_on, fn ($query) => $query->whereDate('observed_on', '>=', $term->starts_on))
            ->when($term?->ends_on, fn ($query) => $query->whereDate('observed_on', '<=', $term->ends_on));

        $rubric = collect($areas)->flatMap(fn (array $area) => $area['sub_areas'])->values()->all();

        return [
            'biodata' => $this->studentBiodata($student),
            'areas' => $areas,
            'rubric' => $rubric,
            'observation_count' => (clone $allObservations)->count(),
            'needs_support_count' => (clone $allObservations)->where('needs_follow_up', true)->count(),
            'ilp_plans' => $this->ilpSummaryForStudent($student, $term),
            'attendance' => $this->attendanceSummaryForStudent(
                $student,
                $term?->starts_on?->toDateString(),
                $term?->ends_on?->toDateString()
            ),
            'generated_from' => 'observations',
            'term' => [
                'name' => $term?->name,
                'academic_year' => $term?->academicYear?->name,
                'starts_on' => $term?->starts_on?->toDateString(),
                'ends_on' => $term?->ends_on?->toDateString(),
            ],
        ];
    }

    /**
     * @return array<string, string|null>
     */
    private function studentBiodata(Student $student): array
    {
        return [
            'name' => $student->name,
            'code' => $student->code,
            'gender' => $student->gender,
            'birth_place' => $student->birth_place,
            'birth_date' => $student->birth_date?->toDateString(),
            'age' => $student->age_label,
            'class' => $student->schoolClass?->name,
            'level' => $student->schoolClass?->classLevel?->name ?? $student->schoolClass?->level,
            'guardian_name' => $student->guardian?->name,
            'guardian_relationship' => $student->guardian?->relationship,
            'guardian_phone' => $student->guardian?->phone,
            'address' => $student->guardian?->address,
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function ilpSummaryForStudent(Student $student, ?Term $term): array
    {
        return IlpPlan::query()
            ->with(['indicator.developmentArea'])
            ->where('student_id', $student->id)
            ->when($term, fn ($query) => $query->where(function ($termQuery) use ($term): void {
                $termQuery
                    ->where('term_id', $term->id)
                    ->orWhere(function ($dateQuery) use ($term): void {
                        $dateQuery
                            ->whereDate('starts_on', '<=', $term->ends_on)
                            ->whereDate('ends_on', '>=', $term->starts_on);
                    });
            }))
            ->orderByRaw("case status when 'draft' then 1 when 'in_progress' then 2 when 'completed' then 3 else 4 end")
            ->orderByDesc('updated_at')
            ->get()
            ->map(fn (IlpPlan $plan): array => [
                'id' => $plan->id,
                'status' => $plan->status,
                'status_label' => [
                    'draft' => 'Draft',
                    'in_progress' => 'Berjalan',
                    'completed' => 'Selesai',
                    'cancelled' => 'Dibatalkan',
                ][$plan->status] ?? $plan->status,
                'area' => $plan->indicator?->developmentArea?->name,
                'indicator_code' => $plan->indicator?->code,
                'indicator' => $plan->indicator?->description,
                'sub_area' => $plan->indicator?->sub_area,
                'analysis' => $plan->analysis,
                'target' => $plan->target,
                'follow_up' => $plan->follow_up,
                'starts_on' => $plan->starts_on?->toDateString(),
                'ends_on' => $plan->ends_on?->toDateString(),
            ])
            ->values()
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    private function observationStatusMeta(?string $status, ?int $score): array
    {
        return match ($status) {
            'exceeding' => ['code' => 'MH', 'label' => 'Melebihi Harapan', 'tone' => 'exceeding', 'observed' => true],
            'independent', 'achieved' => ['code' => 'M', 'label' => 'Mandiri', 'tone' => 'independent', 'observed' => true],
            'developing' => ['code' => 'B', 'label' => 'Berkembang', 'tone' => 'developing', 'observed' => true],
            'emerging', 'needs_support' => ['code' => 'MB', 'label' => 'Mulai Berkembang', 'tone' => 'emerging', 'observed' => true],
            default => $this->statusMetaFromScore((int) ($score ?? 0), false),
        };
    }

    /**
     * @return array<string, mixed>
     */
    private function statusMetaFromScore(int $score, bool $observed): array
    {
        if (! $observed) {
            return ['code' => '-', 'label' => 'Belum diamati', 'tone' => 'muted', 'observed' => false];
        }

        if ($score >= 95) {
            return ['code' => 'MH', 'label' => 'Melebihi Harapan', 'tone' => 'exceeding', 'observed' => true];
        }

        if ($score >= 80) {
            return ['code' => 'M', 'label' => 'Mandiri', 'tone' => 'independent', 'observed' => true];
        }

        if ($score >= 50) {
            return ['code' => 'B', 'label' => 'Berkembang', 'tone' => 'developing', 'observed' => true];
        }

        return ['code' => 'MB', 'label' => 'Mulai Berkembang', 'tone' => 'emerging', 'observed' => true];
    }

    /**
     * @param  array<string, mixed>  $summary
     */
    private function draftNarrative(Student $student, array $summary): string
    {
        if (($summary['observation_count'] ?? 0) === 0) {
            return 'Belum ada observasi pada periode ini.';
        }

        $bestArea = collect($summary['areas'] ?? [])->where('observed', '>', 0)->sortByDesc('score')->first();
        $needsSupport = (int) ($summary['needs_support_count'] ?? 0);
        $attendanceRate = $summary['attendance']['attendance_rate'] ?? 0;

        $opening = $bestArea
            ? "{$student->name} menunjukkan perkembangan paling menonjol pada area {$bestArea['name']}."
            : "{$student->name} sudah memiliki catatan observasi pada periode ini.";

        $support = $needsSupport > 0
            ? "Terdapat {$needsSupport} catatan yang perlu stimulasi lanjutan dan sudah disiapkan sebagai bahan ILP/remedial."
            : 'Belum ada indikator yang masuk kategori perlu stimulasi intensif.';

        return "{$opening} Rata-rata kehadiran tercatat {$attendanceRate}%. {$support} Narasi ini masih berupa draft otomatis dan perlu review guru sebelum dibagikan ke orangtua.";
    }

    private function teacherForRequest(Request $request): ?Teacher
    {
        if ($request->user()?->role !== 'teacher') {
            return null;
        }

        return Teacher::query()->where('user_id', $request->user()->id)->first();
    }

    /**
     * @return array<int>|null
     */
    private function accessibleStudentIds(Request $request): ?array
    {
        $teacher = $this->teacherForRequest($request);

        if (! $teacher) {
            return null;
        }

        return Student::query()
            ->where(fn ($query) => $this->scopeStudentsScheduledWithTeacher($query, $teacher->id))
            ->pluck('id')
            ->all();
    }

    /**
     * @return array<int>
     */
    private function studentIdsScheduledWithTeacher(int $teacherId): array
    {
        return Student::query()
            ->where(fn ($query) => $this->scopeStudentsScheduledWithTeacher($query, $teacherId))
            ->pluck('id')
            ->all();
    }

    private function scopeStudentsScheduledWithTeacher($query, int $teacherId): void
    {
        $query
            ->whereHas('weeklySchedules', fn ($scheduleQuery) => $scheduleQuery->where('teacher_id', $teacherId))
            ->orWhereHas('classSessions', fn ($sessionQuery) => $sessionQuery->where('teacher_id', $teacherId));
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function attendanceRecap(?string $startsOn, ?string $endsOn, ?int $classId, ?array $studentIds = null): array
    {
        return Student::query()
            ->with(['schoolClass', 'guardian'])
            ->when($studentIds !== null, fn ($query) => $query->whereIn('id', $studentIds))
            ->when($classId, fn ($query) => $query->where('school_class_id', $classId))
            ->orderBy('name')
            ->get()
            ->map(fn (Student $student): array => [
                'student' => $student,
                'summary' => $this->attendanceSummaryForStudent($student, $startsOn, $endsOn),
            ])
            ->values()
            ->all();
    }

    /**
     * @return array<string, int|float>
     */
    private function attendanceSummaryForStudent(Student $student, ?string $startsOn, ?string $endsOn): array
    {
        $baseQuery = Attendance::query()
            ->where('student_id', $student->id)
            ->whereNotNull('marked_at')
            ->whereHas('classSession', function ($query) use ($startsOn, $endsOn): void {
                $query
                    ->when($startsOn, fn ($sessionQuery) => $sessionQuery->whereDate('session_date', '>=', $startsOn))
                    ->when($endsOn, fn ($sessionQuery) => $sessionQuery->whereDate('session_date', '<=', $endsOn));
            });

        $counts = (clone $baseQuery)
            ->selectRaw('status, count(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status');

        $present = (int) ($counts['present'] ?? 0);
        $late = (int) ($counts['late'] ?? 0);
        $sick = (int) ($counts['sick'] ?? 0);
        $excused = (int) ($counts['excused'] ?? 0);
        $absent = (int) ($counts['absent'] ?? 0);
        $recorded = $present + $late + $sick + $excused + $absent;
        $attendanceRate = $recorded > 0 ? round((($present + $late) / $recorded) * 100, 1) : 0;

        return [
            'recorded' => $recorded,
            'present' => $present,
            'late' => $late,
            'sick' => $sick,
            'excused' => $excused,
            'absent' => $absent,
            'attendance_rate' => $attendanceRate,
        ];
    }
}
