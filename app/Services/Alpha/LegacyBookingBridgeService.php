<?php

namespace App\Services\Alpha;

use App\Models\ChildSessionBooking;
use App\Models\ClassSession;
use App\Models\ClassSessionStudent;
use App\Models\RecurringSchedule;
use App\Models\SessionOccurrence;
use App\Models\SessionTemplate;
use App\Models\StudentWeeklySchedule;
use App\Models\WeeklySchedule;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class LegacyBookingBridgeService
{
    public function syncRecurringPivot(StudentWeeklySchedule $pivot): ?RecurringSchedule
    {
        if (! Schema::hasTable('recurring_schedules')) {
            return null;
        }

        $schedule = WeeklySchedule::query()->find($pivot->weekly_schedule_id);
        if (! $schedule) {
            return null;
        }

        return $this->syncRecurringRow(
            (int) $pivot->id,
            (int) $pivot->student_id,
            $schedule,
            $pivot->created_at,
            $pivot->updated_at,
        );
    }

    public function markRecurringPivotDeleted(StudentWeeklySchedule $pivot): void
    {
        if (! Schema::hasTable('recurring_schedules')) {
            return;
        }

        RecurringSchedule::query()
            ->where('legacy_student_weekly_schedule_id', $pivot->id)
            ->update([
                'is_active' => false,
                'legacy_deleted_at' => now(),
            ]);
    }

    public function syncRecurringSchedulesForSchedule(WeeklySchedule $schedule): void
    {
        if (! Schema::hasTable('recurring_schedules')) {
            return;
        }

        $rows = DB::table('student_weekly_schedule')
            ->where('weekly_schedule_id', $schedule->id)
            ->get();
        $currentPivotIds = $rows->pluck('id')->map(fn ($id): int => (int) $id)->all();

        $stale = RecurringSchedule::query()
            ->where('legacy_weekly_schedule_id', $schedule->id);

        if ($currentPivotIds !== []) {
            $stale->whereNotIn('legacy_student_weekly_schedule_id', $currentPivotIds);
        }

        $stale->update([
            'is_active' => false,
            'legacy_deleted_at' => now(),
        ]);

        foreach ($rows as $row) {
            $this->syncRecurringRow(
                (int) $row->id,
                (int) $row->student_id,
                $schedule,
                $row->created_at,
                $row->updated_at,
            );
        }
    }

    public function markRecurringSchedulesForLegacyScheduleDeleted(WeeklySchedule $schedule): void
    {
        if (! Schema::hasTable('recurring_schedules')) {
            return;
        }

        RecurringSchedule::query()
            ->where('legacy_weekly_schedule_id', $schedule->id)
            ->update([
                'is_active' => false,
                'legacy_deleted_at' => now(),
            ]);
    }

    public function syncBookingPivot(ClassSessionStudent $pivot): ?ChildSessionBooking
    {
        if (! Schema::hasTable('child_session_bookings')) {
            return null;
        }

        $session = ClassSession::query()->find($pivot->class_session_id);
        if (! $session) {
            return null;
        }

        return $this->syncBookingRow(
            (int) $pivot->id,
            (int) $pivot->student_id,
            $session,
            $pivot->created_at,
            $pivot->updated_at,
        );
    }

    public function markBookingPivotDeleted(ClassSessionStudent $pivot): void
    {
        if (! Schema::hasTable('child_session_bookings')) {
            return;
        }

        ChildSessionBooking::query()
            ->where('legacy_class_session_student_id', $pivot->id)
            ->update([
                'status' => 'cancelled',
                'active_on' => null,
                'legacy_deleted_at' => now(),
            ]);
    }

    public function syncBookingsForSession(ClassSession $session): void
    {
        if (! Schema::hasTable('child_session_bookings')) {
            return;
        }

        $rows = DB::table('class_session_student')
            ->where('class_session_id', $session->id)
            ->get();
        $currentPivotIds = $rows->pluck('id')->map(fn ($id): int => (int) $id)->all();

        $stale = ChildSessionBooking::query()
            ->where('legacy_class_session_id', $session->id);

        if ($currentPivotIds !== []) {
            $stale->whereNotIn('legacy_class_session_student_id', $currentPivotIds);
        }

        $stale->update([
            'status' => 'cancelled',
            'active_on' => null,
            'legacy_deleted_at' => now(),
        ]);

        foreach ($rows as $row) {
            $this->syncBookingRow(
                (int) $row->id,
                (int) $row->student_id,
                $session,
                $row->created_at,
                $row->updated_at,
            );
        }
    }

    public function markBookingsForLegacySessionDeleted(ClassSession $session): void
    {
        if (! Schema::hasTable('child_session_bookings')) {
            return;
        }

        ChildSessionBooking::query()
            ->where('legacy_class_session_id', $session->id)
            ->update([
                'status' => 'cancelled',
                'active_on' => null,
                'legacy_deleted_at' => now(),
            ]);
    }

    private function syncRecurringRow(
        int $pivotId,
        int $studentId,
        WeeklySchedule $schedule,
        mixed $createdAt,
        mixed $updatedAt,
    ): RecurringSchedule {
        $template = SessionTemplate::query()
            ->where('legacy_weekly_schedule_id', $schedule->id)
            ->first();

        if (! $template) {
            $template = app(LegacySessionBridgeService::class)->syncTemplate($schedule);
        }

        return RecurringSchedule::query()->updateOrCreate(
            ['legacy_student_weekly_schedule_id' => $pivotId],
            [
                'student_id' => $studentId,
                'session_template_id' => $template->id,
                'day_of_week' => $schedule->day_of_week,
                'valid_from' => null,
                'valid_until' => null,
                'is_active' => (bool) $schedule->is_active,
                'legacy_weekly_schedule_id' => $schedule->id,
                'legacy_deleted_at' => null,
                'created_at' => $createdAt,
                'updated_at' => $updatedAt,
            ]
        );
    }

    private function syncBookingRow(
        int $pivotId,
        int $studentId,
        ClassSession $session,
        mixed $createdAt,
        mixed $updatedAt,
    ): ChildSessionBooking {
        $occurrence = SessionOccurrence::query()
            ->where('legacy_class_session_id', $session->id)
            ->first();

        if (! $occurrence) {
            $occurrence = app(LegacySessionBridgeService::class)->syncOccurrence($session);
        }

        $cancelled = $session->status === 'cancelled';

        return ChildSessionBooking::query()->updateOrCreate(
            ['legacy_class_session_student_id' => $pivotId],
            [
                'student_id' => $studentId,
                'session_occurrence_id' => $occurrence->id,
                'booking_type' => 'regular',
                'status' => $cancelled ? 'session_cancelled' : 'scheduled',
                'active_on' => $cancelled ? null : $session->session_date?->toDateString(),
                'source_type' => 'legacy',
                'created_by' => null,
                'legacy_class_session_id' => $session->id,
                'legacy_deleted_at' => null,
                'created_at' => $createdAt,
                'updated_at' => $updatedAt,
            ]
        );
    }
}
