<?php

namespace App\Http\Controllers\Alpha;

use App\Http\Controllers\Alpha\Concerns\ProvidesAlphaShell;
use App\Http\Controllers\Controller;
use App\Models\DevelopmentArea;
use App\Models\IlpPlan;
use App\Models\Indicator;
use App\Models\Observation;
use App\Models\SchoolClass;
use App\Models\Student;
use App\Models\Teacher;
use App\Services\Alpha\AccessScopeService;
use App\Services\Scheduling\SchedulingReadModelService;
use Illuminate\Http\Request;
use Illuminate\View\View;

class ProcessPageController extends Controller
{
    use ProvidesAlphaShell;

    public function schedules(Request $request): View
    {
        return $this->processView($request, 'schedules', 'process.schedules');
    }

    public function sessions(Request $request): View
    {
        return $this->processView($request, 'sessions', 'process.sessions');
    }

    public function observations(Request $request): View
    {
        return $this->processView($request, 'observations', 'process.observations');
    }

    public function ilp(Request $request): View
    {
        return $this->processView($request, 'ilp', 'process.ilp');
    }

    private function processView(Request $request, string $processSection, string $activeMenu): View
    {
        $scope = app(AccessScopeService::class);
        $scheduling = app(SchedulingReadModelService::class);
        $user = $request->user();
        $studentIds = $user ? $scope->accessibleStudentIds($user) : [];
        $classIds = $user ? $scope->accessibleClassIds($user) : [];
        $teacher = $user ? $scope->teacherFor($user) : null;
        $selectedSessionDate = $request->date('date')?->toDateString() ?? now()->toDateString();
        $schedules = $scheduling->schedules($classIds, $teacher);
        $sessions = $scheduling->sessions($classIds, $teacher);
        $indicators = Indicator::query()
            ->with('developmentArea')
            ->where('is_active', true)
            ->get()
            ->sort(function (Indicator $a, Indicator $b): int {
                return (($a->developmentArea?->sort_order ?? 999) <=> ($b->developmentArea?->sort_order ?? 999))
                    ?: strcmp((string) $a->sub_area, (string) $b->sub_area)
                    ?: strnatcasecmp($a->code, $b->code);
            })
            ->values();
        $monitoringSnapshots = Observation::query()
            ->whereIn('class_session_id', $sessions->pluck('id'))
            ->whereIn('indicator_id', $indicators->pluck('id'))
            ->get()
            ->groupBy(fn (Observation $observation): string => implode('|', [
                $observation->class_session_id,
                $observation->student_id,
                $observation->observed_on->toDateString(),
            ]))
            ->map(fn ($rows) => [
                'note' => $rows->firstWhere('note', '!=', null)?->note ?? '',
                'items' => $rows
                    ->mapWithKeys(fn (Observation $observation) => [
                        (string) $observation->indicator_id => [
                            'status' => $observation->level,
                            'note' => $observation->note,
                        ],
                    ])
                    ->all(),
            ])
            ->all();

        return view('alpha.process', [
            ...$this->shell($request, $activeMenu),
            'processSection' => $processSection,
            'schedules' => $schedules,
            'sessions' => $sessions,
            'selectedSessionDate' => $selectedSessionDate,
            'observations' => Observation::query()
                ->with(['student.schoolClass', 'developmentArea', 'indicator.developmentArea', 'teacher', 'classSession'])
                ->whereIn('student_id', $studentIds)
                ->where('status', '!=', 'archived')
                ->orderByDesc('observed_on')
                ->orderByDesc('id')
                ->get(),
            'ilpPlans' => IlpPlan::query()
                ->with(['student.schoolClass.classLevel', 'indicator.developmentArea', 'term', 'triggerObservation.teacher'])
                ->whereIn('student_id', $studentIds)
                ->orderByRaw("case status when 'draft' then 1 when 'in_progress' then 2 when 'completed' then 3 else 4 end")
                ->latest('updated_at')
                ->get(),
            'classes' => SchoolClass::naturalSort(SchoolClass::query()->with('classLevel')->whereIn('id', $classIds)->get()),
            'students' => Student::query()->with(['schoolClass.classLevel', 'guardian'])->whereIn('id', $studentIds)->orderBy('name')->get(),
            'teachers' => Teacher::query()
                ->when($teacher, fn ($query) => $query->whereKey($teacher->id))
                ->orderBy('name')
                ->get(),
            'developmentAreas' => DevelopmentArea::query()->orderBy('sort_order')->orderBy('name')->get(),
            'indicators' => $indicators,
            'monitoringSnapshots' => $monitoringSnapshots,
            'canManageSchedules' => in_array($user?->role, ['super_admin', 'admin'], true),
            'canWriteProcess' => in_array($user?->role, ['super_admin', 'admin', 'teacher'], true),
        ]);
    }
}
