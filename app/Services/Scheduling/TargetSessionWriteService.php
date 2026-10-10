<?php

namespace App\Services\Scheduling;

use App\Models\BookingMovement;
use App\Models\ChildSessionBooking;
use App\Models\ClassSession;
use App\Models\Observation;
use App\Models\RecurringSchedule;
use App\Models\SessionOccurrence;
use App\Models\SessionTemplate;
use App\Models\User;
use App\Models\WeeklySchedule;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class TargetSessionWriteService
{
    public function __construct(
        private readonly LegacySessionCompatibilityWriter $compatibility,
        private readonly BookingEvidenceService $evidence,
        private readonly TargetSessionValidator $validator,
    ) {}

    /** @return array<int, int> */
    public function participantIdsForOccurrence(SessionOccurrence $occurrence): array
    {
        return ChildSessionBooking::query()
            ->where('session_occurrence_id', $occurrence->id)
            ->whereIn('status', ChildSessionBooking::ACTIVE_STATUSES)
            ->pluck('student_id')
            ->map(fn ($id): int => (int) $id)
            ->all();
    }

    /** @return array{teacher_id: ?int, school_class_id: ?int} */
    public function ownershipForOccurrence(SessionOccurrence $occurrence): array
    {
        return [
            'teacher_id' => $occurrence->legacy_teacher_id === null ? null : (int) $occurrence->legacy_teacher_id,
            'school_class_id' => $occurrence->legacy_school_class_id === null ? null : (int) $occurrence->legacy_school_class_id,
        ];
    }

    public function createFromTemplate(SessionTemplate $template, string $date, ?User $actor): SessionOccurrence
    {
        return DB::transaction(function () use ($template, $date, $actor): SessionOccurrence {
            $template = SessionTemplate::query()
                ->whereNull('legacy_deleted_at')
                ->lockForUpdate()
                ->findOrFail($template->id);
            $sessionDate = Carbon::parse($date);

            if (! $template->is_active || (int) $sessionDate->dayOfWeekIso !== (int) $template->day_of_week) {
                throw ValidationException::withMessages([
                    'session_date' => 'Tanggal sesi harus menggunakan target template aktif pada hari yang sesuai.',
                ]);
            }

            if (($template->valid_from && $sessionDate->lt($template->valid_from->startOfDay()))
                || ($template->valid_until && $sessionDate->gt($template->valid_until->endOfDay()))) {
                throw ValidationException::withMessages([
                    'session_date' => 'Tanggal sesi berada di luar masa berlaku target template.',
                ]);
            }

            $existing = SessionOccurrence::query()
                ->where('session_template_id', $template->id)
                ->whereDate('occurs_on', $sessionDate->toDateString())
                ->whereNull('legacy_deleted_at')
                ->first();

            if ($existing) {
                $this->compatibility->syncOccurrence($existing);

                return $existing;
            }

            $payload = $this->templateOccurrencePayload($template, $sessionDate->toDateString());
            $this->validator->validateTime($payload);
            $occurrence = SessionOccurrence::query()->create($payload);

            if ($this->compatibility->syncOccurrence($occurrence) === null) {
                throw ValidationException::withMessages([
                    'session' => 'Compatibility session tidak dapat dibentuk dari occurrence target.',
                ]);
            }

            $recurring = RecurringSchedule::query()
                ->where('session_template_id', $template->id)
                ->where('is_active', true)
                ->whereNull('legacy_deleted_at')
                ->where(function ($query) use ($sessionDate): void {
                    $query->whereNull('valid_from')->orWhereDate('valid_from', '<=', $sessionDate->toDateString());
                })
                ->where(function ($query) use ($sessionDate): void {
                    $query->whereNull('valid_until')->orWhereDate('valid_until', '>=', $sessionDate->toDateString());
                })
                ->orderBy('id')
                ->get();

            foreach ($recurring as $row) {
                $booking = ChildSessionBooking::query()->create([
                    'student_id' => $row->student_id,
                    'child_enrollment_id' => $row->child_enrollment_id,
                    'session_occurrence_id' => $occurrence->id,
                    'booking_type' => 'regular',
                    'status' => 'scheduled',
                    'source_type' => 'recurring_schedule',
                    'created_by' => $actor?->id,
                ]);
                $this->compatibility->syncBooking($booking);
                $this->compatibility->ensureUnmarkedAttendance($booking);
            }

            return $occurrence->fresh();
        });
    }

    /** @param array<string, mixed> $attributes @param array<int, int> $studentIds */
    public function updateOccurrence(
        SessionOccurrence $occurrence,
        array $attributes,
        array $studentIds,
        ?User $actor,
    ): SessionOccurrence {
        return DB::transaction(function () use ($occurrence, $attributes, $studentIds, $actor): SessionOccurrence {
            $occurrence = SessionOccurrence::query()
                ->whereNull('legacy_deleted_at')
                ->lockForUpdate()
                ->findOrFail($occurrence->id);

            if ($occurrence->status !== 'planned') {
                throw ValidationException::withMessages([
                    'status' => 'Structural session update hanya boleh dilakukan saat occurrence masih planned.',
                ]);
            }
            if (($attributes['status'] ?? 'planned') === 'cancelled') {
                throw ValidationException::withMessages([
                    'status' => 'Gunakan workflow session cancellation agar credit, makeup, dan audit tetap konsisten.',
                ]);
            }

            $studentIds = collect($studentIds)->map(fn ($id): int => (int) $id)->unique()->values()->all();
            $payload = [
                'occurs_on' => Carbon::parse($attributes['session_date'])->toDateString(),
                'starts_at' => $attributes['starts_at'],
                'ends_at' => $attributes['ends_at'],
                'capacity' => (int) $attributes['capacity'],
                'room' => $this->validator->normalizeRoom($attributes['room'] ?? null),
                'legacy_school_class_id' => (int) $attributes['school_class_id'],
                'legacy_teacher_id' => (int) $attributes['teacher_id'],
                'legacy_topic' => $attributes['topic'] ?? null,
                'status' => 'planned',
            ];

            $this->validator->validateTime($payload, $occurrence);
            $this->validator->validateStudents($payload, $studentIds, $occurrence);
            $occurrence->forceFill($payload)->save();
            $this->compatibility->syncOccurrence($occurrence);
            $this->syncParticipants($occurrence, $studentIds, $actor);

            return $occurrence->fresh();
        });
    }

    /** @param array{class_note?: ?string, follow_up_recommendation?: ?string} $notes */
    public function updateOccurrenceNotes(SessionOccurrence $occurrence, array $notes): void
    {
        $occurrence = SessionOccurrence::query()
            ->whereNull('legacy_deleted_at')
            ->findOrFail($occurrence->id);
        $legacyId = $this->compatibility->syncOccurrence($occurrence);

        if ($legacyId === null) {
            throw ValidationException::withMessages([
                'session' => 'Compatibility session untuk catatan sesi tidak dapat dibentuk.',
            ]);
        }

        $this->compatibility->updateCompatibilityNotes($legacyId, $notes);
    }

    /** @param array{class_note?: ?string, follow_up_recommendation?: ?string} $notes */
    public function closeOccurrence(SessionOccurrence $occurrence, array $notes, ?User $actor): int
    {
        return DB::transaction(function () use ($occurrence, $notes, $actor): int {
            $occurrence = SessionOccurrence::query()
                ->whereNull('legacy_deleted_at')
                ->lockForUpdate()
                ->findOrFail($occurrence->id);

            if ($occurrence->status === 'cancelled') {
                throw ValidationException::withMessages([
                    'session' => 'Session occurrence yang cancelled tidak dapat ditutup sebagai completed.',
                ]);
            }

            $bookings = ChildSessionBooking::query()
                ->where('session_occurrence_id', $occurrence->id)
                ->whereIn('status', ChildSessionBooking::ACTIVE_STATUSES)
                ->get();
            $unmarked = $bookings->filter(
                fn (ChildSessionBooking $booking): bool => $this->evidence->attendanceFor($booking)?->marked_at === null,
            )->count();

            if ($occurrence->status !== 'completed') {
                $occurrence->forceFill([
                    'status' => 'completed',
                    'completed_at' => now(),
                    'completed_by' => $actor?->id,
                ])->save();
            }

            $this->compatibility->syncOccurrence($occurrence);
            $this->updateOccurrenceNotes($occurrence, $notes);

            return $unmarked;
        });
    }

    public function destroyOccurrence(SessionOccurrence $occurrence, ?User $actor): void
    {
        DB::transaction(function () use ($occurrence, $actor): void {
            $occurrence = SessionOccurrence::query()
                ->whereNull('legacy_deleted_at')
                ->lockForUpdate()
                ->findOrFail($occurrence->id);

            if ($occurrence->status !== 'planned') {
                throw ValidationException::withMessages([
                    'session' => 'Hanya planned session tanpa evidence yang boleh dihapus.',
                ]);
            }

            $legacyId = $occurrence->legacy_class_session_id;
            $hasObservation = Observation::query()
                ->where('session_occurrence_id', $occurrence->id)
                ->when(
                    $legacyId !== null,
                    fn ($query) => $query->orWhere('class_session_id', $legacyId),
                )
                ->exists();

            if ($hasObservation || $occurrence->presentations()->exists()) {
                throw ValidationException::withMessages([
                    'session' => 'Session yang sudah memiliki evidence pedagogis tidak boleh dihapus.',
                ]);
            }

            $bookings = ChildSessionBooking::query()
                ->where('session_occurrence_id', $occurrence->id)
                ->lockForUpdate()
                ->get();

            foreach ($bookings as $booking) {
                $hasMovement = BookingMovement::query()
                    ->where('source_booking_id', $booking->id)
                    ->orWhere('destination_booking_id', $booking->id)
                    ->exists();

                if ($booking->status !== 'scheduled'
                    || $booking->session_credit_id !== null
                    || $this->evidence->hasAnyMarkedAttendance($booking)
                    || $hasMovement) {
                    throw ValidationException::withMessages([
                        'session' => 'Session memiliki booking history, credit, movement, atau attendance evidence. Gunakan workflow cancellation, bukan delete.',
                    ]);
                }
            }

            foreach ($bookings as $booking) {
                $booking->forceFill([
                    'status' => 'cancelled',
                    'active_on' => null,
                    'cancellation_reason' => 'Administrative removal of pristine planned session.',
                    'cancelled_by' => $actor?->id,
                    'cancelled_at' => now(),
                    'legacy_deleted_at' => now(),
                ])->save();
                $this->compatibility->removeBookingMirror($booking);
            }

            $occurrence->forceFill([
                'status' => 'cancelled',
                'cancellation_reason' => 'Administrative removal of pristine planned session.',
                'cancelled_by' => $actor?->id,
                'cancelled_at' => now(),
                'legacy_deleted_at' => now(),
            ])->save();
            $this->compatibility->deleteOccurrenceMirror($occurrence);
        });
    }

    /** @return array<int, int> */
    public function participantIds(ClassSession $session): array
    {
        $occurrence = $this->resolveOccurrence($session);

        return ChildSessionBooking::query()
            ->where('session_occurrence_id', $occurrence->id)
            ->whereIn('status', ChildSessionBooking::ACTIVE_STATUSES)
            ->pluck('student_id')
            ->map(fn ($id): int => (int) $id)
            ->all();
    }

    /** @return array{teacher_id: ?int, school_class_id: ?int} */
    public function ownership(ClassSession $session): array
    {
        $occurrence = $this->resolveOccurrence($session);

        return [
            'teacher_id' => $occurrence->legacy_teacher_id === null ? null : (int) $occurrence->legacy_teacher_id,
            'school_class_id' => $occurrence->legacy_school_class_id === null ? null : (int) $occurrence->legacy_school_class_id,
        ];
    }

    public function createFromSchedule(WeeklySchedule $schedule, string $date, ?User $actor): ClassSession
    {
        $template = SessionTemplate::query()
            ->where('legacy_weekly_schedule_id', $schedule->id)
            ->whereNull('legacy_deleted_at')
            ->firstOrFail();
        $occurrence = $this->createFromTemplate($template, $date, $actor);

        return $this->compatibilityHandle($occurrence);
    }

    /** @param array<string, mixed> $attributes @param array<int, int> $studentIds */
    public function update(ClassSession $session, array $attributes, array $studentIds, ?User $actor): ClassSession
    {
        return DB::transaction(function () use ($session, $attributes, $studentIds, $actor): ClassSession {
            $occurrence = $this->resolveOccurrence($session, true);
            if ($occurrence->status !== 'planned') {
                throw ValidationException::withMessages([
                    'status' => 'Structural session update hanya boleh dilakukan saat occurrence masih planned.',
                ]);
            }
            if (($attributes['status'] ?? 'planned') === 'cancelled') {
                throw ValidationException::withMessages([
                    'status' => 'Gunakan workflow session cancellation agar credit, makeup, dan audit tetap konsisten.',
                ]);
            }

            $studentIds = collect($studentIds)->map(fn ($id): int => (int) $id)->unique()->values()->all();
            $payload = [
                'occurs_on' => Carbon::parse($attributes['session_date'])->toDateString(),
                'starts_at' => $attributes['starts_at'],
                'ends_at' => $attributes['ends_at'],
                'capacity' => (int) $attributes['capacity'],
                'room' => $this->validator->normalizeRoom($attributes['room'] ?? null),
                'legacy_school_class_id' => (int) $attributes['school_class_id'],
                'legacy_teacher_id' => (int) $attributes['teacher_id'],
                'legacy_topic' => $attributes['topic'] ?? null,
                'status' => 'planned',
            ];

            $this->validator->validateTime($payload, $occurrence);
            $this->validator->validateStudents($payload, $studentIds, $occurrence);
            $occurrence->forceFill($payload)->save();
            $this->compatibility->syncOccurrence($occurrence);
            $this->syncParticipants($occurrence, $studentIds, $actor);

            return ClassSession::query()->findOrFail($session->id);
        });
    }

    /** @param array{class_note?: ?string, follow_up_recommendation?: ?string} $notes */
    public function updateNotes(ClassSession $session, array $notes): void
    {
        $this->resolveOccurrence($session);
        $this->compatibility->updateCompatibilityNotes((int) $session->id, $notes);
    }

    /** @param array{class_note?: ?string, follow_up_recommendation?: ?string} $notes */
    public function close(ClassSession $session, array $notes, ?User $actor): int
    {
        return DB::transaction(function () use ($session, $notes, $actor): int {
            $occurrence = $this->resolveOccurrence($session, true);
            if ($occurrence->status === 'cancelled') {
                throw ValidationException::withMessages([
                    'session' => 'Session occurrence yang cancelled tidak dapat ditutup sebagai completed.',
                ]);
            }

            $bookings = ChildSessionBooking::query()
                ->where('session_occurrence_id', $occurrence->id)
                ->whereIn('status', ChildSessionBooking::ACTIVE_STATUSES)
                ->get();
            $unmarked = $bookings->filter(
                fn (ChildSessionBooking $booking): bool => $this->evidence->attendanceFor($booking)?->marked_at === null,
            )->count();

            if ($occurrence->status !== 'completed') {
                $occurrence->forceFill([
                    'status' => 'completed',
                    'completed_at' => now(),
                    'completed_by' => $actor?->id,
                ])->save();
            }

            $this->compatibility->syncOccurrence($occurrence);
            $this->compatibility->updateCompatibilityNotes((int) $session->id, $notes);

            return $unmarked;
        });
    }

    public function destroy(ClassSession $session, ?User $actor): void
    {
        DB::transaction(function () use ($session, $actor): void {
            $occurrence = $this->resolveOccurrence($session, true);
            if ($occurrence->status !== 'planned') {
                throw ValidationException::withMessages([
                    'session' => 'Hanya planned session tanpa evidence yang boleh dihapus dari compatibility UI.',
                ]);
            }

            if (Observation::query()
                ->where(function ($query) use ($occurrence, $session): void {
                    $query->where('session_occurrence_id', $occurrence->id)
                        ->orWhere('class_session_id', $session->id);
                })
                ->exists()
                || $occurrence->presentations()->exists()) {
                throw ValidationException::withMessages([
                    'session' => 'Session yang sudah memiliki evidence pedagogis tidak boleh dihapus.',
                ]);
            }

            $bookings = ChildSessionBooking::query()
                ->where('session_occurrence_id', $occurrence->id)
                ->lockForUpdate()
                ->get();

            foreach ($bookings as $booking) {
                $hasMovement = BookingMovement::query()
                    ->where('source_booking_id', $booking->id)
                    ->orWhere('destination_booking_id', $booking->id)
                    ->exists();
                if ($booking->status !== 'scheduled'
                    || $booking->session_credit_id !== null
                    || $this->evidence->hasAnyMarkedAttendance($booking)
                    || $hasMovement) {
                    throw ValidationException::withMessages([
                        'session' => 'Session memiliki booking history, credit, movement, atau attendance evidence. Gunakan workflow cancellation, bukan delete.',
                    ]);
                }
            }

            foreach ($bookings as $booking) {
                $booking->forceFill([
                    'status' => 'cancelled',
                    'active_on' => null,
                    'cancellation_reason' => 'Administrative removal of pristine planned session.',
                    'cancelled_by' => $actor?->id,
                    'cancelled_at' => now(),
                    'legacy_deleted_at' => now(),
                ])->save();
                $this->compatibility->removeBookingMirror($booking);
            }

            $occurrence->forceFill([
                'status' => 'cancelled',
                'cancellation_reason' => 'Administrative removal of pristine planned session.',
                'cancelled_by' => $actor?->id,
                'cancelled_at' => now(),
                'legacy_deleted_at' => now(),
            ])->save();
            $this->compatibility->deleteOccurrenceMirror($occurrence);
        });
    }

    /** @param array<int, int> $studentIds */
    private function syncParticipants(SessionOccurrence $occurrence, array $studentIds, ?User $actor): void
    {
        $active = ChildSessionBooking::query()
            ->where('session_occurrence_id', $occurrence->id)
            ->whereIn('status', ChildSessionBooking::ACTIVE_STATUSES)
            ->lockForUpdate()
            ->get()
            ->keyBy(fn (ChildSessionBooking $booking): int => (int) $booking->student_id);

        foreach ($active as $studentId => $booking) {
            if (! in_array((int) $studentId, $studentIds, true)) {
                $this->validator->assertBookingCanBeRemoved($booking);
                $booking->forceFill([
                    'status' => 'cancelled',
                    'active_on' => null,
                    'cancellation_reason' => 'Removed from session roster.',
                    'cancelled_by' => $actor?->id,
                    'cancelled_at' => now(),
                ])->save();
                $this->compatibility->syncBooking($booking);

                continue;
            }

            // Re-save after occurrence date mutation so active_on is re-derived and
            // the booking model re-checks same-day/capacity invariants.
            $booking->save();
            $this->compatibility->syncBooking($booking);
            $this->compatibility->ensureUnmarkedAttendance($booking);
        }

        $existingIds = $active->keys()->map(fn ($id): int => (int) $id)->all();
        foreach (array_diff($studentIds, $existingIds) as $studentId) {
            $terminalBooking = ChildSessionBooking::query()
                ->where('session_occurrence_id', $occurrence->id)
                ->where('student_id', (int) $studentId)
                ->whereNotIn('status', ChildSessionBooking::ACTIVE_STATUSES)
                ->lockForUpdate()
                ->first();

            if ($terminalBooking) {
                throw ValidationException::withMessages([
                    'student_ids' => 'Booking historis terminal tidak boleh diaktifkan kembali melalui generic roster update. Gunakan workflow reschedule atau makeup yang eksplisit.',
                ]);
            }

            $booking = ChildSessionBooking::query()->create([
                'student_id' => (int) $studentId,
                'session_occurrence_id' => $occurrence->id,
                'booking_type' => 'regular',
                'status' => 'scheduled',
                'source_type' => 'session_update',
                'created_by' => $actor?->id,
            ]);
            $this->compatibility->syncBooking($booking);
            $this->compatibility->ensureUnmarkedAttendance($booking);
        }
    }

    private function compatibilityHandle(SessionOccurrence $occurrence): ClassSession
    {
        $legacyId = $this->compatibility->syncOccurrence($occurrence);
        if ($legacyId === null) {
            throw ValidationException::withMessages(['session' => 'Compatibility session tidak dapat dibentuk dari occurrence target.']);
        }

        return ClassSession::query()->findOrFail($legacyId);
    }

    private function resolveOccurrence(ClassSession $session, bool $lock = false): SessionOccurrence
    {
        $query = SessionOccurrence::query()
            ->where('legacy_class_session_id', $session->id)
            ->whereNull('legacy_deleted_at');
        if ($lock) {
            $query->lockForUpdate();
        }

        $occurrence = $query->first();
        if (! $occurrence) {
            throw ValidationException::withMessages([
                'session' => 'Canonical SessionOccurrence untuk compatibility session tidak ditemukan.',
            ]);
        }

        return $occurrence;
    }

    /** @return array<string, mixed> */
    private function templateOccurrencePayload(SessionTemplate $template, string $date): array
    {
        return [
            'session_template_id' => $template->id,
            'environment_id' => $template->environment_id,
            'occurs_on' => $date,
            'starts_at' => Carbon::parse($template->starts_at)->format('H:i'),
            'ends_at' => Carbon::parse($template->ends_at)->format('H:i'),
            'capacity' => $template->capacity,
            'room' => $this->validator->normalizeRoom($template->room),
            'status' => 'planned',
            'legacy_weekly_schedule_id' => $template->legacy_weekly_schedule_id,
            'legacy_school_class_id' => $template->legacy_school_class_id,
            'legacy_teacher_id' => $template->legacy_teacher_id,
            'legacy_topic' => $template->legacy_topic,
            'legacy_deleted_at' => null,
        ];
    }
}
