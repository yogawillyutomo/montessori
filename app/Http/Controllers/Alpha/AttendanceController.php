<?php

namespace App\Http\Controllers\Alpha;

use App\Http\Controllers\Controller;
use App\Models\ClassSession;
use App\Services\Alpha\AccessScopeService;
use App\Services\Entitlement\AttendanceOutcomeService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class AttendanceController extends Controller
{
    public function update(Request $request, ClassSession $classSession): RedirectResponse
    {
        $this->authorizeTeacherSession($request, $classSession);

        $validated = $request->validate([
            'attendance' => ['required', 'array'],
            'attendance.*.status' => ['required', 'in:present,excused,sick,absent,late,unmarked'],
            'attendance.*.note' => ['nullable', 'string', 'max:500'],
            'attendance_action' => ['nullable', 'in:save,all_present,reset'],
        ]);

        $sessionStudentIds = $classSession->students()
            ->pluck('students.id')
            ->map(fn ($id): int => (int) $id)
            ->all();
        $action = $validated['attendance_action'] ?? 'save';

        foreach (array_keys($validated['attendance']) as $studentId) {
            if (! in_array((int) $studentId, $sessionStudentIds, true)) {
                throw ValidationException::withMessages([
                    'attendance' => 'Presensi hanya boleh diisi untuk siswa yang terdaftar di jadwal ini.',
                ]);
            }
        }

        $rows = collect($sessionStudentIds)->mapWithKeys(function (int $studentId) use ($validated, $action): array {
            $row = $validated['attendance'][$studentId] ?? [];

            if ($action === 'all_present') {
                $row['status'] = 'present';
            }

            if ($action === 'reset') {
                $row['status'] = 'unmarked';
                $row['note'] = null;
            }

            return [$studentId => $row];
        });

        $actor = $request->user();
        abort_if(! $actor, 403);

        DB::transaction(function () use ($rows, $classSession, $actor): void {
            $outcomes = app(AttendanceOutcomeService::class);

            foreach ($rows as $studentId => $row) {
                $outcomes->recordForLegacySession(
                    $classSession,
                    (int) $studentId,
                    $row['status'] ?? 'unmarked',
                    $row['note'] ?? null,
                    $actor,
                );
            }
        });

        return back()->with('status', 'Presensi berhasil diperbarui.');
    }

    private function authorizeTeacherSession(Request $request, ClassSession $classSession): void
    {
        $teacher = app(AccessScopeService::class)->teacherFor($request->user());

        abort_if($teacher && (int) $classSession->teacher_id !== (int) $teacher->id, 403);
    }
}
