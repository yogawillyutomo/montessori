<?php

namespace App\Http\Controllers\Alpha;

use App\Http\Controllers\Alpha\Concerns\ProvidesAlphaShell;
use App\Http\Controllers\Controller;
use App\Models\AcademicYear;
use App\Models\ClassLevel;
use App\Models\DevelopmentArea;
use App\Models\Guardian;
use App\Models\SchoolClass;
use App\Models\Student;
use App\Models\Teacher;
use App\Models\Term;
use App\Services\Alpha\ImportTemplateService;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

class MasterPageController extends Controller
{
    use ProvidesAlphaShell;

    public function academicYears(Request $request): View
    {
        return $this->masterView($request, 'academic-years', 'master.academic-years');
    }

    public function classes(Request $request): View
    {
        return $this->masterView($request, 'classes', 'master.classes');
    }

    public function levels(Request $request): View
    {
        return $this->masterView($request, 'levels', 'master.levels');
    }

    public function students(Request $request): View
    {
        return $this->masterView($request, 'students', 'master.students');
    }

    public function teachers(Request $request): View
    {
        return $this->masterView($request, 'teachers', 'master.teachers');
    }

    public function curriculum(Request $request): View
    {
        return $this->masterView($request, 'curriculum', 'master.curriculum');
    }

    public function downloadImportTemplate(string $type, ImportTemplateService $templates): StreamedResponse
    {
        return $templates->download($type);
    }

    private function masterView(Request $request, string $masterSection, string $activeMenu): View
    {
        $classes = SchoolClass::naturalSort(
            SchoolClass::query()
                ->with('classLevel')
                ->withCount(['students', 'weeklySchedules'])
                ->get()
        );
        $classLevels = ClassLevel::query()->withCount('schoolClasses')->orderBy('sequence')->orderBy('name')->get();
        $students = Student::query()
            ->with(['schoolClass.classLevel', 'guardian.students.schoolClass'])
            ->orderBy('code')
            ->get();
        $teachers = Teacher::query()->withCount(['weeklySchedules', 'classSessions'])->orderBy('name')->get();
        $areas = DevelopmentArea::query()->with('indicators')->orderBy('sort_order')->get();
        $academicYears = AcademicYear::query()->with('terms')->orderByDesc('starts_on')->get();
        $currentTerm = Term::query()->where('is_current', true)->with('academicYear')->first();

        return view('alpha.master', [
            ...$this->shell($request, $activeMenu),
            'masterSection' => $masterSection,
            'stats' => [
                'academic_years' => $academicYears->count(),
                'classes' => $classes->count(),
                'levels' => $classLevels->count(),
                'students' => $students->count(),
                'guardians' => Guardian::query()->count(),
                'teachers' => $teachers->count(),
                'indicators' => $areas->sum(fn (DevelopmentArea $area): int => $area->indicators->count()),
            ],
            'academicYears' => $academicYears,
            'currentTerm' => $currentTerm,
            'classes' => $classes,
            'classLevels' => $classLevels,
            'students' => $students,
            'guardians' => Guardian::query()->with(['students.schoolClass'])->withCount('students')->orderBy('name')->get(),
            'teachers' => $teachers,
            'areas' => $areas,
        ]);
    }
}
