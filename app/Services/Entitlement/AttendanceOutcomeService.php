<?php

namespace App\Services\Entitlement;

use App\Models\Attendance;
use App\Models\ChildSessionBooking;
use App\Models\ClassSession;
use App\Models\MakeupEligibility;
use App\Models\SessionCredit;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class AttendanceOutcomeService
{
    public function __construct(
        private readonly MakeupEligibilityService $eligibilities,
    ) {}

    public function recordForLegacySession(
        ClassSession $session,
        int $studentId,
        string $status,
        ?string $note,
        User $actor,
    ): Attendance {
        $booking = ChildSessionBooking::query()
            ->where('legacy_class_session_id', $session->id)
            ->where('student_id', $studentId)
            ->first();

        if (! $booking) {
            throw ValidationException::withMessages([
                'attendance' => 'Booking target untuk presensi legacy tidak ditemukan.',
            ]);
        }

        return $this->recordForBooking($booking, $status, $note, $actor);
    }

    public function recordForBooking(
        ChildSessionBooking $booking,
        string $status,
        ?string $note,
        User $actor,
    ): Attendance {
        if (! array_key_exists($status, Attendance::STATUSES)) {
            throw ValidationException::withMessages([
                'status' => 'Status attendance tidak valid.',
            ]);
        }

        return DB::transaction(function () use ($booking, $status, $note, $actor): Attendance {
            $lockedBooking = ChildSessionBooking::query()
                ->whereKey($booking->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($lockedBooking->status !== 'scheduled') {
                throw ValidationException::withMessages([
                    'booking_id' => 'Attendance hanya dapat dicatat pada booking aktif berstatus scheduled.',
                ]);
            }

            $attendance = Attendance::query()
                ->where('child_session_booking_id', $lockedBooking->id)
                ->lockForUpdate()
                ->first();

            if (! $attendance && $lockedBooking->legacy_class_session_id !== null) {
                $attendance = Attendance::query()
                    ->where('class_session_id', $lockedBooking->legacy_class_session_id)
                    ->where('student_id', $lockedBooking->student_id)
                    ->lockForUpdate()
                    ->first();
            }

            if (! $attendance) {
                $attendance = new Attendance([
                    'class_session_id' => $lockedBooking->legacy_class_session_id,
                    'student_id' => $lockedBooking->student_id,
                    'child_session_booking_id' => $lockedBooking->id,
                ]);
            } elseif ($attendance->child_session_booking_id === null) {
                $attendance->child_session_booking_id = $lockedBooking->id;
            } elseif ((int) $attendance->child_session_booking_id !== (int) $lockedBooking->id) {
                throw ValidationException::withMessages([
                    'attendance' => 'Attendance sudah terhubung ke booking lain.',
                ]);
            }

            $previousStatus = $attendance->exists && $attendance->marked_at !== null
                ? (string) $attendance->status
                : 'unmarked';

            if ($previousStatus !== $status && $lockedBooking->outgoingMovement()->exists()) {
                throw ValidationException::withMessages([
                    'attendance' => 'Outcome booking yang sudah memiliki downstream movement harus direkonsiliasi sebelum dikoreksi.',
                ]);
            }

            $isMarked = $status !== 'unmarked';
            $attendance->forceFill([
                'class_session_id' => $lockedBooking->legacy_class_session_id,
                'student_id' => $lockedBooking->student_id,
                'child_session_booking_id' => $lockedBooking->id,
                'status' => $isMarked ? $status : 'unmarked',
                'note' => $note,
                'marked_by' => $isMarked ? $actor->id : null,
                'marked_at' => $isMarked ? now() : null,
            ])->save();

            if ($lockedBooking->session_credit_id !== null) {
                $this->settleCredit($lockedBooking, $attendance, $status, $actor);
            }

            return $attendance->fresh(['childSessionBooking', 'markedBy']);
        });
    }

    private function settleCredit(
        ChildSessionBooking $booking,
        Attendance $attendance,
        string $status,
        User $actor,
    ): void {
        $credit = SessionCredit::query()
            ->whereKey($booking->session_credit_id)
            ->lockForUpdate()
            ->firstOrFail();

        if ($credit->voided_at !== null) {
            throw ValidationException::withMessages([
                'session_credit_id' => 'Voided session credit tidak dapat diselesaikan melalui attendance.',
            ]);
        }

        $period = $credit->entitlementPeriod()
            ->with('sessionPlan')
            ->firstOrFail();
        $plan = $period->sessionPlan;

        if (! $plan) {
            throw ValidationException::withMessages([
                'session_credit_id' => 'Session plan untuk credit tidak ditemukan.',
            ]);
        }

        $eligibility = MakeupEligibility::query()
            ->where('session_credit_id', $credit->id)
            ->lockForUpdate()
            ->first();

        if (in_array($status, ['present', 'late'], true)) {
            $credit->forceFill(['status' => 'used'])->save();

            if ($eligibility?->status === 'pending') {
                if ((int) $eligibility->source_booking_id === (int) $booking->id) {
                    $this->eligibilities->revoke(
                        $eligibility,
                        $actor,
                        'Source attendance corrected to fulfilled before makeup was used.',
                    );
                } else {
                    $this->eligibilities->fulfill($eligibility, $booking, $actor);
                }
            }

            return;
        }

        if ($status === 'unmarked') {
            $credit->forceFill(['status' => 'booked'])->save();

            if ($eligibility?->status === 'fulfilled'
                && (int) $eligibility->fulfilled_by_booking_id === (int) $booking->id) {
                $this->eligibilities->reopen($eligibility);
            } elseif ($eligibility?->status === 'pending'
                && (int) $eligibility->source_booking_id === (int) $booking->id) {
                $this->eligibilities->revoke(
                    $eligibility,
                    $actor,
                    'Source attendance was reset to UNMARKED.',
                );
            }

            return;
        }

        $reasonCategory = $status === 'absent' ? 'no_show' : $status;
        $makeupPolicy = (array) ($plan->makeup_policy ?? []);
        $isMakeupEligible = (bool) ($makeupPolicy[$reasonCategory] ?? false);

        if ($isMakeupEligible) {
            $credit->forceFill(['status' => 'booked'])->save();
            $this->eligibilities->grantForAttendance(
                $booking,
                $attendance,
                $reasonCategory,
                $actor,
            );

            return;
        }

        $credit->forceFill(['status' => 'forfeited'])->save();

        if ($eligibility && $eligibility->status !== 'revoked') {
            $this->eligibilities->revoke(
                $eligibility,
                $actor,
                'Latest attendance outcome is not eligible for makeup under the session plan policy.',
            );
        }
    }
}
