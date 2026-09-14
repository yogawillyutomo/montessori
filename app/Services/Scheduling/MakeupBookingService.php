<?php

namespace App\Services\Scheduling;

use App\Models\BookingMovement;
use App\Models\ChildSessionBooking;
use App\Models\MakeupEligibility;
use App\Models\SessionOccurrence;
use App\Models\User;
use App\Support\Alpha\Role;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class MakeupBookingService
{
    public function __construct(
        private readonly BookingLineageService $lineage,
        private readonly BookingEvidenceService $evidence,
        private readonly LegacySessionCompatibilityWriter $compatibility,
    ) {}

    public function schedule(
        MakeupEligibility $eligibility,
        SessionOccurrence $destinationOccurrence,
        User $actor,
        string $reason,
        bool $capacityOverride = false,
        ?string $capacityOverrideReason = null,
    ): BookingMovement {
        $reason = trim($reason);
        $capacityOverrideReason = trim((string) $capacityOverrideReason);
        $this->authorize($actor);

        if ($reason === '') {
            throw ValidationException::withMessages([
                'reason' => 'Alasan makeup wajib dicatat.',
            ]);
        }

        if ($capacityOverride && ! in_array($actor->role, [Role::SUPER_ADMIN, Role::ADMIN], true)) {
            throw ValidationException::withMessages([
                'capacity_override' => 'Capacity override hanya boleh dilakukan oleh super admin atau admin.',
            ]);
        }

        if ($capacityOverride && $capacityOverrideReason === '') {
            throw ValidationException::withMessages([
                'capacity_override_reason' => 'Alasan capacity override wajib dicatat.',
            ]);
        }

        return DB::transaction(function () use (
            $eligibility,
            $destinationOccurrence,
            $actor,
            $reason,
            $capacityOverride,
            $capacityOverrideReason,
        ): BookingMovement {
            $lockedEligibility = MakeupEligibility::query()
                ->whereKey($eligibility->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($lockedEligibility->status !== 'pending') {
                throw ValidationException::withMessages([
                    'makeup_eligibility_id' => 'Hanya makeup eligibility PENDING yang dapat dijadwalkan.',
                ]);
            }

            $origin = ChildSessionBooking::query()->findOrFail($lockedEligibility->source_booking_id);
            $currentSnapshot = $this->lineage->currentBooking($origin);
            $source = ChildSessionBooking::query()
                ->whereKey($currentSnapshot->id)
                ->lockForUpdate()
                ->firstOrFail();
            $destination = SessionOccurrence::query()
                ->whereKey($destinationOccurrence->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ((int) $source->session_credit_id !== (int) $lockedEligibility->session_credit_id) {
                throw ValidationException::withMessages([
                    'session_credit_id' => 'Current booking makeup tidak lagi memakai session credit eligibility.',
                ]);
            }

            $credit = $source->sessionCredit()->lockForUpdate()->firstOrFail();
            if ($credit->voided_at !== null || $credit->status !== 'booked') {
                throw ValidationException::withMessages([
                    'session_credit_id' => 'Makeup hanya dapat dijadwalkan dengan session credit BOOKED yang aktif.',
                ]);
            }

            if ($source->outgoingMovement()->exists()) {
                throw ValidationException::withMessages([
                    'source_booking_id' => 'Current booking sudah memiliki downstream movement.',
                ]);
            }

            $schoolCancellationSource = $source->status === 'session_cancelled';

            if (! $schoolCancellationSource) {
                if ($source->status !== 'scheduled') {
                    throw ValidationException::withMessages([
                        'source_booking_id' => 'Makeup hanya dapat berangkat dari booking scheduled dengan missed outcome atau source school cancellation.',
                    ]);
                }

                $attendance = $this->evidence->attendanceFor($source);
                if (! $attendance
                    || $attendance->marked_at === null
                    || ! in_array($attendance->status, ['sick', 'excused', 'absent'], true)) {
                    throw ValidationException::withMessages([
                        'source_booking_id' => 'Booking sumber makeup harus memiliki missed attendance outcome yang sudah ditandai.',
                    ]);
                }
            }

            if ((int) $source->session_occurrence_id === (int) $destination->id) {
                throw ValidationException::withMessages([
                    'destination_occurrence_id' => 'Occurrence tujuan makeup harus berbeda dari source occurrence.',
                ]);
            }

            if (in_array($destination->status, ['cancelled', 'completed'], true)) {
                throw ValidationException::withMessages([
                    'destination_occurrence_id' => 'Occurrence tujuan makeup sudah tidak menerima booking baru.',
                ]);
            }

            $duplicateDestinationDate = ChildSessionBooking::query()
                ->active()
                ->where('student_id', $source->student_id)
                ->whereDate('active_on', $destination->occurs_on->toDateString())
                ->whereKeyNot($source->id)
                ->exists();

            if ($duplicateDestinationDate) {
                throw ValidationException::withMessages([
                    'destination_occurrence_id' => 'Anak sudah memiliki booking aktif lain pada tanggal tujuan makeup.',
                ]);
            }

            if (! $capacityOverride && $destination->capacity !== null) {
                $activeCount = ChildSessionBooking::query()
                    ->active()
                    ->where('session_occurrence_id', $destination->id)
                    ->count();

                if ($activeCount >= $destination->capacity) {
                    throw ValidationException::withMessages([
                        'destination_occurrence_id' => "Kapasitas occurrence tujuan sudah penuh ({$destination->capacity} anak).",
                    ]);
                }
            }

            if ($source->status === 'scheduled') {
                $source->forceFill([
                    'status' => 'rescheduled_out',
                    'active_on' => null,
                ])->save();
                $this->compatibility->syncBooking($source);
            }

            $destinationBooking = ChildSessionBooking::query()->create([
                'student_id' => $source->student_id,
                'child_enrollment_id' => $source->child_enrollment_id,
                'session_credit_id' => $source->session_credit_id,
                'credit_allocated_by' => $source->credit_allocated_by,
                'credit_allocated_at' => $source->credit_allocated_at,
                'session_occurrence_id' => $destination->id,
                'booking_type' => 'makeup',
                'status' => 'scheduled',
                'source_type' => 'movement',
                'created_by' => $actor->id,
                'capacity_override' => $capacityOverride,
                'capacity_override_by' => $capacityOverride ? $actor->id : null,
                'capacity_override_reason' => $capacityOverride ? $capacityOverrideReason : null,
            ]);

            $this->compatibility->syncBooking($destinationBooking);
            $this->compatibility->ensureUnmarkedAttendance($destinationBooking);

            return BookingMovement::query()->create([
                'source_booking_id' => $source->id,
                'destination_booking_id' => $destinationBooking->id,
                'movement_type' => 'makeup',
                'reason' => $reason,
                'moved_by' => $actor->id,
                'capacity_override' => $capacityOverride,
                'capacity_override_reason' => $capacityOverride ? $capacityOverrideReason : null,
            ])->load(['sourceBooking', 'destinationBooking', 'movedBy']);
        });
    }

    private function authorize(User $actor): void
    {
        if (! in_array($actor->role, [Role::SUPER_ADMIN, Role::ADMIN, Role::TEACHER], true)) {
            throw ValidationException::withMessages([
                'actor' => 'Penjadwalan makeup hanya boleh dilakukan oleh super admin, admin, atau teacher.',
            ]);
        }
    }
}
