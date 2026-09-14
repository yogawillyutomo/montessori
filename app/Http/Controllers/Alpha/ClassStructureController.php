<?php

namespace App\Http\Controllers\Alpha;

use App\Http\Controllers\Controller;
use App\Models\ClassLevel;
use App\Models\SchoolClass;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class ClassStructureController extends Controller
{
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
