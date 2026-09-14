<?php

namespace App\Services\Scheduling;

use App\Models\RecurringSchedule;
use App\Models\SessionTemplate;
use App\Models\WeeklySchedule;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ScheduleWriteService
{
    /**
     * @param  array<string, mixed>  $payload
     */
    public function create(array $payload): WeeklySchedule
    {
        if ($this->usesLegacySource()) {
            return $this->createLegacy($payload);
        }

        return DB::transaction(function () use ($payload): WeeklySchedule {
            $studentIds = $this->studentIds($payload);
            $this->assertValid($payload, $studentIds);

            $template = SessionTemplate::query()->create($this->templatePayload($payload));
            $legacyId = DB::table('weekly_schedules')->insertGetId([
                ...$this->legacyPayload($payload),
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $template->forceFill(['legacy_weekly_schedule_id' => $legacyId])->save();
            $this->replaceTargetMembership($template, $studentIds, $legacyId);
            $this->replaceLegacyMembership($legacyId, $studentIds);

            return WeeklySchedule::query()->findOrFail($legacyId);
        });
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function update(WeeklySchedule $compatibilitySchedule, array $payload): WeeklySchedule
    {
        if ($this->usesLegacySource()) {
            return $this->updateLegacy($compatibilitySchedule, $payload);
        }

        return DB::transaction(function () use ($compatibilitySchedule, $payload): WeeklySchedule {
            $template = SessionTemplate::query()
                ->where('legacy_weekly_schedule_id', $compatibilitySchedule->id)
                ->lockForUpdate()
                ->firstOrFail();
            $studentIds = $this->studentIds($payload);

            $this->assertValid($payload, $studentIds, $template);

            $template->forceFill($this->templatePayload($payload, (bool) $template->is_active))->save();
            DB::table('weekly_schedules')->where('id', $compatibilitySchedule->id)->update([
                ...$this->legacyPayload($payload, (bool) $template->is_active),
                'updated_at' => now(),
            ]);

            $this->replaceTargetMembership($template, $studentIds, (int) $compatibilitySchedule->id);
            $this->replaceLegacyMembership((int) $compatibilitySchedule->id, $studentIds);

            return WeeklySchedule::query()->findOrFail($compatibilitySchedule->id);
        });
    }

    public function toggle(WeeklySchedule $compatibilitySchedule): WeeklySchedule
    {
        if ($this->usesLegacySource()) {
            $compatibilitySchedule->update(['is_active' => ! $compatibilitySchedule->is_active]);

            return $compatibilitySchedule->fresh();
        }

        return DB::transaction(function () use ($compatibilitySchedule): WeeklySchedule {
            $template = SessionTemplate::query()
                ->where('legacy_weekly_schedule_id', $compatibilitySchedule->id)
                ->lockForUpdate()
                ->firstOrFail();
            $nextActive = ! $template->is_active;

            if ($nextActive) {
                $payload = [
                    'school_class_id' => $template->legacy_school_class_id,
                    'teacher_id' => $template->legacy_teacher_id,
                    'room' => $template->room,
                    'capacity' => $template->capacity,
                    'day_of_week' => $template->day_of_week,
                    'starts_at' => $template->starts_at?->format('H:i'),
                    'ends_at' => $template->ends_at?->format('H:i'),
                    'topic' => $template->legacy_topic,
                ];
                $studentIds = RecurringSchedule::query()
                    ->where('session_template_id', $template->id)
                    ->whereNull('legacy_deleted_at')
                    ->pluck('student_id')
                    ->map(fn ($id): int => (int) $id)
                    ->all();
                $this->assertValid($payload, $studentIds, $template);
            }

            $template->forceFill(['is_active' => $nextActive])->save();
            RecurringSchedule::query()
                ->where('session_template_id', $template->id)
                ->whereNull('legacy_deleted_at')
                ->update(['is_active' => $nextActive, 'updated_at' => now()]);
            DB::table('weekly_schedules')->where('id', $compatibilitySchedule->id)->update([
                'is_active' => $nextActive,
                'updated_at' => now(),
            ]);

            return WeeklySchedule::query()->findOrFail($compatibilitySchedule->id);
        });
    }

    public function delete(WeeklySchedule $compatibilitySchedule): void
    {
        if ($this->usesLegacySource()) {
            $compatibilitySchedule->delete();

            return;
        }

        DB::transaction(function () use ($compatibilitySchedule): void {
            $template = SessionTemplate::query()
                ->where('legacy_weekly_schedule_id', $compatibilitySchedule->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($template->occurrences()->exists()) {
                throw ValidationException::withMessages([
                    'schedule' => 'Jadwal tidak bisa dihapus karena sudah pernah dibuat menjadi sesi belajar.',
                ]);
            }

            RecurringSchedule::query()
                ->where('session_template_id', $template->id)
                ->update([
                    'is_active' => false,
                    'legacy_deleted_at' => now(),
                    'updated_at' => now(),
                ]);
            $template->forceFill([
                'is_active' => false,
                'legacy_deleted_at' => now(),
            ])->save();

            DB::table('student_weekly_schedule')
                ->where('weekly_schedule_id', $compatibilitySchedule->id)
                ->delete();
            DB::table('weekly_schedules')
                ->where('id', $compatibilitySchedule->id)
                ->delete();
        });
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<int, int>  $studentIds
     */
    private function assertValid(array $payload, array $studentIds, ?SessionTemplate $ignore = null): void
    {
        if ($payload['ends_at'] <= $payload['starts_at']) {
            throw ValidationException::withMessages([
                'ends_at' => 'Jam selesai harus setelah jam mulai.',
            ]);
        }

        if (count($studentIds) > (int) $payload['capacity']) {
            throw ValidationException::withMessages([
                'student_ids' => "Jumlah peserta melebihi kapasitas slot ({$payload['capacity']} siswa).",
            ]);
        }

        $room = $this->normalizeRoom($payload['room'] ?? null);
        $overlap = SessionTemplate::query()
            ->whereNull('legacy_deleted_at')
            ->where('day_of_week', $payload['day_of_week'])
            ->where('starts_at', '<', $payload['ends_at'])
            ->where('ends_at', '>', $payload['starts_at'])
            ->where(function ($query) use ($payload, $room): void {
                $query->where('legacy_school_class_id', $payload['school_class_id'])
                    ->orWhere('legacy_teacher_id', $payload['teacher_id']);

                if ($room !== null) {
                    $query->orWhere('room', $room);
                }
            })
            ->when($ignore, fn ($query) => $query->whereKeyNot($ignore->id))
            ->exists();

        if ($overlap) {
            throw ValidationException::withMessages([
                'starts_at' => 'Jadwal bentrok dengan kelas, guru, atau ruangan pada hari dan jam yang sama.',
            ]);
        }

        if ($studentIds === []) {
            return;
        }

        $studentConflict = RecurringSchedule::query()
            ->whereIn('student_id', $studentIds)
            ->where('day_of_week', $payload['day_of_week'])
            ->where('is_active', true)
            ->whereNull('legacy_deleted_at')
            ->when($ignore, fn ($query) => $query->where('session_template_id', '!=', $ignore->id))
            ->exists();

        if ($studentConflict) {
            throw ValidationException::withMessages([
                'student_ids' => 'Ada siswa yang sudah punya slot mingguan aktif lain pada hari yang sama.',
            ]);
        }
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function templatePayload(array $payload, bool $isActive = true): array
    {
        return [
            'environment_id' => null,
            'name' => null,
            'code' => null,
            'day_of_week' => $payload['day_of_week'],
            'starts_at' => $payload['starts_at'],
            'ends_at' => $payload['ends_at'],
            'capacity' => $payload['capacity'],
            'room' => $this->normalizeRoom($payload['room'] ?? null),
            'valid_from' => null,
            'valid_until' => null,
            'is_active' => $isActive,
            'legacy_school_class_id' => $payload['school_class_id'],
            'legacy_teacher_id' => $payload['teacher_id'],
            'legacy_topic' => $payload['topic'] ?? null,
            'legacy_deleted_at' => null,
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function legacyPayload(array $payload, bool $isActive = true): array
    {
        return [
            'school_class_id' => $payload['school_class_id'],
            'teacher_id' => $payload['teacher_id'],
            'room' => $this->normalizeRoom($payload['room'] ?? null),
            'capacity' => $payload['capacity'],
            'day_of_week' => $payload['day_of_week'],
            'starts_at' => $payload['starts_at'],
            'ends_at' => $payload['ends_at'],
            'topic' => $payload['topic'] ?? null,
            'is_active' => $isActive,
        ];
    }

    /**
     * @param  array<int, int>  $studentIds
     */
    private function replaceTargetMembership(SessionTemplate $template, array $studentIds, int $legacyScheduleId): void
    {
        $current = RecurringSchedule::query()
            ->where('session_template_id', $template->id)
            ->get();

        foreach ($current as $recurring) {
            if (! in_array((int) $recurring->student_id, $studentIds, true)) {
                $recurring->forceFill([
                    'is_active' => false,
                    'legacy_deleted_at' => now(),
                ])->save();
            }
        }

        foreach ($studentIds as $studentId) {
            $recurring = $current->firstWhere('student_id', $studentId);
            if ($recurring) {
                $recurring->forceFill([
                    'day_of_week' => $template->day_of_week,
                    'is_active' => (bool) $template->is_active,
                    'legacy_weekly_schedule_id' => $legacyScheduleId,
                    'legacy_deleted_at' => null,
                ])->save();

                continue;
            }

            RecurringSchedule::query()->create([
                'student_id' => $studentId,
                'session_template_id' => $template->id,
                'day_of_week' => $template->day_of_week,
                'valid_from' => null,
                'valid_until' => null,
                'is_active' => (bool) $template->is_active,
                'legacy_weekly_schedule_id' => $legacyScheduleId,
                'legacy_deleted_at' => null,
            ]);
        }
    }

    /**
     * @param  array<int, int>  $studentIds
     */
    private function replaceLegacyMembership(int $legacyScheduleId, array $studentIds): void
    {
        $existing = DB::table('student_weekly_schedule')
            ->where('weekly_schedule_id', $legacyScheduleId)
            ->get()
            ->keyBy('student_id');

        DB::table('student_weekly_schedule')
            ->where('weekly_schedule_id', $legacyScheduleId)
            ->whereNotIn('student_id', $studentIds === [] ? [-1] : $studentIds)
            ->delete();

        foreach ($studentIds as $studentId) {
            if ($existing->has($studentId)) {
                continue;
            }

            $pivotId = DB::table('student_weekly_schedule')->insertGetId([
                'weekly_schedule_id' => $legacyScheduleId,
                'student_id' => $studentId,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            RecurringSchedule::query()
                ->where('session_template_id', function ($query) use ($legacyScheduleId): void {
                    $query->select('id')
                        ->from('session_templates')
                        ->where('legacy_weekly_schedule_id', $legacyScheduleId)
                        ->limit(1);
                })
                ->where('student_id', $studentId)
                ->whereNull('legacy_student_weekly_schedule_id')
                ->update([
                    'legacy_student_weekly_schedule_id' => $pivotId,
                    'updated_at' => now(),
                ]);
        }
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<int, int>
     */
    private function studentIds(array $payload): array
    {
        return collect($payload['student_ids'] ?? [])
            ->map(fn ($id): int => (int) $id)
            ->unique()
            ->values()
            ->all();
    }

    private function normalizeRoom(?string $room): ?string
    {
        $room = trim((string) $room);

        return $room === '' ? null : $room;
    }

    private function usesLegacySource(): bool
    {
        return config('montessori.scheduling.write_source') === 'legacy';
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function createLegacy(array $payload): WeeklySchedule
    {
        $schedule = WeeklySchedule::query()->create([
            ...$this->legacyPayload($payload),
        ]);
        $schedule->students()->sync($this->studentIds($payload));

        return $schedule->fresh();
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function updateLegacy(WeeklySchedule $schedule, array $payload): WeeklySchedule
    {
        $schedule->update($this->legacyPayload($payload, (bool) $schedule->is_active));
        $schedule->students()->sync($this->studentIds($payload));

        return $schedule->fresh();
    }
}
