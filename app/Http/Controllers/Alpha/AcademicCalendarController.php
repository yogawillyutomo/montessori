<?php

namespace App\Http\Controllers\Alpha;

use App\Http\Controllers\Controller;
use App\Models\AcademicYear;
use App\Models\Term;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;

class AcademicCalendarController extends Controller
{
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

    /**
     * @param  array<string, mixed>  $validated
     */
    private function termDateIsOutsideAcademicYear(array $validated, AcademicYear $academicYear): bool
    {
        return Carbon::parse($validated['starts_on'])->lt($academicYear->starts_on)
            || Carbon::parse($validated['ends_on'])->gt($academicYear->ends_on);
    }

    private function termDateMessage(AcademicYear $academicYear): string
    {
        return "Tanggal periode harus berada dalam rentang tahun ajaran {$academicYear->name} ({$academicYear->starts_on->format('d M Y')} - {$academicYear->ends_on->format('d M Y')}).";
    }
}
