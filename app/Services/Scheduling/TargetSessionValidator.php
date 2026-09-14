<?php

namespace App\Services\Scheduling;

use App\Models\BookingMovement;
use App\Models\ChildSessionBooking;
use App\Models\SessionOccurrence;
use Illuminate\Validation\ValidationException;

class TargetSessionValidator
{
    public function __construct(
        private readonly BookingEvidenceService $evidence,
    ) {}

    /** @param array<string, mixed> $payload */
    public function validateTime(array $payload, ?SessionOccurrence $ignore = null): void
    {
        if ($payload['ends_at'] <= $payload['starts_at']) {
            throw ValidationException::withMessages(['ends_at' => 'Jam selesai harus setelah jam mulai.']);
        }

        $room = $this->normalizeRoom($payload['room'] ?? null);
        $overlap = SessionOccurrence::query()
            ->whereDate('occurs_on', $payload['occurs_on'])
            ->where('status', '!=', 'cancelled')
            ->where('starts_at', '<', $payload['ends_at'])
            ->where('ends_at', '>', $payload['starts_at'])
            ->where(function ($query) use ($payload, $room): void {
                $query->where('legacy_school_class_id', $payload['legacy_school_class_id'] ?? null)
                    ->orWhere('legacy_teacher_id', $payload['legacy_teacher_id'] ?? null);
                if ($room !== null) {
                    $query->orWhere('room', $room);
                }
            })
            ->when($ignore, fn ($query) => $query->whereKeyNot($ignore->id))
            ->exists();

        if ($overlap) {
            throw ValidationException::withMessages([
                'starts_at' => 'Session target bentrok dengan kelas, guide, atau ruangan pada tanggal dan jam yang sama.',
            ]);
        }
    }

    /** @param array<string, mixed> $payload @param array<int, int> $studentIds */
    public function validateStudents(array $payload, array $studentIds, SessionOccurrence $ignore): void
    {
        if (($payload['capacity'] ?? null) !== null && count($studentIds) > (int) $payload['capacity']) {
            throw ValidationException::withMessages([
                'student_ids' => "Jumlah peserta melebihi kapasitas ({$payload['capacity']} anak).",
            ]);
        }

        if ($studentIds === []) {
            return;
        }

        $conflict = ChildSessionBooking::query()
            ->active()
            ->whereIn('student_id', $studentIds)
            ->whereDate('active_on', $payload['occurs_on'])
            ->where('session_occurrence_id', '!=', $ignore->id)
            ->exists();

        if ($conflict) {
            throw ValidationException::withMessages([
                'student_ids' => 'Ada anak yang sudah memiliki booking aktif lain pada tanggal target.',
            ]);
        }
    }

    public function assertBookingCanBeRemoved(ChildSessionBooking $booking): void
    {
        $hasMovement = BookingMovement::query()
            ->where('source_booking_id', $booking->id)
            ->orWhere('destination_booking_id', $booking->id)
            ->exists();

        if ($booking->session_credit_id !== null
            || $this->evidence->hasAnyMarkedAttendance($booking)
            || $this->evidence->hasObservationEvidence($booking)
            || $hasMovement) {
            throw ValidationException::withMessages([
                'student_ids' => 'Booking yang sudah memiliki credit, movement, attendance, atau observation evidence tidak boleh dikeluarkan dari roster.',
            ]);
        }
    }

    public function normalizeRoom(?string $room): ?string
    {
        $room = trim((string) $room);

        return $room === '' ? null : $room;
    }
}
