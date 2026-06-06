<?php

namespace App\Http\Controllers;

use App\Models\ClassSession;
use App\Models\DevelopmentArea;
use App\Models\IlpPlan;
use App\Models\Indicator;
use App\Models\Observation;
use App\Models\Report;
use App\Models\SchoolClass;
use App\Models\Student;
use App\Models\Teacher;
use App\Models\Term;
use App\Models\WeeklySchedule;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\View\View;

class AlphaController extends Controller
{
    /**
     * @var array<string, string>
     */
    private array $roles = [
        'super_admin' => 'Super Admin',
        'admin' => 'Admin',
        'teacher' => 'Guru',
        'parent' => 'Orangtua',
    ];

    public function dashboard(Request $request): View
    {
        $stats = [
            'classes' => SchoolClass::query()->count(),
            'students' => Student::query()->where('status', 'active')->count(),
            'weekly_schedules' => WeeklySchedule::query()->where('is_active', true)->count(),
            'draft_reports' => Report::query()->where('status', 'draft')->count(),
            'needs_support' => Observation::query()->where('status', 'needs_support')->count(),
        ];

        $classes = SchoolClass::query()
            ->withCount(['students', 'weeklySchedules'])
            ->orderBy('name')
            ->get();

        $sessions = ClassSession::query()
            ->with(['schoolClass', 'teacher', 'students'])
            ->orderByDesc('session_date')
            ->orderBy('starts_at')
            ->limit(6)
            ->get();

        $areaScores = $this->areaScores();

        $needsSupport = Observation::query()
            ->with(['student.schoolClass', 'indicator.developmentArea', 'teacher'])
            ->where('status', 'needs_support')
            ->latest('observed_on')
            ->limit(5)
            ->get();

        return view('alpha.dashboard', [
            ...$this->shell($request, 'dashboard'),
            'stats' => $stats,
            'classes' => $classes,
            'sessions' => $sessions,
            'areaScores' => $areaScores,
            'needsSupport' => $needsSupport,
        ]);
    }

    public function master(Request $request): View
    {
        return view('alpha.master', [
            ...$this->shell($request, 'master'),
            'classes' => SchoolClass::query()->withCount(['students', 'weeklySchedules'])->orderBy('name')->get(),
            'students' => Student::query()->with(['schoolClass', 'guardian'])->orderBy('code')->get(),
            'teachers' => Teacher::query()->withCount(['weeklySchedules', 'classSessions'])->orderBy('name')->get(),
            'areas' => DevelopmentArea::query()->with('indicators')->orderBy('sort_order')->get(),
        ]);
    }

    public function process(Request $request): View
    {
        return view('alpha.process', [
            ...$this->shell($request, 'process'),
            'schedules' => WeeklySchedule::query()
                ->with(['schoolClass', 'teacher', 'students'])
                ->orderBy('day_of_week')
                ->orderBy('starts_at')
                ->get(),
            'sessions' => ClassSession::query()
                ->with(['schoolClass', 'teacher', 'students'])
                ->orderByDesc('session_date')
                ->orderBy('starts_at')
                ->limit(10)
                ->get(),
            'observations' => Observation::query()
                ->with(['student.schoolClass', 'indicator.developmentArea', 'teacher', 'classSession'])
                ->latest('observed_on')
                ->limit(12)
                ->get(),
            'ilpPlans' => IlpPlan::query()
                ->with(['student.schoolClass', 'indicator.developmentArea'])
                ->latest()
                ->limit(8)
                ->get(),
            'students' => Student::query()->orderBy('name')->get(),
            'teachers' => Teacher::query()->orderBy('name')->get(),
            'indicators' => Indicator::query()->with('developmentArea')->orderBy('code')->get(),
        ]);
    }

    public function reports(Request $request): View
    {
        return view('alpha.reports', [
            ...$this->shell($request, 'reports'),
            'reports' => Report::query()
                ->with(['student.schoolClass', 'student.guardian', 'term.academicYear', 'homeroomTeacher'])
                ->orderByRaw("case status when 'draft' then 1 when 'empty' then 2 else 3 end")
                ->orderByDesc('generated_at')
                ->get(),
            'currentTerm' => Term::query()->where('is_current', true)->with('academicYear')->first(),
        ]);
    }

