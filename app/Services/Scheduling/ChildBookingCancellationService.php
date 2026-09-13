<?php

namespace App\Services\Scheduling;

use App\Models\ChildSessionBooking;
use App\Models\ClassSession;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ChildBookingCancellationService
{
    public function __construct(
        private readonly BookingEvidenceService $evidence,
    ) {}

    public function cancel(
        ChildSessionBooking $booking,
        User $actor,
        string $reason,
    ): ChildSessionBooking {
        $reason = trim($reason);

        if ($reason === '') {
            throw ValidationException::withMessages([
                'reason' => 'Alasan pembatalan booking wajib dicatat.',
            ]);
        }

        return DB::transaction(function () use ($booking, $actor, $reason): ChildSessionBooking {
            $locked = ChildSessionBooking::query()
                ->whereKey($booking->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($locked->status !== 'scheduled') {
                throw ValidationException::withMessages([
                    'booking_id' => 'Hanya booking aktif berstatus scheduled yang dapat dibatalkan.',
                ]);
            }

            if ($this->evidence->hasAnyMarkedAttendance($locked) || $this->evidence->hasObservationEvidence($locked)) {
                throw ValidationException::withMessages([
                    'booking_id' => 'Booking yang sudah memiliki presensi atau observasi tidak boleh dibatalkan sebagai child cancellation.',
                ]);
            }

            $locked->forceFill([
                'status' => 'cancelled',
                'active_on' => null,
                'cancellation_reason' => $reason,
                'cancelled_by' => $actor->id,
                'cancelled_at' => now(),
            ])->save();

            $this->syncLegacyCancellation($locked);

            return $locked->fresh();
        });
    }

    private function syncLegacyCancellation(ChildSessionBooking $booking): void
    {
        if (! $booking->legacy_class_session_id) {
            return;
        }

        $attendance = $this->evidence->attendanceFor($booking);
        if ($attendance && $attendance->marked_at === null) {
            $attendance->delete();
        }

        $legacySession = ClassSession::query()->find($booking->legacy_class_session_id);
        if (! $legacySession) {
            return;
        }

        if ($legacySession->students()->whereKey($booking->student_id)->exists()) {
            $legacySession->students()->detach($booking->student_id);
        }
    }
}
