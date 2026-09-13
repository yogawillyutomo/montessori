<?php

namespace App\Services\Entitlement;

use App\Models\ChildSessionBooking;
use App\Models\EntitlementPeriod;
use App\Models\SessionCredit;
use App\Models\User;
use App\Support\Alpha\Role;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CreditAllocationService
{
    public function allocate(
        ChildSessionBooking $booking,
        SessionCredit $credit,
        User $actor,
    ): ChildSessionBooking {
        $this->authorize($actor);

        return DB::transaction(function () use ($booking, $credit, $actor): ChildSessionBooking {
            $lockedBooking = ChildSessionBooking::query()
                ->whereKey($booking->id)
                ->lockForUpdate()
                ->firstOrFail();
            $lockedCredit = SessionCredit::query()
                ->whereKey($credit->id)
                ->lockForUpdate()
                ->firstOrFail();
            $period = EntitlementPeriod::query()
                ->whereKey($lockedCredit->entitlement_period_id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($lockedBooking->status !== 'scheduled') {
                throw ValidationException::withMessages([
                    'booking' => 'Session credit hanya dapat dialokasikan ke booking aktif berstatus scheduled.',
                ]);
            }

            if ($lockedBooking->child_enrollment_id === null
                || (int) $period->child_enrollment_id !== (int) $lockedBooking->child_enrollment_id) {
                throw ValidationException::withMessages([
                    'session_credit_id' => 'Session credit harus berasal dari entitlement period enrollment booking yang sama.',
                ]);
            }

            if ($lockedCredit->voided_at !== null || $lockedCredit->status !== 'available') {
                throw ValidationException::withMessages([
                    'session_credit_id' => 'Hanya session credit AVAILABLE dan tidak di-void yang dapat dialokasikan.',
                ]);
            }

            if ($lockedBooking->session_credit_id !== null) {
                if ((int) $lockedBooking->session_credit_id === (int) $lockedCredit->id) {
                    return $lockedBooking;
                }

                throw ValidationException::withMessages([
                    'session_credit_id' => 'Booking sudah memiliki session credit lain.',
                ]);
            }

            $duplicateActive = ChildSessionBooking::query()
                ->active()
                ->where('session_credit_id', $lockedCredit->id)
                ->whereKeyNot($lockedBooking->id)
                ->lockForUpdate()
                ->exists();

            if ($duplicateActive) {
                throw ValidationException::withMessages([
                    'session_credit_id' => 'Session credit sudah dipakai oleh booking aktif lain.',
                ]);
            }

            $bookingDate = $lockedBooking->active_on?->toDateString();
            if (! $bookingDate
                || $bookingDate < $period->period_start->toDateString()
                || $bookingDate > $period->period_end->toDateString()) {
                throw ValidationException::withMessages([
                    'session_credit_id' => 'Alokasi awal credit regular harus berada pada origin entitlement period. Cross-period continuation hanya melalui reschedule/makeup workflow.',
                ]);
            }

            $lockedCredit->forceFill(['status' => 'booked'])->save();
            $lockedBooking->forceFill([
                'session_credit_id' => $lockedCredit->id,
                'credit_allocated_by' => $actor->id,
                'credit_allocated_at' => now(),
            ])->save();

            return $lockedBooking->fresh(['sessionCredit.entitlementPeriod', 'creditAllocatedBy']);
        });
    }

    private function authorize(User $actor): void
    {
        if (! in_array($actor->role, [Role::SUPER_ADMIN, Role::ADMIN], true)) {
            throw ValidationException::withMessages([
                'actor' => 'Alokasi service entitlement hanya boleh dilakukan oleh super admin atau admin.',
            ]);
        }
    }
}
