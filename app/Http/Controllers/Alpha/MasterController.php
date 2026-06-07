<?php

namespace App\Http\Controllers\Alpha;

use App\Http\Controllers\Alpha\Concerns\ProvidesAlphaShell;
use App\Http\Controllers\Controller;
use App\Models\AcademicYear;
use App\Models\ClassLevel;
use App\Models\DevelopmentArea;
use App\Models\Guardian;
use App\Models\Indicator;
use App\Models\SchoolClass;
use App\Models\Student;
use App\Models\Teacher;
use App\Models\Term;
use App\Services\Alpha\ImportTemplateService;
use App\Services\Alpha\MasterImportService;
use App\Services\Alpha\SpreadsheetReader;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

class MasterController extends Controller
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

    public function storeClass(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'class_level_id' => ['required', 'exists:class_levels,id'],
            'name' => ['required', 'string', 'max:120'],
            'slug' => ['nullable', 'alpha_dash', 'max:120', Rule::unique('school_classes', 'slug')],
            'capacity' => ['required', 'integer', 'min:1', 'max:100'],
        ]);
        $level = ClassLevel::query()->findOrFail($validated['class_level_id']);

        SchoolClass::create([
            ...$validated,
            'slug' => $validated['slug'] ?: $this->uniqueSlug('school_classes', $validated['name']),
            'level' => $level->name,
            'age_range' => $level->age_range_label,
            'color' => $level->color,
            'is_active' => true,
        ]);

        return back()->with('status', 'Kelas master berhasil ditambahkan.');
    }

    public function duplicateClass(Request $request, SchoolClass $schoolClass): RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'slug' => ['nullable', 'alpha_dash', 'max:120', Rule::unique('school_classes', 'slug')],
        ]);

        SchoolClass::create([
            'class_level_id' => $schoolClass->class_level_id,
            'name' => $validated['name'],
            'slug' => $validated['slug'] ?: $this->uniqueSlug('school_classes', $validated['name']),
            'level' => $schoolClass->level,
            'age_range' => $schoolClass->age_range,
            'capacity' => $schoolClass->capacity,
            'color' => $schoolClass->color,
            'is_active' => true,
        ]);

        return back()->with('status', 'Kelas berhasil disalin. Nama kelas baru sudah disesuaikan.');
    }

    public function updateClass(Request $request, SchoolClass $schoolClass): RedirectResponse
    {
        $validated = $request->validate([
            'class_level_id' => ['required', 'exists:class_levels,id'],
            'name' => ['required', 'string', 'max:120'],
            'slug' => ['nullable', 'alpha_dash', 'max:120', Rule::unique('school_classes', 'slug')->ignore($schoolClass)],
            'capacity' => ['required', 'integer', 'min:1', 'max:100'],
        ]);
        $level = ClassLevel::query()->findOrFail($validated['class_level_id']);

        $schoolClass->update([
            ...$validated,
            'slug' => $validated['slug'] ?: $this->uniqueSlug('school_classes', $validated['name'], $schoolClass->id),
            'level' => $level->name,
            'age_range' => $level->age_range_label,
            'color' => $level->color,
        ]);

        return back()->with('status', 'Kelas berhasil diperbarui.');
    }

    public function toggleClass(SchoolClass $schoolClass): RedirectResponse
    {
        $schoolClass->update(['is_active' => ! $schoolClass->is_active]);

        return back()->with('status', 'Status kelas berhasil diperbarui.');
    }

    public function destroyClass(SchoolClass $schoolClass): RedirectResponse
    {
        if ($schoolClass->students()->exists() || $schoolClass->weeklySchedules()->exists() || $schoolClass->classSessions()->exists()) {
            return back()->withErrors('Kelas tidak bisa dihapus karena sudah dipakai siswa, jadwal, atau presensi.');
        }

        $schoolClass->delete();

        return back()->with('status', 'Kelas berhasil dihapus.');
    }

    public function storeLevel(Request $request): RedirectResponse
    {
        $this->normalizeAgeYearInputs($request);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:120', Rule::unique('class_levels', 'name')],
            'slug' => ['nullable', 'alpha_dash', 'max:120', Rule::unique('class_levels', 'slug')],
            'sequence' => ['required', 'integer', 'min:0', 'max:100'],
            'min_age_years' => ['nullable', 'numeric', 'min:0', 'max:20'],
            'max_age_years' => ['nullable', 'numeric', 'min:0', 'max:20', 'gte:min_age_years'],
            'color' => ['required', 'in:sage,teal,coral,blue,gold,plum'],
        ]);

        ClassLevel::create([
            'name' => $validated['name'],
            'slug' => $validated['slug'] ?: $this->uniqueSlug('class_levels', $validated['name']),
            'sequence' => $validated['sequence'],
            'min_age_months' => $this->yearsToMonths($validated['min_age_years'] ?? null),
            'max_age_months' => $this->yearsToMonths($validated['max_age_years'] ?? null),
            'color' => $validated['color'],
            'is_active' => true,
        ]);

        return back()->with('status', 'Level kelas berhasil ditambahkan.');
    }

    public function updateLevel(Request $request, ClassLevel $classLevel): RedirectResponse
    {
        $this->normalizeAgeYearInputs($request);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:120', Rule::unique('class_levels', 'name')->ignore($classLevel)],
            'slug' => ['nullable', 'alpha_dash', 'max:120', Rule::unique('class_levels', 'slug')->ignore($classLevel)],
            'sequence' => ['required', 'integer', 'min:0', 'max:100'],
            'min_age_years' => ['nullable', 'numeric', 'min:0', 'max:20'],
            'max_age_years' => ['nullable', 'numeric', 'min:0', 'max:20', 'gte:min_age_years'],
            'color' => ['required', 'in:sage,teal,coral,blue,gold,plum'],
        ]);

        $classLevel->update([
            'name' => $validated['name'],
            'slug' => $validated['slug'] ?: $this->uniqueSlug('class_levels', $validated['name'], $classLevel->id),
            'sequence' => $validated['sequence'],
            'min_age_months' => $this->yearsToMonths($validated['min_age_years'] ?? null),
            'max_age_months' => $this->yearsToMonths($validated['max_age_years'] ?? null),
            'color' => $validated['color'],
        ]);

        $classLevel->schoolClasses()->update([
            'level' => $classLevel->name,
            'age_range' => $classLevel->age_range_label,
            'color' => $classLevel->color,
        ]);

        return back()->with('status', 'Level kelas berhasil diperbarui.');
    }

    public function toggleLevel(ClassLevel $classLevel): RedirectResponse
    {
        $classLevel->update(['is_active' => ! $classLevel->is_active]);

        return back()->with('status', 'Status level berhasil diperbarui.');
    }

    public function destroyLevel(ClassLevel $classLevel): RedirectResponse
    {
        if ($classLevel->schoolClasses()->exists()) {
            return back()->withErrors('Level tidak bisa dihapus karena masih dipakai kelas.');
        }

        $classLevel->delete();

        return back()->with('status', 'Level kelas berhasil dihapus.');
    }

    public function storeAcademicYear(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:80', Rule::unique('academic_years', 'name')],
            'starts_on' => ['required', 'date'],
            'ends_on' => ['required', 'date', 'after:starts_on'],
            'is_active' => ['nullable', 'boolean'],
        ]);

        if ($request->boolean('is_active')) {
            AcademicYear::query()->update(['is_active' => false]);
        }

        AcademicYear::create([
            ...$validated,
            'is_active' => $request->boolean('is_active'),
        ]);

        return back()->with('status', 'Tahun ajaran berhasil ditambahkan.');
    }

    public function updateAcademicYear(Request $request, AcademicYear $academicYear): RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:80', Rule::unique('academic_years', 'name')->ignore($academicYear)],
            'starts_on' => ['required', 'date'],
            'ends_on' => ['required', 'date', 'after:starts_on'],
            'is_active' => ['nullable', 'boolean'],
        ]);

        if ($request->boolean('is_active')) {
            AcademicYear::query()->whereKeyNot($academicYear->id)->update(['is_active' => false]);
        }

        $academicYear->update([
            ...$validated,
            'is_active' => $request->boolean('is_active'),
        ]);

        return back()->with('status', 'Tahun ajaran berhasil diperbarui.');
    }

    public function activateAcademicYear(AcademicYear $academicYear): RedirectResponse
    {
        AcademicYear::query()->update(['is_active' => false]);
        $academicYear->update(['is_active' => true]);

        return back()->with('status', 'Tahun ajaran aktif berhasil diganti.');
    }

    public function destroyAcademicYear(AcademicYear $academicYear): RedirectResponse
    {
        if ($academicYear->terms()->exists()) {
            return back()->withErrors('Tahun ajaran tidak bisa dihapus karena masih memiliki periode.');
        }

        $academicYear->delete();

        return back()->with('status', 'Tahun ajaran berhasil dihapus.');
    }

    public function storeTerm(Request $request): RedirectResponse
    {
        $validated = $request->validate($this->termRules($request));
        $academicYear = AcademicYear::query()->findOrFail($validated['academic_year_id']);

        if ($this->termDateIsOutsideAcademicYear($validated, $academicYear)) {
            return back()
                ->withInput()
                ->withErrors($this->termDateMessage($academicYear));
        }

        if ($request->boolean('is_current')) {
            Term::query()->update(['is_current' => false]);
        }

        Term::create([
            ...$validated,
            'is_current' => $request->boolean('is_current'),
        ]);

        return back()->with('status', 'Periode berhasil ditambahkan.');
    }

    public function updateTerm(Request $request, Term $term): RedirectResponse
    {
        $validated = $request->validate($this->termRules($request, $term));
        $academicYear = AcademicYear::query()->findOrFail($validated['academic_year_id']);

        if ($this->termDateIsOutsideAcademicYear($validated, $academicYear)) {
            return back()
                ->withInput()
                ->withErrors($this->termDateMessage($academicYear));
        }

        if ($request->boolean('is_current')) {
            Term::query()->whereKeyNot($term->id)->update(['is_current' => false]);
        }

        $term->update([
            ...$validated,
            'is_current' => $request->boolean('is_current'),
        ]);

        return back()->with('status', 'Periode berhasil diperbarui.');
    }

    public function activateTerm(Term $term): RedirectResponse
    {
        Term::query()->update(['is_current' => false]);
        $term->update(['is_current' => true]);

        return back()->with('status', 'Periode berjalan berhasil diganti.');
    }

    public function destroyTerm(Term $term): RedirectResponse
    {
        if ($term->reports()->exists() || $term->ilpPlans()->exists()) {
            return back()->withErrors('Periode tidak bisa dihapus karena sudah dipakai rapor atau ILP.');
        }

        $term->delete();

        return back()->with('status', 'Periode berhasil dihapus.');
    }

    public function storeStudent(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'school_class_id' => ['required', 'exists:school_classes,id'],
            'guardian_id' => ['nullable', 'exists:guardians,id'],
            'guardian_name' => ['nullable', 'string', 'max:160'],
            'guardian_relationship' => ['nullable', 'string', 'max:80'],
            'guardian_phone' => ['nullable', 'string', 'max:40'],
            'guardian_email' => ['nullable', 'email', 'max:160'],
            'guardian_address' => ['nullable', 'string', 'max:500'],
            'code' => ['required', 'string', 'max:40', Rule::unique('students', 'code')],
            'name' => ['required', 'string', 'max:160'],
            'gender' => ['nullable', 'string', 'max:40'],
            'birth_place' => ['nullable', 'string', 'max:120'],
            'birth_date' => ['nullable', 'date'],
            'status' => ['required', 'in:active,inactive,graduated'],
            'medical_note' => ['nullable', 'string', 'max:1000'],
        ]);

        $guardianId = $validated['guardian_id'] ?? null;

        if (! $guardianId && filled($validated['guardian_name'] ?? null)) {
            $guardian = Guardian::create([
                'name' => $validated['guardian_name'],
                'relationship' => $validated['guardian_relationship'] ?: 'Orangtua',
                'phone' => $validated['guardian_phone'] ?? null,
                'email' => $validated['guardian_email'] ?? null,
                'address' => $validated['guardian_address'] ?? null,
            ]);
            $guardianId = $guardian->id;
        }

        Student::create([
            'school_class_id' => $validated['school_class_id'],
            'guardian_id' => $guardianId,
            'code' => $validated['code'],
            'name' => $validated['name'],
            'gender' => $validated['gender'] ?? null,
            'birth_place' => $validated['birth_place'] ?? null,
            'birth_date' => $validated['birth_date'] ?? null,
            'status' => $validated['status'],
            'medical_notes' => filled($validated['medical_note'] ?? null)
                ? ['note' => $validated['medical_note']]
                : null,
        ]);

        return back()->with('status', 'Siswa dan data orangtua berhasil ditambahkan.');
    }

    public function updateStudent(Request $request, Student $student): RedirectResponse
    {
        $validated = $request->validate([
            'school_class_id' => ['required', 'exists:school_classes,id'],
            'guardian_id' => ['nullable', 'exists:guardians,id'],
            'code' => ['required', 'string', 'max:40', Rule::unique('students', 'code')->ignore($student)],
            'name' => ['required', 'string', 'max:160'],
            'gender' => ['nullable', 'string', 'max:40'],
            'birth_place' => ['nullable', 'string', 'max:120'],
            'birth_date' => ['nullable', 'date'],
            'status' => ['required', 'in:active,inactive,graduated'],
            'medical_note' => ['nullable', 'string', 'max:1000'],
        ]);

        $student->update([
            'school_class_id' => $validated['school_class_id'],
            'guardian_id' => $validated['guardian_id'] ?? null,
            'code' => $validated['code'],
            'name' => $validated['name'],
            'gender' => $validated['gender'] ?? null,
            'birth_place' => $validated['birth_place'] ?? null,
            'birth_date' => $validated['birth_date'] ?? null,
            'status' => $validated['status'],
            'medical_notes' => filled($validated['medical_note'] ?? null)
                ? ['note' => $validated['medical_note']]
                : null,
        ]);

        return back()->with('status', 'Data siswa berhasil diperbarui.');
    }

    public function toggleStudent(Student $student): RedirectResponse
    {
        $student->update(['status' => $student->status === 'active' ? 'inactive' : 'active']);

        return back()->with('status', 'Status siswa berhasil diperbarui.');
    }

    public function destroyStudent(Student $student): RedirectResponse
    {
        if ($student->observations()->exists() || $student->reports()->exists() || $student->ilpPlans()->exists() || $student->classSessions()->exists()) {
            return back()->withErrors('Siswa tidak bisa dihapus karena sudah memiliki observasi, ILP, rapor, atau presensi.');
        }

        $student->delete();

        return back()->with('status', 'Siswa berhasil dihapus.');
    }

    public function importStudents(Request $request, SpreadsheetReader $reader, MasterImportService $importer): RedirectResponse
    {
        $result = $importer->importStudents($reader->rowsFromRequest($request));

        return back()->with('status', "Import siswa selesai. {$result['created']} dibuat, {$result['updated']} diperbarui.");
    }

    public function updateGuardian(Request $request, Guardian $guardian): RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:160'],
            'relationship' => ['required', 'string', 'max:80'],
            'phone' => ['nullable', 'string', 'max:40'],
            'email' => ['nullable', 'email', 'max:160'],
            'address' => ['nullable', 'string', 'max:500'],
        ]);

        $guardian->update($validated);

        return back()->with('status', 'Data orangtua/wali berhasil diperbarui.');
    }

    public function destroyGuardian(Guardian $guardian): RedirectResponse
    {
        if ($guardian->students()->exists()) {
            return back()->withErrors('Orangtua/wali tidak bisa dihapus karena masih terhubung ke siswa.');
        }

        $guardian->delete();

        return back()->with('status', 'Orangtua/wali berhasil dihapus.');
    }

    public function storeArea(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:120', Rule::unique('development_areas', 'name')],
            'slug' => ['nullable', 'alpha_dash', 'max:120', Rule::unique('development_areas', 'slug')],
            'color' => ['required', 'in:sage,teal,coral,blue,gold,plum'],
            'sort_order' => ['required', 'integer', 'min:0', 'max:999'],
        ]);

        DevelopmentArea::create([
            ...$validated,
            'slug' => $validated['slug'] ?: $this->uniqueSlug('development_areas', $validated['name']),
        ]);

        return back()->with('status', 'Area perkembangan berhasil ditambahkan.');
    }

    public function updateArea(Request $request, DevelopmentArea $developmentArea): RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:120', Rule::unique('development_areas', 'name')->ignore($developmentArea)],
            'slug' => ['nullable', 'alpha_dash', 'max:120', Rule::unique('development_areas', 'slug')->ignore($developmentArea)],
            'color' => ['required', 'in:sage,teal,coral,blue,gold,plum'],
            'sort_order' => ['required', 'integer', 'min:0', 'max:999'],
        ]);

        $developmentArea->update([
            ...$validated,
            'slug' => $validated['slug'] ?: $this->uniqueSlug('development_areas', $validated['name'], $developmentArea->id),
        ]);

        return back()->with('status', 'Area perkembangan berhasil diperbarui.');
    }

    public function destroyArea(DevelopmentArea $developmentArea): RedirectResponse
    {
        if ($developmentArea->indicators()->exists()) {
            return back()->withErrors('Area tidak bisa dihapus karena masih memiliki indikator.');
        }

        $developmentArea->delete();

        return back()->with('status', 'Area perkembangan berhasil dihapus.');
    }

    public function storeTeacher(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:160'],
            'code' => ['required', 'string', 'max:40', Rule::unique('teachers', 'code')],
            'focus_area' => ['nullable', 'string', 'max:160'],
            'phone' => ['nullable', 'string', 'max:40'],
        ]);

        Teacher::create([
            ...$validated,
            'is_active' => true,
        ]);

        return back()->with('status', 'Guru master berhasil ditambahkan.');
    }

    public function updateTeacher(Request $request, Teacher $teacher): RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:160'],
            'code' => ['required', 'string', 'max:40', Rule::unique('teachers', 'code')->ignore($teacher)],
            'focus_area' => ['nullable', 'string', 'max:160'],
            'phone' => ['nullable', 'string', 'max:40'],
        ]);

        $teacher->update($validated);

        return back()->with('status', 'Data guru berhasil diperbarui.');
    }

    public function toggleTeacher(Teacher $teacher): RedirectResponse
    {
        $teacher->update(['is_active' => ! $teacher->is_active]);

        return back()->with('status', 'Status guru berhasil diperbarui.');
    }

    public function destroyTeacher(Teacher $teacher): RedirectResponse
    {
        if ($teacher->weeklySchedules()->exists() || $teacher->classSessions()->exists() || $teacher->observations()->exists()) {
            return back()->withErrors('Guru tidak bisa dihapus karena sudah dipakai jadwal, presensi, atau observasi.');
        }

        $teacher->delete();

        return back()->with('status', 'Guru berhasil dihapus.');
    }

    public function importTeachers(Request $request, SpreadsheetReader $reader, MasterImportService $importer): RedirectResponse
    {
        $result = $importer->importTeachers($reader->rowsFromRequest($request));

        return back()->with('status', "Import guru selesai. {$result['created']} dibuat, {$result['updated']} diperbarui.");
    }

    public function storeIndicator(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'development_area_id' => ['required', 'exists:development_areas,id'],
            'code' => ['required', 'string', 'max:40', Rule::unique('indicators', 'code')],
            'sub_area' => ['required', 'string', 'max:160'],
            'description' => ['required', 'string', 'max:1000'],
            'level' => ['nullable', 'string', 'max:80'],
        ]);

        Indicator::create([
            ...$validated,
            'is_active' => true,
        ]);

        return back()->with('status', 'Indikator perkembangan berhasil ditambahkan.');
    }

    public function updateIndicator(Request $request, Indicator $indicator): RedirectResponse
    {
        $validated = $request->validate([
            'development_area_id' => ['required', 'exists:development_areas,id'],
            'code' => ['required', 'string', 'max:40', Rule::unique('indicators', 'code')->ignore($indicator)],
            'sub_area' => ['required', 'string', 'max:160'],
            'description' => ['required', 'string', 'max:1000'],
            'level' => ['nullable', 'string', 'max:80'],
        ]);

        $indicator->update($validated);

        return back()->with('status', 'Indikator berhasil diperbarui.');
    }

    public function toggleIndicator(Indicator $indicator): RedirectResponse
    {
        $indicator->update(['is_active' => ! $indicator->is_active]);

        return back()->with('status', 'Status indikator berhasil diperbarui.');
    }

    public function destroyIndicator(Indicator $indicator): RedirectResponse
    {
        if ($indicator->observations()->exists() || $indicator->ilpPlans()->exists()) {
            return back()->withErrors('Indikator tidak bisa dihapus karena sudah dipakai observasi atau ILP.');
        }

        $indicator->delete();

        return back()->with('status', 'Indikator berhasil dihapus.');
    }

    public function importIndicators(Request $request, SpreadsheetReader $reader, MasterImportService $importer): RedirectResponse
    {
        $result = $importer->importIndicators($reader->rowsFromRequest($request));

        return back()->with('status', "Import kurikulum selesai. {$result['created']} dibuat, {$result['updated']} diperbarui.");
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

    private function termRules(Request $request, ?Term $term = null): array
    {
        $nameRule = Rule::unique('terms', 'name')
            ->where('academic_year_id', $request->integer('academic_year_id'));

        if ($term) {
            $nameRule->ignore($term);
        }

        return [
            'academic_year_id' => ['required', 'exists:academic_years,id'],
            'name' => ['required', 'string', 'max:80', $nameRule],
            'starts_on' => ['required', 'date'],
            'ends_on' => ['required', 'date', 'after:starts_on'],
            'is_current' => ['nullable', 'boolean'],
        ];
    }

    private function termDateIsOutsideAcademicYear(array $validated, AcademicYear $academicYear): bool
    {
        return Carbon::parse($validated['starts_on'])->lt($academicYear->starts_on)
            || Carbon::parse($validated['ends_on'])->gt($academicYear->ends_on);
    }

    private function termDateMessage(AcademicYear $academicYear): string
    {
        return "Tanggal periode harus berada dalam rentang tahun ajaran {$academicYear->name} ({$academicYear->starts_on->format('d M Y')} - {$academicYear->ends_on->format('d M Y')}).";
    }

    private function uniqueSlug(string $table, string $name, ?int $ignoreId = null): string
    {
        $base = Str::slug($name) ?: 'item';
        $slug = $base;
        $counter = 2;

        while (DB::table($table)
            ->where('slug', $slug)
            ->when($ignoreId, fn ($query) => $query->where('id', '!=', $ignoreId))
            ->exists()) {
            $slug = "{$base}-{$counter}";
            $counter++;
        }

        return $slug;
    }

    private function normalizeAgeYearInputs(Request $request): void
    {
        $request->merge([
            'min_age_years' => $this->normalizeDecimalInput($request->input('min_age_years')),
            'max_age_years' => $this->normalizeDecimalInput($request->input('max_age_years')),
        ]);
    }

    private function normalizeDecimalInput(mixed $value): ?string
    {
        if (! filled($value)) {
            return null;
        }

        return str_replace(',', '.', trim((string) $value));
    }

    private function yearsToMonths(null|string|float|int $years): ?float
    {
        if (! filled($years)) {
            return null;
        }

        return round((float) $years * 12, 2);
    }
}
