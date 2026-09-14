<?php

namespace App\Services\Scheduling;

use App\Models\Attendance;
use App\Models\ChildSessionBooking;
use App\Models\SessionOccurrence;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class LegacySessionCompatibilityWriter
{
    private const MIRRORED_BOOKING_STATUSES = [
        'scheduled',
        'session_cancelled',
    ];

    public function syncOccurrence(SessionOccurrence $occurrence): ?int
    {
        $legacyId = $occurrence->legacy_class_session_id;
        $now = now();
        $payload = [
            'weekly_schedule_id' => $occurrence->legacy_weekly_schedule_id,
            'school_class_id' => $occurrence->legacy_school_class_id,
            'teacher_id' => $occurrence->legacy_teacher_id,
            'room' => $this->normalizeRoom($occurrence->room),
            'capacity' => $occurrence->capacity,
            'session_date' => $occurrence->occurs_on?->toDateString(),
            'starts_at' => $occurrence->starts_at,
            'ends_at' => $occurrence->ends_at,
            'topic' => $occurrence->legacy_topic,
            'status' => $occurrence->status,
            'closed_by' => $occurrence->status === 'completed' ? $occurrence->completed_by : null,
            'closed_at' => $occurrence->status === 'completed' ? $occurrence->completed_at : null,
            'updated_at' => $now,
        ];

        if ($legacyId !== null && DB::table('class_sessions')->where('id', $legacyId)->exists()) {
            DB::table('class_sessions')->where('id', $legacyId)->update($payload);
            $this->repairOccurrenceBookingMirrors($occurrence);

            return (int) $legacyId;
        }

        if ($occurrence->legacy_school_class_id === null || $occurrence->legacy_teacher_id === null) {
            return null;
        }

        $legacyId = DB::table('class_sessions')->insertGetId([
            ...$payload,
            'created_at' => $now,
        ]);

        $occurrence->forceFill([
            'legacy_class_session_id' => $legacyId,
            'legacy_deleted_at' => null,
        ])->save();
        $this->repairOccurrenceBookingMirrors($occurrence);

        return (int) $legacyId;
    }

    public function syncBooking(ChildSessionBooking $booking): ?int
    {
        $booking->loadMissing('sessionOccurrence');
        $legacySessionId = $this->resolveCompatibilitySessionId($booking);

        if ($legacySessionId === null) {
            return null;
        }

        if (! in_array($booking->status, self::MIRRORED_BOOKING_STATUSES, true)) {
            $this->removeBookingMirror($booking);
            $this->pruneOrphanPivots($legacySessionId);

            return null;
        }

        $pivotId = $booking->legacy_class_session_student_id;
        $now = now();

        if ($pivotId !== null && DB::table('class_session_student')->where('id', $pivotId)->exists()) {
            DB::table('class_session_student')->where('id', $pivotId)->update([
                'class_session_id' => $legacySessionId,
                'student_id' => $booking->student_id,
                'updated_at' => $now,
            ]);
        } else {
            $existingPivot = DB::table('class_session_student')
                ->where('class_session_id', $legacySessionId)
                ->where('student_id', $booking->student_id)
                ->first();

            $pivotId = $existingPivot?->id ?? DB::table('class_session_student')->insertGetId([
                'class_session_id' => $legacySessionId,
                'student_id' => $booking->student_id,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        $booking->forceFill([
            'legacy_class_session_student_id' => $pivotId,
            'legacy_class_session_id' => $legacySessionId,
            'legacy_deleted_at' => null,
        ])->save();

        $this->pruneOrphanPivots($legacySessionId);

        return (int) $pivotId;
    }

    public function removeBookingMirror(ChildSessionBooking $booking): void
    {
        $booking->loadMissing('sessionOccurrence');
        $legacySessionIds = collect([
            $booking->legacy_class_session_id,
            $booking->sessionOccurrence?->legacy_class_session_id,
        ])
            ->filter(fn ($id): bool => $id !== null)
            ->map(fn ($id): int => (int) $id)
            ->unique()
            ->values();

        $attendance = $this->attendanceFor($booking);
        if ($attendance && $attendance->marked_at === null) {
            $attendance->delete();
        }

        if ($booking->legacy_class_session_student_id !== null) {
            DB::table('class_session_student')
                ->where('id', $booking->legacy_class_session_student_id)
                ->delete();
        }

        foreach ($legacySessionIds as $legacySessionId) {
            DB::table('class_session_student')
                ->where('class_session_id', $legacySessionId)
                ->where('student_id', $booking->student_id)
                ->delete();

            Attendance::query()
                ->where('class_session_id', $legacySessionId)
                ->where('student_id', $booking->student_id)
                ->whereNull('marked_at')
                ->delete();
        }

        $booking->forceFill([
            'legacy_deleted_at' => now(),
        ])->save();
    }

    public function ensureUnmarkedAttendance(ChildSessionBooking $booking): Attendance
    {
        if ($booking->status !== 'scheduled') {
            throw ValidationException::withMessages([
                'booking_id' => 'Attendance placeholder hanya boleh dibuat untuk booking aktif.',
            ]);
        }

        $legacySessionId = $this->resolveCompatibilitySessionId($booking);
        if ($legacySessionId === null) {
            throw ValidationException::withMessages([
                'booking_id' => 'Compatibility session untuk booking tidak ditemukan.',
            ]);
        }

        $attendance = Attendance::query()
            ->where('child_session_booking_id', $booking->id)
            ->first();

        if (! $attendance) {
            $attendance = Attendance::query()
                ->where('class_session_id', $legacySessionId)
                ->where('student_id', $booking->student_id)
                ->first();
        }

        if (! $attendance) {
            $attendance = new Attendance();
        }

        if ($attendance->exists && $attendance->child_session_booking_id !== null
            && (int) $attendance->child_session_booking_id !== (int) $booking->id) {
            throw ValidationException::withMessages([
                'attendance' => 'Attendance compatibility row sudah terhubung ke booking lain.',
            ]);
        }

        if (! $attendance->exists || $attendance->marked_at === null) {
            $attendance->forceFill([
                'class_session_id' => $legacySessionId,
                'student_id' => $booking->student_id,
                'child_session_booking_id' => $booking->id,
                'status' => 'unmarked',
                'note' => $attendance->note,
                'marked_by' => null,
                'marked_at' => null,
            ])->save();
        }

        return $attendance;
    }

    public function deleteOccurrenceMirror(SessionOccurrence $occurrence): void
    {
        if ($occurrence->legacy_class_session_id === null) {
            return;
        }

        DB::table('class_session_student')
            ->where('class_session_id', $occurrence->legacy_class_session_id)
            ->delete();
        DB::table('class_sessions')
            ->where('id', $occurrence->legacy_class_session_id)
            ->delete();
    }

    /**
     * These notes remain explicit compatibility-only fields until a canonical target
     * home is approved. Their existence blocks dropping class_sessions in M17.4.
     *
     * @param  array{class_note?: ?string, follow_up_recommendation?: ?string}  $notes
     */
    public function updateCompatibilityNotes(int $legacySessionId, array $notes): void
    {
        DB::table('class_sessions')->where('id', $legacySessionId)->update([
            ...$notes,
            'updated_at' => now(),
        ]);
    }

    private function repairOccurrenceBookingMirrors(SessionOccurrence $occurrence): void
    {
        $bookings = ChildSessionBooking::query()
            ->where('session_occurrence_id', $occurrence->id)
            ->get();

        foreach ($bookings as $booking) {
            $this->syncBooking($booking);

            if ($booking->status === 'scheduled') {
                $this->ensureUnmarkedAttendance($booking);
            }
        }
    }

    private function resolveCompatibilitySessionId(ChildSessionBooking $booking): ?int
    {
        $booking->loadMissing('sessionOccurrence');
        $occurrenceLegacyId = $booking->sessionOccurrence?->legacy_class_session_id;

        if ($occurrenceLegacyId !== null
            && DB::table('class_sessions')->where('id', $occurrenceLegacyId)->exists()) {
            return (int) $occurrenceLegacyId;
        }

        if ($booking->legacy_class_session_id !== null
            && DB::table('class_sessions')->where('id', $booking->legacy_class_session_id)->exists()) {
            return (int) $booking->legacy_class_session_id;
        }

        return null;
    }

    private function pruneOrphanPivots(int $legacySessionId): void
    {
        $desiredStudentIds = ChildSessionBooking::query()
            ->where('legacy_class_session_id', $legacySessionId)
            ->whereIn('status', self::MIRRORED_BOOKING_STATUSES)
            ->pluck('student_id')
            ->map(fn ($id): int => (int) $id)
            ->unique()
            ->values()
            ->all();

        $stale = DB::table('class_session_student')
            ->where('class_session_id', $legacySessionId)
            ->when($desiredStudentIds !== [], fn ($query) => $query->whereNotIn('student_id', $desiredStudentIds))
            ->get(['id', 'student_id']);

        if ($desiredStudentIds === []) {
            $stale = DB::table('class_session_student')
                ->where('class_session_id', $legacySessionId)
                ->get(['id', 'student_id']);
        }

        foreach ($stale as $pivot) {
            $booking = ChildSessionBooking::query()
                ->where('legacy_class_session_student_id', $pivot->id)
                ->first();

            if ($booking) {
                $this->removeBookingMirror($booking);

                continue;
            }

            DB::table('class_session_student')->where('id', $pivot->id)->delete();
            Attendance::query()
                ->where('class_session_id', $legacySessionId)
                ->where('student_id', $pivot->student_id)
                ->whereNull('marked_at')
                ->delete();
        }
    }

    private function attendanceFor(ChildSessionBooking $booking): ?Attendance
    {
        $attendance = Attendance::query()
            ->where('child_session_booking_id', $booking->id)
            ->first();

        if ($attendance) {
            return $attendance;
        }

        $legacySessionIds = collect([
            $booking->sessionOccurrence?->legacy_class_session_id,
            $booking->legacy_class_session_id,
        ])
            ->filter(fn ($id): bool => $id !== null)
            ->map(fn ($id): int => (int) $id)
            ->unique()
            ->values();

        foreach ($legacySessionIds as $legacySessionId) {
            $attendance = Attendance::query()
                ->where('class_session_id', $legacySessionId)
                ->where('student_id', $booking->student_id)
                ->first();

            if ($attendance) {
                return $attendance;
            }
        }

        return null;
    }

    private function normalizeRoom(?string $room): ?string
    {
        $room = trim((string) $room);

        return $room === '' ? null : $room;
    }
}