    public function createSession(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'weekly_schedule_id' => ['required', 'exists:weekly_schedules,id'],
            'session_date' => ['required', 'date'],
        ]);

        $schedule = WeeklySchedule::query()->with('students')->findOrFail($validated['weekly_schedule_id']);

        $session = ClassSession::query()->firstOrCreate(
            [
                'weekly_schedule_id' => $schedule->id,
                'session_date' => $validated['session_date'],
            ],
            [
                'school_class_id' => $schedule->school_class_id,
                'teacher_id' => $schedule->teacher_id,
                'starts_at' => $schedule->starts_at,
                'ends_at' => $schedule->ends_at,
                'topic' => $schedule->topic,
                'status' => 'planned',
            ]
        );

        $studentIds = $schedule->students->pluck('id')->all();
        $session->students()->syncWithoutDetaching($studentIds);

        foreach ($studentIds as $studentId) {
            $session->attendances()->firstOrCreate([
                'student_id' => $studentId,
            ], [
                'status' => 'present',
            ]);
        }

        return back()->with('status', 'Sesi berhasil dibuat dari jadwal mingguan.');
    }

    public function storeObservation(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'class_session_id' => ['required', 'exists:class_sessions,id'],
            'student_id' => ['required', 'exists:students,id'],
            'indicator_id' => ['required', 'exists:indicators,id'],
            'teacher_id' => ['required', 'exists:teachers,id'],
            'observed_on' => ['required', 'date'],
            'status' => ['required', 'in:achieved,emerging,needs_support,not_observed'],
            'note' => ['nullable', 'string', 'max:2000'],
        ]);

        $observation = Observation::create([
            ...$validated,
            'score' => Observation::STATUS_SCORES[$validated['status']],
        ]);

        if ($observation->status === 'needs_support') {
            $term = Term::query()->where('is_current', true)->first();

            IlpPlan::query()->firstOrCreate(
                [
                    'student_id' => $observation->student_id,
                    'indicator_id' => $observation->indicator_id,
                    'term_id' => $term?->id,
                    'status' => 'draft',
                ],
                [
                    'trigger_observation_id' => $observation->id,
                    'analysis' => 'Anak masih memerlukan stimulasi dan pendampingan terarah pada indikator ini.',
                    'target' => 'Mampu melakukan indikator dengan bantuan minimal dalam 2-4 minggu.',
                    'follow_up' => 'Pendampingan melalui kegiatan bermain terarah dan komunikasi dengan orangtua.',
                    'starts_on' => Carbon::parse($observation->observed_on)->addDay(),
                    'ends_on' => Carbon::parse($observation->observed_on)->addWeeks(4),
                ]
            );
        }

        return back()->with('status', 'Observasi tersimpan. Jika perlu stimulasi, ILP otomatis dibuat.');
    }

    public function generateReports(Request $request): RedirectResponse
    {
        $term = Term::query()->where('is_current', true)->firstOrFail();
        $teacher = Teacher::query()->orderBy('id')->first();

        Student::query()->with(['observations.indicator.developmentArea'])->each(function (Student $student) use ($term, $teacher): void {
            $summary = $this->buildReportSummary($student);
            $bestArea = collect($summary['areas'])->sortByDesc('score')->first();

            Report::query()->updateOrCreate(
                [
                    'student_id' => $student->id,
                    'term_id' => $term->id,
                ],
                [
                    'homeroom_teacher_id' => $teacher?->id,
                    'status' => $summary['observation_count'] > 0 ? 'draft' : 'empty',
                    'summary' => $summary,
                    'teacher_narrative' => $summary['observation_count'] > 0
                        ? "{$student->name} menunjukkan perkembangan yang paling menonjol pada area {$bestArea['name']}. Narasi ini masih berupa draft otomatis dan perlu review guru."
                        : 'Belum ada observasi pada periode ini.',
                    'generated_at' => now(),
                ]
            );
        });

        return back()->with('status', 'Draft rapor berhasil digenerate ulang dari observasi.');
    }

    /**
     * @return array<string, mixed>
     */
    private function shell(Request $request, string $activeMenu): array
    {
        $role = $request->query('role', 'admin');

        if (! array_key_exists($role, $this->roles)) {
            $role = 'admin';
        }

        return [
            'activeMenu' => $activeMenu,
            'activeRole' => $role,
            'roles' => $this->roles,
            'roleLabel' => $this->roles[$role],
            'statusLabels' => $this->statusLabels(),
            'dayLabels' => $this->dayLabels(),
        ];
    }

    /**
     * @return array<int, array{name: string, score: int, observed: int}>
     */
    private function areaScores(): array
    {
        return DevelopmentArea::query()
            ->with('indicators.observations')
            ->orderBy('sort_order')
            ->get()
            ->map(function (DevelopmentArea $area): array {
                $observations = $area->indicators->flatMap->observations;

                return [
                    'name' => $area->name,
                    'score' => $observations->count() > 0 ? (int) round($observations->avg('score')) : 0,
                    'observed' => $observations->count(),
                ];
            })
            ->values()
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    private function buildReportSummary(Student $student): array
    {
        $areas = DevelopmentArea::query()
            ->with(['indicators.observations' => fn ($query) => $query->where('student_id', $student->id)])
            ->orderBy('sort_order')
            ->get()
            ->map(function (DevelopmentArea $area): array {
                $observations = $area->indicators->flatMap->observations;

                return [
                    'name' => $area->name,
                    'score' => $observations->count() > 0 ? (int) round($observations->avg('score')) : 0,
                    'observed' => $observations->count(),
                    'needs_support' => $observations->where('status', 'needs_support')->count(),
                ];
            })
            ->values()
            ->all();

        return [
            'areas' => $areas,
            'observation_count' => $student->observations()->count(),
            'needs_support_count' => $student->observations()->where('status', 'needs_support')->count(),
            'generated_from' => 'observations',
        ];
    }

    /**
     * @return array<string, string>
     */
    private function statusLabels(): array
    {
        return [
            'achieved' => 'Tercapai',
            'emerging' => 'Berkembang',
            'needs_support' => 'Perlu stimulasi',
            'not_observed' => 'Belum diamati',
            'planned' => 'Direncanakan',
            'completed' => 'Selesai',
            'draft' => 'Draft',
            'empty' => 'Belum ada data',
            'published' => 'Publish',
        ];
    }

    /**
     * @return array<int, string>
     */
    private function dayLabels(): array
    {
        return [
            1 => 'Senin',
            2 => 'Selasa',
            3 => 'Rabu',
            4 => 'Kamis',
            5 => 'Jumat',
            6 => 'Sabtu',
            7 => 'Minggu',
        ];
    }
}
