<?php

namespace App\Services\Migration;

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
            ),
            'student_recurring_schedules' => $this->mappingCheck(
                'student_weekly_schedule',
                'recurring_schedules',
                'legacy_student_weekly_schedule_id',
            ),
            'session_occurrences' => $this->mappingCheck(
                'class_sessions',
                'session_occurrences',
                'legacy_class_session_id',
            ),
            'session_bookings' => $this->mappingCheck(
                'class_session_student',
                'child_session_bookings',
                'legacy_class_session_student_id',
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
    private function mappingCheck(string $legacyTable, string $targetTable, string $legacyKey): array
    {
        $legacyExists = Schema::hasTable($legacyTable);
        $targetExists = Schema::hasTable($targetTable);
        $legacyTotal = $legacyExists ? DB::table($legacyTable)->count() : 0;

        if (! $legacyExists || ! $targetExists) {
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
            'mismatch_total' => 0,
            'table_ready' => true,
        ];
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
                            ->whereNotNull('booking.legacy_class_session_id')
                            ->whereColumn('attendance.class_session_id', '!=', 'booking.legacy_class_session_id');
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
