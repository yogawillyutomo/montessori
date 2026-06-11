<?php

namespace App\Http\Controllers\Alpha;

use App\Http\Controllers\Alpha\Concerns\ProvidesAlphaShell;
use App\Http\Controllers\Controller;
use App\Models\ClassSession;
use App\Models\DevelopmentArea;
use App\Models\Observation;
use App\Models\Report;
use App\Models\SchoolClass;
use App\Models\WeeklySchedule;
use App\Services\Alpha\AccessScopeService;
use Illuminate\Http\Request;
use Illuminate\View\View;

class DashboardController extends Controller
{
    use ProvidesAlphaShell;

    public function __invoke(Request $request): View
    {
        $user = $request->user();
        $scope = app(AccessScopeService::class);
        $studentIds = $user ? $scope->accessibleStudentIds($user) : [];
        $classIds = $user ? $scope->accessibleClassIds($user) : [];

        return view('alpha.dashboard', [
            ...$this->shell($request, 'dashboard'),
            'stats' => [
                'classes' => SchoolClass::query()->whereIn('id', $classIds)->count(),
                'students' => count($studentIds),
                'weekly_schedules' => WeeklySchedule::query()->whereIn('school_class_id', $classIds)->where('is_active', true)->count(),
                'draft_reports' => Report::query()->whereIn('student_id', $studentIds)->where('status', 'draft')->count(),
                'needs_support' => Observation::query()->whereIn('student_id', $studentIds)->where('needs_follow_up', true)->count(),
            ],
            'classes' => SchoolClass::query()
                ->with('classLevel')
                ->withCount(['students', 'weeklySchedules'])
                ->whereIn('id', $classIds)
                ->get()
                ->pipe(fn ($classes) => SchoolClass::naturalSort($classes)),
            'sessions' => ClassSession::query()
                ->with(['schoolClass', 'teacher', 'students'])
                ->whereIn('school_class_id', $classIds)
                ->when($studentIds !== [], fn ($query) => $query->whereHas('students', fn ($studentQuery) => $studentQuery->whereIn('students.id', $studentIds)))
                ->orderByDesc('session_date')
                ->orderBy('starts_at')
                ->limit(6)
                ->get(),
            'areaScores' => $this->areaScores($studentIds),
            'needsSupport' => Observation::query()
                ->with(['student.schoolClass', 'developmentArea', 'indicator.developmentArea', 'teacher'])
                ->whereIn('student_id', $studentIds)
                ->where('needs_follow_up', true)
                ->latest('observed_on')
                ->limit(5)
                ->get(),
        ]);
    }

    /**
     * @return array<int, array{name: string, score: int, observed: int}>
     */
    private function areaScores(array $studentIds): array
    {
        return DevelopmentArea::query()
            ->with(['indicators.observations' => fn ($query) => $query->whereIn('student_id', $studentIds)->where('status', '!=', 'archived')])
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
}
