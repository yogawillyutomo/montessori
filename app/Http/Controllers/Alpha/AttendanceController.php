<?php

namespace App\Http\Controllers\Alpha;

use App\Http\Controllers\Controller;
use App\Models\ChildSessionBooking;
use App\Models\SessionOccurrence;
use App\Services\Alpha\AccessScopeService;
use App\Services\Entitlement\AttendanceOutcomeService;
use App\Services\Scheduling\SessionWriteService;
use App\Support\Alpha\Role;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class AttendanceController extends Controller
{
    public function update(Request $request, SessionOccurrence $sessionOccurrence): RedirectResponse
    {
        $sessionWrites = app(SessionWriteService::class);
        $this->authorizeTeacherOccurrence($request, $sessionOccurrence, $sessionWrites);

        $validated = $request->validate([
            'attendance' => ['required', 'array'],
            'attendance.*.status' => ['required', 'in:present,excused,sick,absent,late,unmarked'],
            'attendance.*.note' => ['nullable', 'string', 'max:500'],
            'attendance_action' => ['nullable', 'in:save,all_present,reset'],
        ]);

        $sessionStudentIds = $sessionWrites->participantIdsForOccurrence($sessionOccurrence);
        $action = $validated['attendance_action'] ?? 'save';

        foreach (array_keys($validated['attendance']) as $studentId) {
            if (! in_array((int) $studentId, $sessionStudentIds, true)) {
                throw ValidationException::withMessages([
                    'attendance' => 'Presensi hanya boleh diisi untuk anak dengan booking target aktif di sesi ini.',
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

        DB::transaction(function () use ($rows, $sessionOccurrence, $actor): void {
            $outcomes = app(AttendanceOutcomeService::class);

            foreach ($rows as $studentId => $row) {
                $booking = ChildSessionBooking::query()
                    ->where('session_occurrence_id', $sessionOccurrence->id)
                    ->where('student_id', (int) $studentId)
                    ->whereIn('status', ChildSessionBooking::ACTIVE_STATUSES)
                    ->first();

                if (! $booking) {
                    throw ValidationException::withMessages([
                        'attendance' => 'Canonical booking aktif untuk participant tidak ditemukan.',
                    ]);
                }

                $outcomes->recordForBooking(
                    $booking,
                    $row['status'] ?? 'unmarked',
                    $row['note'] ?? null,
                    $actor,
                );
            }
        });

        return back()->with('status', 'Presensi berhasil diperbarui.');
    }

    private function authorizeTeacherOccurrence(
        Request $request,
        SessionOccurrence $sessionOccurrence,
        SessionWriteService $sessionWrites,
    ): void {
        $user = $request->user();
        abort_if(! $user, 403);

        if ($user->role !== Role::TEACHER) {
            return;
        }

        $teacher = app(AccessScopeService::class)->teacherFor($user);
        abort_if(! $teacher, 403);

        $ownership = $sessionWrites->ownershipForOccurrence($sessionOccurrence);
        abort_if($ownership['teacher_id'] === null || $ownership['teacher_id'] !== (int) $teacher->id, 403);
    }
}
