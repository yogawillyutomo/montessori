<?php

namespace App\Services\Migration;

use Closure;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class LegacyContractionReadinessService
{
    /**
     * @return array<string, array{
     *     legacy_total: int,
     *     mapped_total: int,
     *     missing_total: int,
     *     duplicate_total: int,
     *     mismatch_total: int,
     *     table_ready: bool
     * }>
     */
    public function report(): array
    {
        return [
            'weekly_schedule_templates' => $this->mappingCheck(
                'weekly_schedules',
                'session_templates',
                'legacy_weekly_schedule_id',
                fn (): int => $this->weeklyScheduleTemplateMismatchCount(),
            ),
            'student_recurring_schedules' => $this->mappingCheck(
                'student_weekly_schedule',
                'recurring_schedules',
                'legacy_student_weekly_schedule_id',
                fn (): int => $this->recurringScheduleMismatchCount(),
            ),
            'session_occurrences' => $this->mappingCheck(
                'class_sessions',
                'session_occurrences',
                'legacy_class_session_id',
                fn (): int => $this->sessionOccurrenceMismatchCount(),
            ),
            'session_bookings' => $this->mappingCheck(
                'class_session_student',
                'child_session_bookings',
                'legacy_class_session_student_id',
                fn (): int => $this->sessionBookingMismatchCount(),
            ),
            'marked_attendance_bookings' => $this->markedAttendanceCheck(),
        ];
    }

    public function dataReady(): bool
    {
        foreach ($this->report() as $check) {
            if (! $check['table_ready']
                || $check['missing_total'] > 0
                || $check['duplicate_total'] > 0
                || $check['mismatch_total'] > 0) {
                return false;
            }
        }

        return true;
    }

    /**
     * @return array{
     *     legacy_total: int,
     *     mapped_total: int,
     *     missing_total: int,
     *     duplicate_total: int,
     *     mismatch_total: int,
     *     table_ready: bool
     * }
     */
    private function mappingCheck(
        string $legacyTable,
        string $targetTable,
        string $legacyKey,
        Closure $mismatchResolver,
    ): array {
        $legacyExists = Schema::hasTable($legacyTable);
        $targetExists = Schema::hasTable($targetTable);
        $legacyTotal = $legacyExists ? DB::table($legacyTable)->count() : 0;

        if (! $legacyExists || ! $targetExists || ! Schema::hasColumn($targetTable, $legacyKey)) {
            return [
                'legacy_total' => $legacyTotal,
                'mapped_total' => 0,
                'missing_total' => $legacyTotal,
                'duplicate_total' => 0,
                'mismatch_total' => 0,
                'table_ready' => false,
            ];
        }

        $mappedTotal = DB::table("{$legacyTable} as legacy")
            ->join("{$targetTable} as target", "target.{$legacyKey}", '=', 'legacy.id')
            ->distinct()
            ->count('legacy.id');

        $duplicateTotal = DB::table($targetTable)
            ->whereNotNull($legacyKey)
            ->select($legacyKey)
            ->groupBy($legacyKey)
            ->havingRaw('COUNT(*) > 1')
            ->get()
            ->count();

        return [
            'legacy_total' => $legacyTotal,
            'mapped_total' => $mappedTotal,
            'missing_total' => max(0, $legacyTotal - $mappedTotal),
            'duplicate_total' => $duplicateTotal,
            'mismatch_total' => $mismatchResolver(),
            'table_ready' => true,
        ];
    }

    private function weeklyScheduleTemplateMismatchCount(): int
    {
        return DB::table('weekly_schedules as legacy')
            ->join('session_templates as target', 'target.legacy_weekly_schedule_id', '=', 'legacy.id')
            ->where(function ($query): void {
                $query->whereNull('target.legacy_school_class_id')
                    ->orWhereColumn('target.legacy_school_class_id', '!=', 'legacy.school_class_id')
                    ->orWhereNull('target.legacy_teacher_id')
                    ->orWhereColumn('target.legacy_teacher_id', '!=', 'legacy.teacher_id')
                    ->orWhereColumn('target.day_of_week', '!=', 'legacy.day_of_week');
            })
            ->count();
    }

    private function recurringScheduleMismatchCount(): int
    {
        return DB::table('student_weekly_schedule as legacy')
            ->join('weekly_schedules as schedule', 'schedule.id', '=', 'legacy.weekly_schedule_id')
            ->join('recurring_schedules as target', 'target.legacy_student_weekly_schedule_id', '=', 'legacy.id')
            ->leftJoin('session_templates as template', 'template.id', '=', 'target.session_template_id')
            ->where(function ($query): void {
                $query->whereColumn('target.student_id', '!=', 'legacy.student_id')
                    ->orWhereNull('target.legacy_weekly_schedule_id')
                    ->orWhereColumn('target.legacy_weekly_schedule_id', '!=', 'legacy.weekly_schedule_id')
                    ->orWhereColumn('target.day_of_week', '!=', 'schedule.day_of_week')
                    ->orWhereNull('target.session_template_id')
                    ->orWhereNull('template.legacy_weekly_schedule_id')
                    ->orWhereColumn('template.legacy_weekly_schedule_id', '!=', 'legacy.weekly_schedule_id');
            })
            ->count();
    }

    private function sessionOccurrenceMismatchCount(): int
    {
        return DB::table('class_sessions as legacy')
            ->join('session_occurrences as target', 'target.legacy_class_session_id', '=', 'legacy.id')
            ->leftJoin('session_templates as template', 'template.id', '=', 'target.session_template_id')
            ->where(function ($query): void {
                $query->whereNull('target.legacy_school_class_id')
                    ->orWhereColumn('target.legacy_school_class_id', '!=', 'legacy.school_class_id')
                    ->orWhereNull('target.legacy_teacher_id')
                    ->orWhereColumn('target.legacy_teacher_id', '!=', 'legacy.teacher_id')
                    ->orWhereColumn('target.occurs_on', '!=', 'legacy.session_date')
                    ->orWhere(function ($schedule): void {
                        $schedule->whereNotNull('legacy.weekly_schedule_id')
                            ->where(function ($binding): void {
                                $binding->whereNull('target.session_template_id')
                                    ->orWhereNull('template.legacy_weekly_schedule_id')
                                    ->orWhereColumn('template.legacy_weekly_schedule_id', '!=', 'legacy.weekly_schedule_id');
                            });
                    });
            })
            ->count();
    }

    private function sessionBookingMismatchCount(): int
    {
        return DB::table('class_session_student as legacy')
            ->join('child_session_bookings as target', 'target.legacy_class_session_student_id', '=', 'legacy.id')
            ->leftJoin('session_occurrences as occurrence', 'occurrence.id', '=', 'target.session_occurrence_id')
            ->where(function ($query): void {
                $query->whereColumn('target.student_id', '!=', 'legacy.student_id')
                    ->orWhereNull('target.legacy_class_session_id')
                    ->orWhereColumn('target.legacy_class_session_id', '!=', 'legacy.class_session_id')
                    ->orWhereNull('occurrence.id')
                    ->orWhereNull('occurrence.legacy_class_session_id')
                    ->orWhereColumn('occurrence.legacy_class_session_id', '!=', 'legacy.class_session_id');
            })
            ->count();
    }

    /**
     * @return array{
     *     legacy_total: int,
     *     mapped_total: int,
     *     missing_total: int,
     *     duplicate_total: int,
     *     mismatch_total: int,
     *     table_ready: bool
     * }
     */
    private function markedAttendanceCheck(): array
    {
        $attendanceExists = Schema::hasTable('attendances');
        $bookingExists = Schema::hasTable('child_session_bookings');
        $legacyTotal = $attendanceExists
            ? DB::table('attendances')->whereNotNull('marked_at')->count()
            : 0;

        if (! $attendanceExists || ! $bookingExists || ! Schema::hasColumn('attendances', 'child_session_booking_id')) {
            return [
                'legacy_total' => $legacyTotal,
                'mapped_total' => 0,
                'missing_total' => $legacyTotal,
                'duplicate_total' => 0,
                'mismatch_total' => 0,
                'table_ready' => false,
            ];
        }

        $mappedTotal = DB::table('attendances')
            ->whereNotNull('marked_at')
            ->whereNotNull('child_session_booking_id')
            ->count();

        $duplicateTotal = DB::table('attendances')
            ->whereNotNull('marked_at')
            ->whereNotNull('child_session_booking_id')
            ->select('child_session_booking_id')
            ->groupBy('child_session_booking_id')
            ->havingRaw('COUNT(*) > 1')
            ->get()
            ->count();

        $mismatchTotal = DB::table('attendances as attendance')
            ->join('child_session_bookings as booking', 'booking.id', '=', 'attendance.child_session_booking_id')
            ->whereNotNull('attendance.marked_at')
            ->where(function ($query): void {
                $query->whereColumn('attendance.student_id', '!=', 'booking.student_id')
                    ->orWhere(function ($session): void {
                        $session->whereNotNull('attendance.class_session_id')
                            ->where(function ($binding): void {
                                $binding->whereNull('booking.legacy_class_session_id')
                                    ->orWhereColumn('attendance.class_session_id', '!=', 'booking.legacy_class_session_id');
                            });
                    });
            })
            ->count();

        return [
            'legacy_total' => $legacyTotal,
            'mapped_total' => $mappedTotal,
            'missing_total' => max(0, $legacyTotal - $mappedTotal),
            'duplicate_total' => $duplicateTotal,
            'mismatch_total' => $mismatchTotal,
            'table_ready' => true,
        ];
    }
}
