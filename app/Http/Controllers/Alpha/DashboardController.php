<?php

namespace App\Http\Controllers\Alpha;

use App\Http\Controllers\Alpha\Concerns\ProvidesAlphaShell;
use App\Http\Controllers\Controller;
use App\Models\ClassSession;
use App\Models\DevelopmentArea;
use App\Models\Observation;
use App\Models\Report;
use App\Models\SchoolClass;
use App\Models\Student;
use App\Models\WeeklySchedule;
use Illuminate\Http\Request;
use Illuminate\View\View;

class DashboardController extends Controller
{
    use ProvidesAlphaShell;

    public function __invoke(Request $request): View
    {
        return view('alpha.dashboard', [
            ...$this->shell($request, 'dashboard'),
            'stats' => [
                'classes' => SchoolClass::query()->count(),
                'students' => Student::query()->where('status', 'active')->count(),
                'weekly_schedules' => WeeklySchedule::query()->where('is_active', true)->count(),
                'draft_reports' => Report::query()->where('status', 'draft')->count(),
                'needs_support' => Observation::query()->where('status', 'needs_support')->count(),
            ],
            'classes' => SchoolClass::query()
                ->with('classLevel')
                ->withCount(['students', 'weeklySchedules'])
                ->get()
                ->pipe(fn ($classes) => SchoolClass::naturalSort($classes)),
            'sessions' => ClassSession::query()
                ->with(['schoolClass', 'teacher', 'students'])
                ->orderByDesc('session_date')
                ->orderBy('starts_at')
                ->limit(6)
                ->get(),
            'areaScores' => $this->areaScores(),
            'needsSupport' => Observation::query()
                ->with(['student.schoolClass', 'indicator.developmentArea', 'teacher'])
                ->where('status', 'needs_support')
                ->latest('observed_on')
                ->limit(5)
                ->get(),
        ]);
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
}
