<?php

namespace App\Http\Controllers\Alpha;

use App\Http\Controllers\Controller;
use App\Models\SchoolClass;
use App\Models\WeeklySchedule;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class WeeklyScheduleController extends Controller
{
    public function store(Request $request): RedirectResponse
    {
        $validated = $this->rules($request);
        $validated['capacity'] = $this->resolveCapacity($validated['capacity'] ?? null, (int) $validated['school_class_id']);
        $this->validateTime($validated);
        $this->validateStudents($validated);

        $schedule = WeeklySchedule::create([
            'school_class_id' => $validated['school_class_id'],
            'teacher_id' => $validated['teacher_id'],
            'room' => $this->normalizeRoom($validated['room'] ?? null),
            'capacity' => $validated['capacity'],
            'day_of_week' => $validated['day_of_week'],
            'starts_at' => $validated['starts_at'],
            'ends_at' => $validated['ends_at'],
            'topic' => $validated['topic'] ?? null,
            'is_active' => true,
        ]);
        $schedule->students()->sync($validated['student_ids'] ?? []);

        return back()->with('status', 'Jadwal mingguan berhasil ditambahkan.');
    }

    public function update(Request $request, WeeklySchedule $weeklySchedule): RedirectResponse
    {
        $validated = $this->rules($request);
        $validated['capacity'] = $this->resolveCapacity($validated['capacity'] ?? null, (int) $validated['school_class_id']);
        $this->validateTime($validated, $weeklySchedule);
        $this->validateStudents($validated, $weeklySchedule);

        $weeklySchedule->update([
            'school_class_id' => $validated['school_class_id'],
            'teacher_id' => $validated['teacher_id'],
            'room' => $this->normalizeRoom($validated['room'] ?? null),
            'capacity' => $validated['capacity'],
            'day_of_week' => $validated['day_of_week'],
            'starts_at' => $validated['starts_at'],
            'ends_at' => $validated['ends_at'],
            'topic' => $validated['topic'] ?? null,
        ]);
        $weeklySchedule->students()->sync($validated['student_ids'] ?? []);

        return back()->with('status', 'Jadwal mingguan berhasil diperbarui.');
    }

    public function toggle(WeeklySchedule $weeklySchedule): RedirectResponse
    {
        $weeklySchedule->update(['is_active' => ! $weeklySchedule->is_active]);

        return back()->with('status', 'Status jadwal mingguan berhasil diperbarui.');
    }

    public function destroy(WeeklySchedule $weeklySchedule): RedirectResponse
    {
        if ($weeklySchedule->classSessions()->exists()) {
            return back()->withErrors('Jadwal tidak bisa dihapus karena sudah pernah dibuat menjadi presensi.');
        }

        $weeklySchedule->delete();

        return back()->with('status', 'Jadwal mingguan berhasil dihapus.');
    }

    /**
     * @return array<string, mixed>
     */
    private function rules(Request $request): array
    {
        return $request->validate([
            'school_class_id' => ['required', 'exists:school_classes,id'],
            'teacher_id' => ['required', 'exists:teachers,id'],
            'room' => ['nullable', 'string', 'max:120'],
            'capacity' => ['nullable', 'integer', 'min:1', 'max:60'],
            'day_of_week' => ['required', 'integer', 'min:1', 'max:6'],
            'starts_at' => ['required', 'date_format:H:i'],
            'ends_at' => ['required', 'date_format:H:i'],
            'topic' => ['nullable', 'string', 'max:160'],
            'student_ids' => ['nullable', 'array'],
            'student_ids.*' => ['integer', 'distinct', 'exists:students,id'],
        ]);
    }

    /**
     * @param  array<string, mixed>  $validated
     */
    private function validateTime(array $validated, ?WeeklySchedule $ignore = null): void
    {
        if ($validated['ends_at'] <= $validated['starts_at']) {
            throw ValidationException::withMessages([
                'ends_at' => 'Jam selesai harus setelah jam mulai.',
            ]);
        }

        $overlap = WeeklySchedule::query()
            ->where('day_of_week', $validated['day_of_week'])
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
                'starts_at' => 'Jadwal bentrok dengan kelas, guru, atau ruangan pada hari dan jam yang sama.',
            ]);
        }
    }

    /**
     * @param  array<string, mixed>  $validated
     */
    private function validateStudents(array $validated, ?WeeklySchedule $ignore = null): void
    {
        $studentIds = $this->selectedStudentIds($validated);
        if ($studentIds === []) {
            return;
        }

        if (count($studentIds) > $validated['capacity']) {
            throw ValidationException::withMessages([
                'student_ids' => "Jumlah peserta melebihi kapasitas slot ({$validated['capacity']} siswa).",
            ]);
        }

        $conflictExists = WeeklySchedule::query()
            ->where('day_of_week', $validated['day_of_week'])
            ->where('starts_at', '<', $validated['ends_at'])
            ->where('ends_at', '>', $validated['starts_at'])
            ->whereHas('students', fn ($query) => $query->whereIn('students.id', $studentIds))
            ->when($ignore, fn ($query) => $query->whereKeyNot($ignore->id))
            ->exists();

        if ($conflictExists) {
            throw ValidationException::withMessages([
                'student_ids' => 'Ada siswa yang sudah punya slot mingguan lain pada hari dan jam yang sama.',
            ]);
        }
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
}
