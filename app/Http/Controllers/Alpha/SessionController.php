<?php

namespace App\Http\Controllers\Alpha;

use App\Http\Controllers\Alpha\Concerns\ProvidesAlphaShell;
use App\Http\Controllers\Controller;
use App\Models\ClassSession;
use App\Models\SchoolClass;
use App\Models\WeeklySchedule;
use App\Services\Alpha\AccessScopeService;
use App\Services\Scheduling\SessionWriteService;
use App\Support\Alpha\Role;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class SessionController extends Controller
{
    use ProvidesAlphaShell;

    public function __construct(
        private readonly SessionWriteService $sessionWrites,
    ) {}

    public function createFromSchedule(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'weekly_schedule_id' => ['required', 'exists:weekly_schedules,id'],
            'session_date' => ['required', 'date'],
        ]);

        $schedule = WeeklySchedule::query()->findOrFail($validated['weekly_schedule_id']);
        $this->authorizeTeacherSchedule($request, $schedule);
        $this->sessionWrites->createFromSchedule($schedule, $validated['session_date'], $request->user());

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
            : $this->sessionWrites->participantIds($classSession);

        $this->sessionWrites->update(
            $classSession,
            collect($validated)->except(['student_ids', 'student_ids_present'])->all(),
            $studentIds,
            $request->user(),
        );

        return back()->with('status', 'Sesi belajar berhasil diperbarui.');
    }

    public function updateNote(Request $request, ClassSession $classSession): RedirectResponse
    {
        $this->authorizeTeacherSession($request, $classSession);
        $validated = $request->validate([
            'class_note' => ['nullable', 'string', 'max:3000'],
            'follow_up_recommendation' => ['nullable', 'string', 'max:3000'],
        ]);

        $this->sessionWrites->updateNotes($classSession, $validated);

        return back()->with('status', 'Catatan kelas berhasil disimpan.');
    }

    public function close(Request $request, ClassSession $classSession): RedirectResponse
    {
        $this->authorizeTeacherSession($request, $classSession);
        $validated = $request->validate([
            'class_note' => ['nullable', 'string', 'max:3000'],
            'follow_up_recommendation' => ['nullable', 'string', 'max:3000'],
        ]);

        $unmarked = $this->sessionWrites->close($classSession, $validated, $request->user());
        $warning = $unmarked > 0
            ? " Sesi ditutup dengan {$unmarked} siswa belum ditandai presensinya."
            : '';

        return back()->with('status', 'Sesi belajar berhasil ditutup.'.$warning);
    }

    public function destroy(Request $request, ClassSession $classSession): RedirectResponse
    {
        $this->authorizeTeacherSession($request, $classSession);
        $this->sessionWrites->destroy($classSession, $request->user());

        return back()->with('status', 'Sesi belajar berhasil dihapus.');
    }

    /** @return array<string, mixed> */
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

    private function authorizeTeacherSchedule(Request $request, WeeklySchedule $schedule): void
    {
        $user = $request->user();
        abort_if(! $user, 403);

        if ($user->role !== Role::TEACHER) {
            return;
        }

        $teacher = app(AccessScopeService::class)->teacherFor($user);
        abort_if(! $teacher, 403);

        $ownership = $this->sessionWrites->scheduleOwnership($schedule);
        abort_if($ownership['teacher_id'] === null || $ownership['teacher_id'] !== (int) $teacher->id, 403);
    }

    private function authorizeTeacherSession(Request $request, ClassSession $classSession): void
    {
        $user = $request->user();
        abort_if(! $user, 403);

        if ($user->role !== Role::TEACHER) {
            return;
        }

        $teacher = app(AccessScopeService::class)->teacherFor($user);
        abort_if(! $teacher, 403);

        $ownership = $this->sessionWrites->ownership($classSession);
        abort_if($ownership['teacher_id'] === null || $ownership['teacher_id'] !== (int) $teacher->id, 403);
    }

    private function normalizeRoom(?string $room): ?string
    {
        $room = trim((string) $room);

        return $room === '' ? null : $room;
    }

    /** @param array<string, mixed> $validated @return array<int, int> */
    private function selectedStudentIds(array $validated): array
    {
        return collect($validated['student_ids'] ?? [])->map(fn ($id): int => (int) $id)->unique()->values()->all();
    }

    private function resolveCapacity(null|int|string $capacity, int $schoolClassId): int
    {
        if ($capacity !== null && (int) $capacity > 0) {
            return (int) $capacity;
        }

        return (int) SchoolClass::query()->whereKey($schoolClassId)->value('capacity') ?: 1;
    }
}
