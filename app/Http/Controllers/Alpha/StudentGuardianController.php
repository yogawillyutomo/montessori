<?php

namespace App\Http\Controllers\Alpha;

use App\Http\Controllers\Controller;
use App\Models\Guardian;
use App\Models\Student;
use App\Services\Alpha\MasterImportService;
use App\Services\Alpha\SpreadsheetReader;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class StudentGuardianController extends Controller
{
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
}
