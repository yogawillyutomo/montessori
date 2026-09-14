<?php

namespace App\Services\Scheduling;

use App\Models\BookingMovement;
use App\Models\ChildSessionBooking;
use App\Models\ClassSession;
use App\Models\SessionOccurrence;
use App\Models\User;
use App\Models\WeeklySchedule;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class LegacySessionWriteService
{
    /** @return array<int, int> */
    public function participantIds(ClassSession $session): array
    {
        return $session->students()->pluck('students.id')->map(fn ($id): int => (int) $id)->all();
    }

    /** @return array{teacher_id: ?int, school_class_id: ?int} */
    public function ownership(ClassSession $session): array
    {
        return [
            'teacher_id' => $session->teacher_id === null ? null : (int) $session->teacher_id,
            'school_class_id' => $session->school_class_id === null ? null : (int) $session->school_class_id,
        ];
    }

    public function createFromSchedule(WeeklySchedule $schedule, string $date): ClassSession
    {
        return DB::transaction(function () use ($schedule, $date): ClassSession {
            $sessionDate = Carbon::parse($date);
            if (! $schedule->is_active || (int) $sessionDate->dayOfWeekIso !== (int) $schedule->day_of_week) {
                throw ValidationException::withMessages([
                    'session_date' => 'Jadwal legacy nonaktif atau tanggal sesi tidak sesuai hari jadwal.',
                ]);
            }

            $payload = [
                'school_class_id' => $schedule->school_class_id,
                'teacher_id' => $schedule->teacher_id,
                'room' => $this->normalizeRoom($schedule->room),
                'capacity' => (int) ($schedule->capacity ?: $schedule->schoolClass?->capacity ?: 1),
                'session_date' => $sessionDate->toDateString(),
                'starts_at' => Carbon::parse($schedule->starts_at)->format('H:i'),
                'ends_at' => Carbon::parse($schedule->ends_at)->format('H:i'),
                'topic' => $schedule->topic,
                'status' => 'planned',
            ];

            $existing = ClassSession::query()
                ->where('weekly_schedule_id', $schedule->id)
                ->whereDate('session_date', $payload['session_date'])
                ->first();
            if ($existing) {
                return $existing;
            }

            $this->validateTime($payload);
            $studentIds = $schedule->students()->pluck('students.id')->map(fn ($id): int => (int) $id)->all();
            $this->validateStudents($payload, $studentIds);

            $session = ClassSession::query()->create([...$payload, 'weekly_schedule_id' => $schedule->id]);
            $session->students()->sync($studentIds);
            foreach ($studentIds as $studentId) {
                $session->attendances()->firstOrCreate(['student_id' => $studentId], [
                    'status' => 'unmarked',
                    'marked_by' => null,
                    'marked_at' => null,
                ]);
            }

            return $session;
        });
    }

    /** @param array<string, mixed> $attributes @param array<int, int> $studentIds */
    public function update(ClassSession $session, array $attributes, array $studentIds): ClassSession
    {
        return DB::transaction(function () use ($session, $attributes, $studentIds): ClassSession {
            $this->validateTime($attributes, $session);
            $this->validateStudents($attributes, $studentIds, $session);

            $observed = $session->observations()->pluck('student_id')->map(fn ($id): int => (int) $id)->all();
            if (array_diff($observed, $studentIds) !== []) {
                throw ValidationException::withMessages([
                    'student_ids' => 'Siswa yang sudah memiliki observasi tidak bisa dikeluarkan dari presensi.',
                ]);
            }

            $session->update($attributes);
            $session->students()->sync($studentIds);
            $session->attendances()->whereNotIn('student_id', $studentIds)->whereNull('marked_at')->delete();
            foreach ($studentIds as $studentId) {
                $session->attendances()->firstOrCreate(['student_id' => $studentId], [
                    'status' => 'unmarked',
                    'marked_by' => null,
                    'marked_at' => null,
                ]);
            }

            return $session->fresh();
        });
    }

    /** @param array{class_note?: ?string, follow_up_recommendation?: ?string} $notes */
    public function updateNotes(ClassSession $session, array $notes): void
    {
        $session->update($notes);
    }

    /** @param array{class_note?: ?string, follow_up_recommendation?: ?string} $notes */
    public function close(ClassSession $session, array $notes, ?User $actor): int
    {
        $session->loadMissing(['students', 'attendances', 'observations']);
        $recap = $session->attendanceRecap();
        $session->update([
            ...$notes,
            'status' => 'completed',
            'closed_by' => $actor?->id,
            'closed_at' => now(),
        ]);

        return (int) $recap['unmarked'];
    }

    public function destroy(ClassSession $session): void
    {
        if ($session->status !== 'planned') {
            throw ValidationException::withMessages([
                'session' => 'Rollback legacy hanya boleh menghapus planned session tanpa evidence.',
            ]);
        }

        if ($session->observations()->exists()
            || $session->attendances()->whereNotNull('marked_at')->exists()) {
            throw ValidationException::withMessages([
                'session' => 'Sesi belajar tidak bisa dihapus karena sudah memiliki observation atau marked attendance evidence.',
            ]);
        }

        $occurrence = SessionOccurrence::query()
            ->where('legacy_class_session_id', $session->id)
            ->first();

        if ($occurrence) {
            if ($occurrence->status !== 'planned' || $occurrence->presentations()->exists()) {
                throw ValidationException::withMessages([
                    'session' => 'Canonical occurrence sudah historical atau memiliki presentation evidence dan tidak boleh dihapus lewat rollback legacy.',
                ]);
            }

            $bookings = ChildSessionBooking::query()
                ->where('session_occurrence_id', $occurrence->id)
                ->get();

            foreach ($bookings as $booking) {
                $hasMovement = BookingMovement::query()
                    ->where('source_booking_id', $booking->id)
                    ->orWhere('destination_booking_id', $booking->id)
                    ->exists();

                if ($booking->status !== 'scheduled'
                    || $booking->session_credit_id !== null
                    || $hasMovement) {
                    throw ValidationException::withMessages([
                        'session' => 'Canonical booking memiliki terminal history, credit, atau movement dan tidak boleh dihapus lewat rollback legacy.',
                    ]);
                }
            }
        }

        $session->attendances()->whereNull('marked_at')->delete();
        $session->students()->detach();
        $session->delete();
    }

    /** @param array<string, mixed> $payload */
    private function validateTime(array $payload, ?ClassSession $ignore = null): void
    {
        if ($payload['ends_at'] <= $payload['starts_at']) {
            throw ValidationException::withMessages(['ends_at' => 'Jam selesai harus setelah jam mulai.']);
        }

        $room = $this->normalizeRoom($payload['room'] ?? null);
        $overlap = ClassSession::query()
            ->whereDate('session_date', $payload['session_date'])
            ->where('starts_at', '<', $payload['ends_at'])
            ->where('ends_at', '>', $payload['starts_at'])
            ->where(function ($query) use ($payload, $room): void {
                $query->where('school_class_id', $payload['school_class_id'])
                    ->orWhere('teacher_id', $payload['teacher_id']);
                if ($room !== null) {
                    $query->orWhere('room', $room);
                }
            })
            ->when($ignore, fn ($query) => $query->whereKeyNot($ignore->id))
            ->exists();

        if ($overlap) {
            throw ValidationException::withMessages(['starts_at' => 'Presensi bentrok dengan kelas, guru, atau ruangan.']);
        }
    }

    /** @param array<string, mixed> $payload @param array<int, int> $studentIds */
    private function validateStudents(array $payload, array $studentIds, ?ClassSession $ignore = null): void
    {
        if (count($studentIds) > (int) $payload['capacity']) {
            throw ValidationException::withMessages(['student_ids' => 'Jumlah peserta melebihi kapasitas.']);
        }

        if ($studentIds === []) {
            return;
        }

        $conflict = ClassSession::query()
            ->whereDate('session_date', $payload['session_date'])
            ->where('starts_at', '<', $payload['ends_at'])
            ->where('ends_at', '>', $payload['starts_at'])
            ->whereHas('students', fn ($query) => $query->whereIn('students.id', $studentIds))
            ->when($ignore, fn ($query) => $query->whereKeyNot($ignore->id))
            ->exists();

        if ($conflict) {
            throw ValidationException::withMessages(['student_ids' => 'Ada siswa yang sudah terdaftar pada presensi lain.']);
        }
    }

    private function normalizeRoom(?string $room): ?string
    {
        $room = trim((string) $room);

        return $room === '' ? null : $room;
    }
}
