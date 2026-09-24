<?php

namespace App\Services\Scheduling;

use App\Models\Attendance;
use App\Models\ChildSessionBooking;
use App\Models\Observation;
use App\Models\SessionOccurrence;
use App\Models\User;
use App\Services\Entitlement\MakeupEligibilityService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class SessionOccurrenceCancellationService
{
    public function __construct(
        private readonly BookingEvidenceService $evidence,
        private readonly MakeupEligibilityService $eligibilities,
        private readonly LegacySessionCompatibilityWriter $compatibility,
    ) {}

    public function cancel(
        SessionOccurrence $occurrence,
        User $actor,
        string $reason,
    ): SessionOccurrence {
        $reason = trim($reason);

        if ($reason === '') {
            throw ValidationException::withMessages([
                'reason' => 'Alasan pembatalan session occurrence wajib dicatat.',
            ]);
        }

        return DB::transaction(function () use ($occurrence, $actor, $reason): SessionOccurrence {
            $locked = SessionOccurrence::query()
                ->whereKey($occurrence->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($locked->status === 'cancelled') {
                return $locked;
            }

            if ($locked->status === 'completed') {
                throw ValidationException::withMessages([
                    'occurrence_id' => 'Session occurrence yang sudah completed tidak boleh dibatalkan.',
                ]);
            }

            $this->assertNoRecordedSessionEvidence($locked);

            $activeBookings = ChildSessionBooking::query()
                ->where('session_occurrence_id', $locked->id)
                ->whereIn('status', ChildSessionBooking::ACTIVE_STATUSES)
                ->lockForUpdate()
                ->get();
            $creditedBookingIds = $activeBookings
                ->whereNotNull('session_credit_id')
                ->pluck('id');

            foreach ($activeBookings as $booking) {
                $booking->forceFill([
                    'status' => 'session_cancelled',
                    'active_on' => null,
                ])->save();
                $this->compatibility->syncBooking($booking);
            }

            $locked->forceFill([
                'status' => 'cancelled',
                'cancellation_reason' => $reason,
                'cancelled_by' => $actor->id,
                'cancelled_at' => now(),
            ])->save();
            $this->compatibility->syncOccurrence($locked);

            foreach ($creditedBookingIds as $bookingId) {
                $booking = ChildSessionBooking::query()->findOrFail($bookingId);
                $this->eligibilities->grantForSchoolCancellation($booking, $actor);
            }

            return $locked->fresh();
        });
    }

    private function assertNoRecordedSessionEvidence(SessionOccurrence $occurrence): void
    {
        $bookings = ChildSessionBooking::query()
            ->where('session_occurrence_id', $occurrence->id)
            ->get();

        foreach ($bookings as $booking) {
            if ($this->evidence->hasAnyMarkedAttendance($booking)
                || $this->evidence->hasObservationEvidence($booking)) {
                throw ValidationException::withMessages([
                    'occurrence_id' => 'Session yang sudah memiliki presensi atau observasi tidak boleh dibatalkan.',
                ]);
            }
        }

        if (! $occurrence->legacy_class_session_id) {
            return;
        }

        $hasMarkedAttendance = Attendance::query()
            ->where('class_session_id', $occurrence->legacy_class_session_id)
            ->whereNotNull('marked_at')
            ->exists();
        $hasObservation = Observation::query()
            ->where('class_session_id', $occurrence->legacy_class_session_id)
            ->exists();

        if ($hasMarkedAttendance || $hasObservation || $occurrence->presentations()->exists()) {
            throw ValidationException::withMessages([
                'occurrence_id' => 'Session yang sudah memiliki presensi, observasi, atau presentation evidence tidak boleh dibatalkan.',
            ]);
        }
    }
}
