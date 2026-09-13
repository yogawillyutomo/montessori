<?php

namespace App\Services\Alpha;

use App\Models\ClassSession;
use App\Models\SessionOccurrence;
use App\Models\SessionTemplate;
use App\Models\WeeklySchedule;

class LegacySessionBridgeService
{
    public function syncTemplate(WeeklySchedule $schedule): SessionTemplate
    {
        return SessionTemplate::query()->updateOrCreate(
            ['legacy_weekly_schedule_id' => $schedule->id],
            [
                'environment_id' => null,
                'name' => null,
                'code' => null,
                'day_of_week' => $schedule->day_of_week,
                'starts_at' => $schedule->starts_at?->format('H:i:s'),
                'ends_at' => $schedule->ends_at?->format('H:i:s'),
                'capacity' => $schedule->capacity,
                'room' => $schedule->room,
                'valid_from' => null,
                'valid_until' => null,
                'is_active' => (bool) $schedule->is_active,
                'legacy_school_class_id' => $schedule->school_class_id,
                'legacy_teacher_id' => $schedule->teacher_id,
                'legacy_topic' => $schedule->topic,
                'legacy_deleted_at' => null,
            ]
        );
    }

    public function syncOccurrence(ClassSession $session): SessionOccurrence
    {
        $templateId = null;

        if ($session->weekly_schedule_id) {
            $template = SessionTemplate::query()
                ->where('legacy_weekly_schedule_id', $session->weekly_schedule_id)
                ->first();

            if (! $template) {
                $legacySchedule = WeeklySchedule::query()->find($session->weekly_schedule_id);
                $template = $legacySchedule ? $this->syncTemplate($legacySchedule) : null;
            }

            $templateId = $template?->id;
        }

        return SessionOccurrence::query()->updateOrCreate(
            ['legacy_class_session_id' => $session->id],
            [
                'session_template_id' => $templateId,
                'environment_id' => null,
                'occurs_on' => $session->session_date?->toDateString(),
                'starts_at' => $session->starts_at?->format('H:i:s'),
                'ends_at' => $session->ends_at?->format('H:i:s'),
                'capacity' => $session->capacity,
                'room' => $session->room,
                'status' => $session->status,
                'opened_at' => null,
                'completed_at' => $session->closed_at,
                'completed_by' => $session->closed_by,
                'cancellation_reason' => null,
                'legacy_weekly_schedule_id' => $session->weekly_schedule_id,
                'legacy_school_class_id' => $session->school_class_id,
                'legacy_teacher_id' => $session->teacher_id,
                'legacy_topic' => $session->topic,
                'legacy_deleted_at' => null,
            ]
        );
    }

    public function markTemplateLegacyDeleted(WeeklySchedule $schedule): void
    {
        SessionTemplate::query()
            ->where('legacy_weekly_schedule_id', $schedule->id)
            ->update([
                'is_active' => false,
                'legacy_deleted_at' => now(),
            ]);
    }

    public function markOccurrenceLegacyDeleted(ClassSession $session): void
    {
        SessionOccurrence::query()
            ->where('legacy_class_session_id', $session->id)
            ->update(['legacy_deleted_at' => now()]);
    }
}
