<?php

namespace App\Services\Scheduling;

use App\Models\ClassSession;
use App\Models\SessionTemplate;
use App\Models\User;
use App\Models\WeeklySchedule;
use Illuminate\Validation\ValidationException;

class SessionWriteService
{
    public function __construct(
        private readonly TargetSessionWriteService $target,
        private readonly LegacySessionWriteService $legacy,
    ) {}

    /** @return array<int, int> */
    public function participantIds(ClassSession $session): array
    {
        return $this->usesLegacySource()
            ? $this->legacy->participantIds($session)
            : $this->target->participantIds($session);
    }

    /** @return array{teacher_id: ?int, school_class_id: ?int} */
    public function ownership(ClassSession $session): array
    {
        return $this->usesLegacySource()
            ? $this->legacy->ownership($session)
            : $this->target->ownership($session);
    }

    /** @return array{teacher_id: ?int, school_class_id: ?int} */
    public function scheduleOwnership(WeeklySchedule $schedule): array
    {
        if ($this->usesLegacySource()) {
            return [
                'teacher_id' => $schedule->teacher_id === null ? null : (int) $schedule->teacher_id,
                'school_class_id' => $schedule->school_class_id === null ? null : (int) $schedule->school_class_id,
            ];
        }

        $template = SessionTemplate::query()
            ->where('legacy_weekly_schedule_id', $schedule->id)
            ->whereNull('legacy_deleted_at')
            ->first();

        if (! $template) {
            throw ValidationException::withMessages([
                'weekly_schedule_id' => 'Canonical SessionTemplate untuk compatibility schedule tidak ditemukan.',
            ]);
        }

        return [
            'teacher_id' => $template->legacy_teacher_id === null ? null : (int) $template->legacy_teacher_id,
            'school_class_id' => $template->legacy_school_class_id === null ? null : (int) $template->legacy_school_class_id,
        ];
    }

    public function createFromSchedule(WeeklySchedule $schedule, string $date, ?User $actor): ClassSession
    {
        return $this->usesLegacySource()
            ? $this->legacy->createFromSchedule($schedule, $date)
            : $this->target->createFromSchedule($schedule, $date, $actor);
    }

    /** @param array<string, mixed> $attributes @param array<int, int> $studentIds */
    public function update(ClassSession $session, array $attributes, array $studentIds, ?User $actor): ClassSession
    {
        if (! $this->usesLegacySource() && ($attributes['status'] ?? 'planned') !== 'planned') {
            throw ValidationException::withMessages([
                'status' => 'Gunakan workflow close atau cancellation untuk mengubah lifecycle occurrence.',
            ]);
        }

        return $this->usesLegacySource()
            ? $this->legacy->update($session, $attributes, $studentIds)
            : $this->target->update($session, $attributes, $studentIds, $actor);
    }

    /** @param array{class_note?: ?string, follow_up_recommendation?: ?string} $notes */
    public function updateNotes(ClassSession $session, array $notes): void
    {
        if ($this->usesLegacySource()) {
            $this->legacy->updateNotes($session, $notes);

            return;
        }

        $this->target->updateNotes($session, $notes);
    }

    /** @param array{class_note?: ?string, follow_up_recommendation?: ?string} $notes */
    public function close(ClassSession $session, array $notes, ?User $actor): int
    {
        return $this->usesLegacySource()
            ? $this->legacy->close($session, $notes, $actor)
            : $this->target->close($session, $notes, $actor);
    }

    public function destroy(ClassSession $session, ?User $actor): void
    {
        if ($this->usesLegacySource()) {
            $this->legacy->destroy($session);

            return;
        }

        $this->target->destroy($session, $actor);
    }

    private function usesLegacySource(): bool
    {
        return config('montessori.session.write_source', 'target') === 'legacy';
    }
}
