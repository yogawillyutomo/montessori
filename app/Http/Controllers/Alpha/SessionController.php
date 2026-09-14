<?php

namespace App\Http\Controllers\Alpha;

use App\Http\Controllers\Alpha\Concerns\ProvidesAlphaShell;
use App\Http\Controllers\Controller;
use App\Models\ClassSession;
use App\Models\SchoolClass;
use App\Models\WeeklySchedule;
use App\Services\Alpha\AccessScopeService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

class SessionController extends Controller
{
    use ProvidesAlphaShell;

    public function createFromSchedule(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'weekly_schedule_id' => ['required', 'exists:weekly_schedules,id'],
            'session_date' => ['required', 'date'],
        ]);

        $schedule = WeeklySchedule::query()->with('students')->findOrFail($validated['weekly_schedule_id']);
        $this->authorizeTeacherSchedule($request, $schedule);
        $sessionDate = Carbon::parse($validated['session_date']);

        if (! $schedule->is_active) {
            return back()->withErrors('Jadwal nonaktif tidak bisa dibuat menjadi sesi belajar.');
        }

        if ((int) $sessionDate->dayOfWeekIso !== $schedule->day_of_week) {
            return back()->withErrors("Tanggal sesi belajar harus sesuai hari jadwal, yaitu {$this->dayLabels()[$schedule->day_of_week]}.");
        }

        $sessionPayload = [
            'school_class_id' => $schedule->school_class_id,
            'teacher_id' => $schedule->teacher_id,
            'room' => $this->normalizeRoom($schedule->room),
            'capacity' => $this->scheduleCapacity($schedule),
            'session_date' => $sessionDate->toDateString(),
            'starts_at' => Carbon::parse($schedule->starts_at)->format('H:i'),
            'ends_at' => Carbon::parse($schedule->ends_at)->format('H:i'),
            'topic' => $schedule->topic,
            'status' => 'planned',
        ];
        $existingSession = ClassSession::query()
            ->where('weekly_schedule_id', $schedule->id)
            ->whereDate('session_date', $sessionPayload['session_date'])
            ->first();

        if (! $existingSession) {
            $this->validateSessionTime($sessionPayload);
            $this->validateSessionStudents($sessionPayload, $schedule->students->pluck('id')->all());
        }

        $session = ClassSession::query()->firstOrCreate(
            [
                'weekly_schedule_id' => $schedule->id,
                'session_date' => $sessionPayload['session_date'],
            ],
            $sessionPayload
        );

        if ($session->wasRecentlyCreated) {
            $studentIds = $schedule->students->pluck('id')->all();
            $session->students()->sync($studentIds);

            foreach ($studentIds as $studentId) {
                $session->attendances()->firstOrCreate([
                    'student_id' => $studentId,
                ], [
                    'status' => 'present',
                    'marked_by' => null,
                    'marked_at' => null,
                ]);
            }
        }

        return back()->with('status', 'Sesi belajar berhasil dibuat dari jadwal mingguan. Presensi bisa diisi kapan saja.');
    }

    public function update(Request $request, ClassSession $classSession): RedirectResponse
    {
        $this->authorizeTeacherSession($request, $classSession);
        $validated = $this->sessionRules($request);
        $validated['room'] = $this->normalizeRoom($validated['room'] ?? null);
        $validated['capacity'] = $this->resolveCapacity($validated['capacity'] ?? null, (int) $validated['school_class_id']);
        $studentIds = $request->boolean('student_ids_present')
            ? $this->selectedStudentIds($validated)
            : $classSession->students()->pluck('students.id')->all();

        $this->validateSessionTime($validated, $classSession);
        $this->validateSessionStudents($validated, $studentIds, $classSession);

        $observedStudentIds = $classSession->observations()->pluck('student_id')->map(fn ($id) => (int) $id)->all();
        $removedObservedStudents = array_diff($observedStudentIds, $studentIds);

        if ($removedObservedStudents !== []) {
            return back()->withErrors('Siswa yang sudah memiliki observasi pada presensi ini tidak bisa dikeluarkan dari presensi.');
        }

        $classSession->update(collect($validated)->except(['student_ids', 'student_ids_present'])->all());
        $classSession->students()->sync($studentIds);
        $classSession->attendances()->whereNotIn('student_id', $studentIds)->delete();

        foreach ($studentIds as $studentId) {
            $classSession->attendances()->firstOrCreate([
                'student_id' => $studentId,
            ], [
                'status' => 'present',
                'marked_by' => null,
                'marked_at' => null,
            ]);
        }

        return back()->with('status', 'Sesi belajar berhasil diperbarui.');
    }

    public function updateNote(Request $request, ClassSession $classSession): RedirectResponse
    {
        $this->authorizeTeacherSession($request, $classSession);

        $validated = $request->validate([
            'class_note' => ['nullable', 'string', 'max:3000'],
            'follow_up_recommendation' => ['nullable', 'string', 'max:3000'],
        ]);

        $classSession->update($validated);

        return back()->with('status', 'Catatan kelas berhasil disimpan.');
    }

    public function close(Request $request, ClassSession $classSession): RedirectResponse
    {
        $this->authorizeTeacherSession($request, $classSession);

        $validated = $request->validate([
            'class_note' => ['nullable', 'string', 'max:3000'],
            'follow_up_recommendation' => ['nullable', 'string', 'max:3000'],
        ]);

        $classSession->loadMissing(['students', 'attendances', 'observations']);
        $recap = $classSession->attendanceRecap();

        $classSession->update([
            ...$validated,
            'status' => 'completed',
            'closed_by' => $request->user()?->id,
            'closed_at' => now(),
        ]);

        $warning = $recap['unmarked'] > 0
            ? " Sesi ditutup dengan {$recap['unmarked']} siswa belum ditandai presensinya."
            : '';

        return back()->with('status', 'Sesi belajar berhasil ditutup.'.$warning);
    }

    public function destroy(Request $request, ClassSession $classSession): RedirectResponse
    {
        $this->authorizeTeacherSession($request, $classSession);

        if ($classSession->observations()->exists()) {
            return back()->withErrors('Sesi belajar tidak bisa dihapus karena sudah memiliki observasi.');
        }

        $classSession->attendances()->delete();
        $classSession->students()->detach();
        $classSession->delete();

        return back()->with('status', 'Sesi belajar berhasil dihapus.');
    }

    /**
     * @return array<string, mixed>
     */
    private function sessionRules(Request $request): array
    {
        return $request->validate([
            'school_class_id' => ['required', 'exists:school_classes,id'],
            'teacher_id' => ['required', 'exists:teachers,id'],
            'room' => ['nullable', 'string', 'max:120'],
            'capacity' => ['nullable', 'integer', 'min:1', 'max:60'],
            'session_date' => ['required', 'date'],
            'starts_at' => ['required', 'date_format:H:i'],
            'ends_at' => ['required', 'date_format:H:i'],
            'topic' => ['nullable', 'string', 'max:160'],
            'status' => ['required', 'in:planned,completed,cancelled'],
            'student_ids_present' => ['nullable', 'boolean'],
            'student_ids' => ['nullable', 'array'],
            'student_ids.*' => ['integer', 'distinct', 'exists:students,id'],
        ]);
    }

    /**
     * @param  array<string, mixed>  $validated
     */
    private function validateSessionTime(array $validated, ?ClassSession $ignore = null): void
    {
        if ($validated['ends_at'] <= $validated['starts_at']) {
            throw ValidationException::withMessages([
                'ends_at' => 'Jam selesai presensi harus setelah jam mulai.',
            ]);
        }

        $overlap = ClassSession::query()
            ->whereDate('session_date', $validated['session_date'])
            ->where('starts_at', '<', $validated['ends_at'])
            ->where('ends_at', '>', $validated['starts_at'])
            ->where(function ($query) use ($validated): void {
                $query->where('school_class_id', $validated['school_class_id'])
                    ->orWhere('teacher_id', $validated['teacher_id']);

                if ($this->normalizeRoom($validated['room'] ?? null) !== null) {
                    $query->orWhere('room', $this->normalizeRoom($validated['room']));
                }
            })
            ->when($ignore, fn ($query) => $query->whereKeyNot($ignore->id))
            ->exists();

        if ($overlap) {
            throw ValidationException::withMessages([
                'starts_at' => 'Presensi bentrok dengan kelas, guru, atau ruangan pada tanggal dan jam yang sama.',
            ]);
        }
    }

    /**
     * @param  array<string, mixed>  $validated
     * @param  array<int, int>  $studentIds
     */
    private function validateSessionStudents(array $validated, array $studentIds, ?ClassSession $ignore = null): void
    {
        $studentIds = collect($studentIds)->map(fn ($id) => (int) $id)->unique()->values()->all();
        if ($studentIds === []) {
            return;
        }

        if (count($studentIds) > $validated['capacity']) {
            throw ValidationException::withMessages([
                'student_ids' => "Jumlah peserta presensi melebihi kapasitas ({$validated['capacity']} siswa).",
            ]);
        }

        $conflictExists = ClassSession::query()
            ->whereDate('session_date', $validated['session_date'])
            ->where('starts_at', '<', $validated['ends_at'])
            ->where('ends_at', '>', $validated['starts_at'])
            ->whereHas('students', fn ($query) => $query->whereIn('students.id', $studentIds))
            ->when($ignore, fn ($query) => $query->whereKeyNot($ignore->id))
            ->exists();

        if ($conflictExists) {
            throw ValidationException::withMessages([
                'student_ids' => 'Ada siswa yang sudah terdaftar pada presensi lain di tanggal dan jam yang sama.',
            ]);
        }
    }

    private function authorizeTeacherSchedule(Request $request, WeeklySchedule $schedule): void
    {
        $teacher = app(AccessScopeService::class)->teacherFor($request->user());

        abort_if($teacher && (int) $schedule->teacher_id !== $teacher->id, 403);
    }

    private function authorizeTeacherSession(Request $request, ClassSession $classSession): void
    {
        $teacher = app(AccessScopeService::class)->teacherFor($request->user());

        abort_if($teacher && (int) $classSession->teacher_id !== $teacher->id, 403);
    }

    private function normalizeRoom(?string $room): ?string
    {
        $room = trim((string) $room);

        return $room === '' ? null : $room;
    }

    /**
     * @param  array<string, mixed>  $validated
     * @return array<int, int>
     */
    private function selectedStudentIds(array $validated): array
    {
        return collect($validated['student_ids'] ?? [])
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values()
            ->all();
    }

    private function resolveCapacity(null|int|string $capacity, int $schoolClassId): int
    {
        if ($capacity !== null && (int) $capacity > 0) {
            return (int) $capacity;
        }

        return (int) SchoolClass::query()->whereKey($schoolClassId)->value('capacity') ?: 1;
    }

    private function scheduleCapacity(WeeklySchedule $schedule): int
    {
        return (int) ($schedule->capacity ?: $schedule->schoolClass?->capacity ?: 1);
    }
}
