<?php

namespace App\Services\Migration;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class LegacyContractionAuditService
{
    public function __construct(
        private readonly LegacyContractionReadinessService $readiness,
    ) {}

    /**
     * @return array<string, array{ready: bool, detail: string}>
     */
    public function report(): array
    {
        $reconciliation = $this->readiness->report();
        $observationCheck = $reconciliation['scheduled_observation_occurrences'] ?? null;

        return [
            'data_reconciliation' => [
                'ready' => $this->readiness->dataReady(),
                'detail' => 'Legacy-to-target identity reconciliation must be clean before any contraction.',
            ],
            'scheduled_observation_target_link' => [
                'ready' => $observationCheck !== null
                    && $observationCheck['table_ready']
                    && $observationCheck['missing_total'] === 0
                    && $observationCheck['mismatch_total'] === 0,
                'detail' => 'Every observation with legacy session context must resolve to the canonical SessionOccurrence.',
            ],
            'all_attendance_booking_link' => [
                'ready' => $this->allAttendanceRowsHaveCanonicalBooking(),
                'detail' => 'Every attendance row, including unmarked compatibility rows, must belong to a canonical ChildSessionBooking.',
            ],
            'observation_legacy_fk_retired' => [
                'ready' => Schema::hasTable('observations')
                    && ! Schema::hasColumn('observations', 'class_session_id'),
                'detail' => 'observations.class_session_id still exists as a compatibility foreign key and blocks dropping class_sessions.',
            ],
            'attendance_legacy_fk_retired' => [
                'ready' => Schema::hasTable('attendances')
                    && ! Schema::hasColumn('attendances', 'class_session_id'),
                'detail' => 'attendances.class_session_id still exists as a compatibility foreign key and blocks dropping class_sessions.',
            ],
            'session_notes_target_home' => [
                'ready' => false,
                'detail' => 'class_note and follow_up_recommendation still have no approved target-domain home; do not invent one during contraction.',
            ],
            'compatibility_read_model_retired' => [
                'ready' => false,
                'detail' => 'SchedulingReadModelService still returns legacy WeeklySchedule/ClassSession compatibility handles to current UI routes.',
            ],
            'legacy_rollback_retired' => [
                'ready' => false,
                'detail' => 'MONTESSORI_SESSION_WRITE_SOURCE=legacy and legacy session writers remain an intentional rollback path.',
            ],
        ];
    }

    public function deletionReady(): bool
    {
        foreach ($this->report() as $check) {
            if (! $check['ready']) {
                return false;
            }
        }

        return true;
    }

    private function allAttendanceRowsHaveCanonicalBooking(): bool
    {
        if (! Schema::hasTable('attendances')
            || ! Schema::hasTable('child_session_bookings')
            || ! Schema::hasColumn('attendances', 'child_session_booking_id')) {
            return false;
        }

        if (DB::table('attendances')->whereNull('child_session_booking_id')->exists()) {
            return false;
        }

        return ! DB::table('attendances as attendance')
            ->leftJoin('child_session_bookings as booking', 'booking.id', '=', 'attendance.child_session_booking_id')
            ->where(function ($query): void {
                $query->whereNull('booking.id')
                    ->orWhereColumn('attendance.student_id', '!=', 'booking.student_id')
                    ->orWhere(function ($session): void {
                        $session->whereNotNull('attendance.class_session_id')
                            ->whereNotNull('booking.legacy_class_session_id')
                            ->whereColumn('attendance.class_session_id', '!=', 'booking.legacy_class_session_id');
                    });
            })
            ->exists();
    }
}
