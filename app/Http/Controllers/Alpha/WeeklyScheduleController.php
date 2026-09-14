<?php

namespace App\Http\Controllers\Alpha;

use App\Http\Controllers\Controller;
use App\Models\SchoolClass;
use App\Models\WeeklySchedule;
use App\Services\Scheduling\ScheduleWriteService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class WeeklyScheduleController extends Controller
{
    public function store(Request $request, ScheduleWriteService $writer): RedirectResponse
    {
        $validated = $this->rules($request);
        $validated['capacity'] = $this->resolveCapacity($validated['capacity'] ?? null, (int) $validated['school_class_id']);
        $writer->create($validated);

        return back()->with('status', 'Jadwal mingguan berhasil ditambahkan.');
    }

    public function update(Request $request, WeeklySchedule $weeklySchedule, ScheduleWriteService $writer): RedirectResponse
    {
        $validated = $this->rules($request);
        $validated['capacity'] = $this->resolveCapacity($validated['capacity'] ?? null, (int) $validated['school_class_id']);
        $writer->update($weeklySchedule, $validated);

        return back()->with('status', 'Jadwal mingguan berhasil diperbarui.');
    }

    public function toggle(WeeklySchedule $weeklySchedule, ScheduleWriteService $writer): RedirectResponse
    {
        $writer->toggle($weeklySchedule);

        return back()->with('status', 'Status jadwal mingguan berhasil diperbarui.');
    }

    public function destroy(WeeklySchedule $weeklySchedule, ScheduleWriteService $writer): RedirectResponse
    {
        if ($weeklySchedule->classSessions()->exists()) {
            return back()->withErrors('Jadwal tidak bisa dihapus karena sudah pernah dibuat menjadi presensi.');
        }

        try {
            $writer->delete($weeklySchedule);
        } catch (ValidationException $exception) {
            return back()->withErrors($exception->errors());
        }

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

    private function resolveCapacity(null|int|string $capacity, int $schoolClassId): int
    {
        if ($capacity !== null && (int) $capacity > 0) {
            return (int) $capacity;
        }

        return (int) SchoolClass::query()->whereKey($schoolClassId)->value('capacity') ?: 1;
    }
}
