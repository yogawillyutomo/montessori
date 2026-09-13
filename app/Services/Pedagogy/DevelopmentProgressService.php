<?php

namespace App\Services\Pedagogy;

use App\Models\DevelopmentProgress;
use App\Models\Indicator;
use App\Models\MontessoriActivity;
use App\Models\Student;
use App\Models\Teacher;
use App\Models\User;
use App\Services\Alpha\AccessScopeService;
use App\Support\Alpha\Role;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

class DevelopmentProgressService
{
    public function __construct(private readonly AccessScopeService $scope) {}

    public function record(
        Student $student,
        Teacher $judge,
        User $actor,
        string $progressState,
        ?Indicator $indicator = null,
        ?MontessoriActivity $activity = null,
        ?string $judgedAt = null,
        ?string $note = null,
    ): DevelopmentProgress {
        if (! in_array($actor->role, [Role::SUPER_ADMIN, Role::ADMIN, Role::TEACHER], true)) {
            throw new AuthorizationException('Pengguna ini tidak diizinkan mencatat perkembangan anak.');
        }

        if (! $this->scope->canViewStudent($actor, $student)) {
            throw new AuthorizationException('Guide tidak memiliki akses ke anak ini.');
        }

        if ($actor->role === Role::TEACHER) {
            $actorTeacher = $this->scope->teacherFor($actor);

            if (! $actorTeacher || (int) $actorTeacher->id !== (int) $judge->id) {
                throw new AuthorizationException('Guide hanya boleh mencatat judgement atas nama dirinya sendiri.');
            }
        }

        if (! in_array($progressState, DevelopmentProgress::STATES, true)) {
            throw ValidationException::withMessages([
                'progress_state' => 'Progress state tidak dikenal.',
            ]);
        }

        if (($indicator === null) === ($activity === null)) {
            throw ValidationException::withMessages([
                'indicator_id' => 'Pilih tepat satu fokus perkembangan: indicator atau Montessori activity.',
                'montessori_activity_id' => 'Pilih tepat satu fokus perkembangan: indicator atau Montessori activity.',
            ]);
        }

        if ($indicator && ! $indicator->is_active) {
            throw ValidationException::withMessages([
                'indicator_id' => 'Indicator yang nonaktif tidak dapat digunakan untuk judgement baru.',
            ]);
        }

        if ($activity && ! $activity->is_active) {
            throw ValidationException::withMessages([
                'montessori_activity_id' => 'Activity yang nonaktif tidak dapat digunakan untuk judgement baru.',
            ]);
        }

        $judgementMoment = $judgedAt ? Carbon::parse($judgedAt) : now();

        if ($judgementMoment->isFuture()) {
            throw ValidationException::withMessages([
                'judged_at' => 'Judgement perkembangan tidak boleh bertanggal di masa depan.',
            ]);
        }

        return DevelopmentProgress::query()->create([
            'student_id' => $student->id,
            'indicator_id' => $indicator?->id,
            'montessori_activity_id' => $activity?->id,
            'progress_state' => $progressState,
            'judged_by' => $judge->id,
            'judged_at' => $judgementMoment,
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
