<?php

namespace App\Services\Scheduling;

use App\Models\Attendance;
use App\Models\ChildSessionBooking;
use App\Models\Observation;

class BookingEvidenceService
{
    public function attendanceFor(ChildSessionBooking $booking): ?Attendance
    {
        if (! $booking->legacy_class_session_id) {
            return null;
        }

        return Attendance::query()
            ->where('class_session_id', $booking->legacy_class_session_id)
            ->where('student_id', $booking->student_id)
            ->first();
    }

    public function hasAnyMarkedAttendance(ChildSessionBooking $booking): bool
    {
        return $this->attendanceFor($booking)?->marked_at !== null;
    }

    public function hasFulfilledAttendance(ChildSessionBooking $booking): bool
    {
        $attendance = $this->attendanceFor($booking);

        return $attendance !== null
            && $attendance->marked_at !== null
            && in_array($attendance->status, ['present', 'late'], true);
    }

    public function hasObservationEvidence(ChildSessionBooking $booking): bool
    {
        if (! $booking->legacy_class_session_id) {
            return false;
        }

        return Observation::query()
            ->where('class_session_id', $booking->legacy_class_session_id)
            ->where('student_id', $booking->student_id)
            ->exists();
    }

    public function isFulfilled(ChildSessionBooking $booking): bool
    {
        return $this->hasFulfilledAttendance($booking)
            || $this->hasObservationEvidence($booking);
    }
}
