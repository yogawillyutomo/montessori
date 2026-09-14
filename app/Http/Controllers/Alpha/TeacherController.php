<?php

namespace App\Http\Controllers\Alpha;

use App\Http\Controllers\Controller;
use App\Models\Teacher;
use App\Services\Alpha\MasterImportService;
use App\Services\Alpha\SpreadsheetReader;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class TeacherController extends Controller
{
    public function store(Request $request): RedirectResponse
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

    public function update(Request $request, Teacher $teacher): RedirectResponse
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

    public function toggle(Teacher $teacher): RedirectResponse
    {
        $teacher->update(['is_active' => ! $teacher->is_active]);

        return back()->with('status', 'Status guru berhasil diperbarui.');
    }

    public function destroy(Teacher $teacher): RedirectResponse
    {
        if ($teacher->weeklySchedules()->exists() || $teacher->classSessions()->exists() || $teacher->observations()->exists()) {
            return back()->withErrors('Guru tidak bisa dihapus karena sudah dipakai jadwal, presensi, atau observasi.');
        }

        $teacher->delete();

        return back()->with('status', 'Guru berhasil dihapus.');
    }

    public function import(Request $request, SpreadsheetReader $reader, MasterImportService $importer): RedirectResponse
    {
        $result = $importer->importTeachers($reader->rowsFromRequest($request));

        return back()->with('status', "Import guru selesai. {$result['created']} dibuat, {$result['updated']} diperbarui.");
    }
}
