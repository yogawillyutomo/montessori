<?php

namespace App\Services\Entitlement;

use App\Models\Attendance;
use App\Models\ChildSessionBooking;
use App\Models\MakeupEligibility;
use App\Models\User;
use Illuminate\Validation\ValidationException;

class MakeupEligibilityService
{
    public function grantForAttendance(
        ChildSessionBooking $booking,
        Attendance $attendance,
        string $reasonCategory,
        User $actor,
    ): MakeupEligibility {
        if (! in_array($reasonCategory, ['sick', 'excused', 'no_show'], true)) {
            throw ValidationException::withMessages([
                'reason_category' => 'Attendance hanya dapat memberi eligibility untuk sick, excused, atau no_show.',
            ]);
        }

        $this->assertActiveBookedCredit($booking);

        $eligibility = MakeupEligibility::query()
            ->where('session_credit_id', $booking->session_credit_id)
            ->first();

        if (! $eligibility) {
            return MakeupEligibility::query()->create([
                'source_booking_id' => $booking->id,
                'session_credit_id' => $booking->session_credit_id,
                'source_attendance_id' => $attendance->id,
                'reason_category' => $reasonCategory,
                'status' => 'pending',
                'granted_by' => $actor->id,
                'granted_at' => now(),
            ]);
        }

        if ($eligibility->status !== 'pending') {
            $eligibility->forceFill([
                'status' => 'pending',
                'fulfilled_by_booking_id' => null,
                'fulfilled_at' => null,
                'revoked_by' => null,
                'revoked_at' => null,
                'revocation_reason' => null,
            ])->save();
        }

        return $eligibility->fresh();
    }

    public function grantForSchoolCancellation(
        ChildSessionBooking $booking,
        User $actor,
    ): MakeupEligibility {
        $this->assertActiveBookedCredit($booking);

        $existing = MakeupEligibility::query()
            ->where('session_credit_id', $booking->session_credit_id)
            ->first();

        if ($existing) {
            if ($existing->status !== 'pending') {
                throw ValidationException::withMessages([
                    'session_credit_id' => 'Session credit sudah memiliki makeup eligibility terminal dan tidak dapat diberi grant school cancellation baru.',
                ]);
            }

            return $existing;
        }

        return MakeupEligibility::query()->create([
            'source_booking_id' => $booking->id,
            'session_credit_id' => $booking->session_credit_id,
            'source_attendance_id' => null,
            'reason_category' => 'school_cancel',
            'status' => 'pending',
            'granted_by' => $actor->id,
            'granted_at' => now(),
        ]);
    }

    public function reopen(MakeupEligibility $eligibility): MakeupEligibility
    {
        $eligibility->forceFill([
            'status' => 'pending',
            'fulfilled_by_booking_id' => null,
            'fulfilled_at' => null,
            'revoked_by' => null,
            'revoked_at' => null,
            'revocation_reason' => null,
        ])->save();

        return $eligibility->fresh();
    }

    public function fulfill(
        MakeupEligibility $eligibility,
        ChildSessionBooking $booking,
        User $actor,
    ): MakeupEligibility {
        if ((int) $eligibility->session_credit_id !== (int) $booking->session_credit_id) {
            throw ValidationException::withMessages([
                'fulfilled_by_booking_id' => 'Booking pemenuh makeup harus memakai session credit yang sama.',
            ]);
        }

        $eligibility->forceFill([
            'status' => 'fulfilled',
            'fulfilled_by_booking_id' => $booking->id,
            'fulfilled_at' => now(),
            'revoked_by' => null,
            'revoked_at' => null,
            'revocation_reason' => null,
        ])->save();

        return $eligibility->fresh();
    }

    public function revoke(
        MakeupEligibility $eligibility,
        User $actor,
        string $reason,
    ): MakeupEligibility {
        $reason = trim($reason);

        if ($reason === '') {
            throw ValidationException::withMessages([
                'revocation_reason' => 'Alasan revocation makeup eligibility wajib dicatat.',
            ]);
        }

        $eligibility->forceFill([
            'status' => 'revoked',
            'fulfilled_by_booking_id' => null,
            'fulfilled_at' => null,
            'revoked_by' => $actor->id,
            'revoked_at' => now(),
            'revocation_reason' => $reason,
        ])->save();

        return $eligibility->fresh();
    }

    private function assertActiveBookedCredit(ChildSessionBooking $booking): void
    {
        if ($booking->session_credit_id === null) {
            throw ValidationException::withMessages([
                'session_credit_id' => 'Booking tanpa session credit tidak dapat memiliki makeup eligibility.',
            ]);
        }

        $credit = $booking->sessionCredit()->first();
        if (! $credit || $credit->voided_at !== null || $credit->status !== 'booked') {
            throw ValidationException::withMessages([
                'session_credit_id' => 'Makeup eligibility hanya dapat diberikan untuk session credit BOOKED yang aktif.',
            ]);
        }
    }
}
