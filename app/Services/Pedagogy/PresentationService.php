<?php

namespace App\Services\Pedagogy;

use App\Models\MontessoriActivity;
use App\Models\Presentation;
use App\Models\SessionOccurrence;
use App\Models\Student;
use App\Models\Teacher;
use App\Models\User;
use App\Services\Alpha\AccessScopeService;
use App\Support\Alpha\Role;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

class PresentationService
{
    public function __construct(private readonly AccessScopeService $scope) {}

    public function record(
        Student $student,
        Teacher $teacher,
        MontessoriActivity $activity,
        User $actor,
        string $presentedOn,
        ?SessionOccurrence $sessionOccurrence = null,
        ?string $presentationType = null,
        ?string $note = null,
    ): Presentation {
        if (! in_array($actor->role, [Role::SUPER_ADMIN, Role::ADMIN, Role::TEACHER], true)) {
            throw new AuthorizationException('Pengguna ini tidak diizinkan mencatat presentation.');
        }

        if (! $this->scope->canViewStudent($actor, $student)) {
            throw new AuthorizationException('Guide tidak memiliki akses ke anak ini.');
        }

        if ($actor->role === Role::TEACHER) {
            $actorTeacher = $this->scope->teacherFor($actor);

            if (! $actorTeacher || (int) $actorTeacher->id !== (int) $teacher->id) {
                throw new AuthorizationException('Guide hanya boleh mencatat presentation atas nama dirinya sendiri.');
            }
        }

        if (! $activity->is_active) {
            throw ValidationException::withMessages([
                'montessori_activity_id' => 'Activity yang nonaktif tidak dapat digunakan untuk presentation baru.',
            ]);
        }

        $presentationDate = Carbon::parse($presentedOn)->startOfDay();

        if ($presentationDate->isAfter(now()->startOfDay())) {
            throw ValidationException::withMessages([
                'presented_on' => 'Presentation adalah catatan kejadian yang sudah berlangsung dan tidak boleh bertanggal di masa depan.',
            ]);
        }

        if ($sessionOccurrence) {
            if ($sessionOccurrence->status === 'cancelled') {
                throw ValidationException::withMessages([
                    'session_occurrence_id' => 'Presentation tidak dapat dikaitkan ke session occurrence yang dibatalkan.',
                ]);
            }

            if ($sessionOccurrence->occurs_on->toDateString() !== $presentationDate->toDateString()) {
                throw ValidationException::withMessages([
                    'presented_on' => 'Tanggal presentation harus sama dengan tanggal session occurrence yang dipilih.',
                ]);
            }

            $hasBooking = $sessionOccurrence->activeBookings()
                ->where('student_id', $student->id)
                ->exists();

            if (! $hasBooking) {
                throw ValidationException::withMessages([
                    'session_occurrence_id' => 'Anak harus memiliki booking aktif pada session occurrence yang dipilih.',
                ]);
            }
        }

        return Presentation::query()->create([
            'student_id' => $student->id,
            'teacher_id' => $teacher->id,
            'montessori_activity_id' => $activity->id,
            'session_occurrence_id' => $sessionOccurrence?->id,
            'presented_on' => $presentationDate->toDateString(),
            'presentation_type' => $this->normalizeNullableText($presentationType),
            'note' => $this->normalizeNullableText($note),
            'recorded_by' => $actor->id,
        ]);
    }

    private function normalizeNullableText(?string $value): ?string
    {
        $normalized = trim((string) $value);

        return $normalized === '' ? null : $normalized;
    }
}
