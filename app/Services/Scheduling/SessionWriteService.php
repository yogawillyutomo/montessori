<?php

namespace App\Services\Scheduling;

use App\Models\ClassSession;
use App\Models\SessionOccurrence;
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
    public function participantIdsForOccurrence(SessionOccurrence $occurrence): array
    {
        if (! $this->usesLegacySource()) {
            return $this->target->participantIdsForOccurrence($occurrence);
        }

        $legacy = $this->legacySessionFor($occurrence);

        return $this->legacy->participantIds($legacy);
    }

    /** @return array{teacher_id: ?int, school_class_id: ?int} */
    public function ownershipForOccurrence(SessionOccurrence $occurrence): array
    {
        return $this->target->ownershipForOccurrence($occurrence);
    }

    /** @return array{teacher_id: ?int, school_class_id: ?int} */
    public function scheduleOwnershipForTemplate(SessionTemplate $template): array
    {
        return [
            'teacher_id' => $template->legacy_teacher_id === null ? null : (int) $template->legacy_teacher_id,
            'school_class_id' => $template->legacy_school_class_id === null ? null : (int) $template->legacy_school_class_id,
        ];
    }

    public function createFromTemplate(SessionTemplate $template, string $date, ?User $actor): SessionOccurrence
    {
        if (! $this->usesLegacySource()) {
            return $this->target->createFromTemplate($template, $date, $actor);
        }

        $legacySchedule = WeeklySchedule::query()
            ->whereKey($template->legacy_weekly_schedule_id)
            ->firstOrFail();
        $legacySession = $this->legacy->createFromSchedule($legacySchedule, $date);

        return SessionOccurrence::query()
            ->where('legacy_class_session_id', $legacySession->id)
            ->firstOrFail();
    }

    /** @param array<string, mixed> $attributes @param array<int, int> $studentIds */
    public function updateOccurrence(
        SessionOccurrence $occurrence,
        array $attributes,
        array $studentIds,
        ?User $actor,
    ): SessionOccurrence {
        if (($attributes['status'] ?? 'planned') !== 'planned') {
            throw ValidationException::withMessages([
                'status' => 'Gunakan workflow close atau cancellation untuk mengubah lifecycle occurrence.',
            ]);
        }

        if ($this->usesLegacySource()) {
            $this->legacy->update($this->legacySessionFor($occurrence), $attributes, $studentIds);

            return $occurrence->fresh();
        }

        return $this->target->updateOccurrence($occurrence, $attributes, $studentIds, $actor);
    }

    /** @param array{class_note?: ?string, follow_up_recommendation?: ?string} $notes */
    public function updateOccurrenceNotes(SessionOccurrence $occurrence, array $notes): void
    {
        if ($this->usesLegacySource()) {
            $this->legacy->updateNotes($this->legacySessionFor($occurrence), $notes);

            return;
        }

        $this->target->updateOccurrenceNotes($occurrence, $notes);
    }

    /** @param array{class_note?: ?string, follow_up_recommendation?: ?string} $notes */
    public function closeOccurrence(SessionOccurrence $occurrence, array $notes, ?User $actor): int
    {
        if ($this->usesLegacySource()) {
            return $this->legacy->close($this->legacySessionFor($occurrence), $notes, $actor);
        }

        return $this->target->closeOccurrence($occurrence, $notes, $actor);
    }

    public function destroyOccurrence(SessionOccurrence $occurrence, ?User $actor): void
    {
        if ($this->usesLegacySource()) {
            $this->legacy->destroy($this->legacySessionFor($occurrence));

            return;
        }

        $this->target->destroyOccurrence($occurrence, $actor);
    }

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

    private function legacySessionFor(SessionOccurrence $occurrence): ClassSession
    {
        if ($occurrence->legacy_class_session_id === null) {
            throw ValidationException::withMessages([
                'session' => 'Legacy rollback membutuhkan compatibility ClassSession yang masih tersedia.',
            ]);
        }

        return ClassSession::query()->findOrFail($occurrence->legacy_class_session_id);
    }

    private function usesLegacySource(): bool
    {
        return config('montessori.session.write_source', 'target') === 'legacy';
    }
}
