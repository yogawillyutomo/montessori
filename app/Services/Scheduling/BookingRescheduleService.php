<?php

namespace App\Services\Scheduling;

use App\Models\Attendance;
use App\Models\BookingMovement;
use App\Models\ChildSessionBooking;
use App\Models\ClassSession;
use App\Models\SessionOccurrence;
use App\Models\User;
use App\Support\Alpha\Role;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class BookingRescheduleService
{
    public function __construct(
        private readonly BookingEvidenceService $evidence,
    ) {}

    public function reschedule(
        ChildSessionBooking $sourceBooking,
        SessionOccurrence $destinationOccurrence,
        User $actor,
        string $reason,
        bool $capacityOverride = false,
        ?string $capacityOverrideReason = null,
    ): BookingMovement {
        $reason = trim($reason);
        $capacityOverrideReason = trim((string) $capacityOverrideReason);

        if ($reason === '') {
            throw ValidationException::withMessages([
                'reason' => 'Alasan reschedule wajib dicatat.',
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
            $sourceBooking,
            $destinationOccurrence,
            $actor,
            $reason,
            $capacityOverride,
            $capacityOverrideReason,
        ): BookingMovement {
            $source = ChildSessionBooking::query()
                ->whereKey($sourceBooking->id)
                ->lockForUpdate()
                ->firstOrFail();
            $destination = SessionOccurrence::query()
                ->whereKey($destinationOccurrence->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($source->status !== 'scheduled') {
                throw ValidationException::withMessages([
                    'source_booking_id' => 'Hanya booking aktif berstatus scheduled yang dapat dipindahkan.',
                ]);
            }

            if ((int) $source->session_occurrence_id === (int) $destination->id) {
                throw ValidationException::withMessages([
                    'destination_occurrence_id' => 'Occurrence tujuan harus berbeda dari occurrence sumber.',
                ]);
            }

            if (in_array($destination->status, ['cancelled', 'completed'], true)) {
                throw ValidationException::withMessages([
                    'destination_occurrence_id' => 'Occurrence tujuan sudah tidak menerima booking baru.',
                ]);
            }

            if ($source->outgoingMovement()->exists()) {
                throw ValidationException::withMessages([
                    'source_booking_id' => 'Booking sumber sudah pernah dipindahkan.',
                ]);
            }

            if ($this->evidence->hasAnyMarkedAttendance($source)) {
                throw ValidationException::withMessages([
                    'source_booking_id' => 'Booking yang sudah memiliki attendance outcome tidak boleh memakai reschedule biasa. Gunakan workflow makeup atau koreksi attendance.',
                ]);
            }

            if ($this->evidence->hasObservationEvidence($source)) {
                throw ValidationException::withMessages([
                    'source_booking_id' => 'Booking yang sudah memiliki observasi tidak boleh dipindahkan.',
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
                    'destination_occurrence_id' => 'Anak sudah memiliki booking aktif lain pada tanggal tujuan.',
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

            $source->forceFill([
                'status' => 'rescheduled_out',
                'active_on' => null,
            ])->save();

            $destinationBooking = ChildSessionBooking::query()->create([
                'student_id' => $source->student_id,
                'child_enrollment_id' => $source->child_enrollment_id,
                'session_credit_id' => $source->session_credit_id,
                'credit_allocated_by' => $source->credit_allocated_by,
                'credit_allocated_at' => $source->credit_allocated_at,
                'session_occurrence_id' => $destination->id,
                'booking_type' => 'rescheduled',
                'status' => 'scheduled',
                'source_type' => 'movement',
                'created_by' => $actor->id,
                'capacity_override' => $capacityOverride,
                'capacity_override_by' => $capacityOverride ? $actor->id : null,
                'capacity_override_reason' => $capacityOverride ? $capacityOverrideReason : null,
            ]);

            $this->syncLegacySourceAfterMove($source);
            $this->syncLegacyDestination($destinationBooking, $destination);

            return BookingMovement::query()->create([
                'source_booking_id' => $source->id,
                'destination_booking_id' => $destinationBooking->id,
                'movement_type' => 'reschedule',
                'reason' => $reason,
                'moved_by' => $actor->id,
                'capacity_override' => $capacityOverride,
                'capacity_override_reason' => $capacityOverride ? $capacityOverrideReason : null,
            ])->load(['sourceBooking', 'destinationBooking', 'movedBy']);
        });
    }

    private function syncLegacySourceAfterMove(ChildSessionBooking $source): void
    {
        if (! $source->legacy_class_session_id) {
            return;
        }

        $attendance = $this->evidence->attendanceFor($source);

        if ($attendance && $attendance->marked_at === null) {
            $attendance->delete();
        }

        $legacySession = ClassSession::query()->find($source->legacy_class_session_id);
        if (! $legacySession) {
            return;
        }

        if ($legacySession->students()->whereKey($source->student_id)->exists()) {
            $legacySession->students()->detach($source->student_id);
        }
    }

    private function syncLegacyDestination(
        ChildSessionBooking $destinationBooking,
        SessionOccurrence $destinationOccurrence,
    ): void {
        if (! $destinationOccurrence->legacy_class_session_id) {
            return;
        }

        $legacySession = ClassSession::query()->find($destinationOccurrence->legacy_class_session_id);
        if (! $legacySession) {
            throw ValidationException::withMessages([
                'destination_occurrence_id' => 'Legacy session untuk occurrence tujuan tidak ditemukan.',
            ]);
        }

        if (! $legacySession->students()->whereKey($destinationBooking->student_id)->exists()) {
            $legacySession->students()->attach($destinationBooking->student_id);
        }

        $destinationBooking->refresh();
        $attendance = Attendance::query()->firstOrCreate(
            [
                'class_session_id' => $legacySession->id,
                'student_id' => $destinationBooking->student_id,
            ],
            [
                'child_session_booking_id' => $destinationBooking->id,
                'status' => 'unmarked',
                'note' => null,
                'marked_by' => null,
                'marked_at' => null,
            ]
        );

        if ($attendance->child_session_booking_id === null) {
            $attendance->forceFill(['child_session_booking_id' => $destinationBooking->id])->save();
        } elseif ((int) $attendance->child_session_booking_id !== (int) $destinationBooking->id) {
            throw ValidationException::withMessages([
                'attendance' => 'Attendance destination sudah terhubung ke booking lain.',
            ]);
        }
    }
}
