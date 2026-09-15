<?php

namespace App\Services\Scheduling;

use App\Models\Attendance;
use App\Models\ChildSessionBooking;
use App\Models\Observation;
use App\Models\Presentation;

class BookingEvidenceService
{
    public function attendanceFor(ChildSessionBooking $booking): ?Attendance
    {
        $direct = Attendance::query()
            ->where('child_session_booking_id', $booking->id)
            ->first();

        if ($direct) {
            return $direct;
        }

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

    public function hasPresentationEvidence(ChildSessionBooking $booking): bool
    {
        return Presentation::query()
            ->where('session_occurrence_id', $booking->session_occurrence_id)
            ->where('student_id', $booking->student_id)
            ->exists();
    }

    public function isFulfilled(ChildSessionBooking $booking): bool
    {
        return $this->hasFulfilledAttendance($booking)
            || $this->hasObservationEvidence($booking);
    }
}
