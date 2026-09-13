<?php

namespace App\Services\Pedagogy;

use App\Models\FollowUpCandidate;
use App\Models\IlpPlan;
use App\Models\Indicator;
use App\Models\Observation;
use App\Models\Student;
use App\Models\Term;
use App\Models\User;
use App\Services\Alpha\AccessScopeService;
use App\Support\Alpha\Role;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class FollowUpCandidateService
{
    public function __construct(private readonly AccessScopeService $scope) {}

    public function captureFromObservation(Observation $observation, User $actor): ?FollowUpCandidate
    {
        if (! $observation->needs_follow_up) {
            return null;
        }

        $this->authorizeActorForStudent($actor, $observation->student);

        if ($actor->role === Role::TEACHER) {
            $teacher = $this->scope->teacherFor($actor);

            if (! $teacher || (int) $teacher->id !== (int) $observation->teacher_id) {
                throw new AuthorizationException('Guide hanya boleh membuat follow-up candidate dari observasinya sendiri.');
            }
        }

        return FollowUpCandidate::query()->firstOrCreate(
            ['source_observation_id' => $observation->id],
            [
                'student_id' => $observation->student_id,
                'indicator_id' => $observation->indicator_id,
                'status' => FollowUpCandidate::STATUS_OPEN,
                'reason_summary' => 'Observation ditandai memerlukan tindak lanjut dan perlu ditinjau oleh guide.',
                'created_by' => $actor->id,
            ]
        );
    }

    public function confirm(
        FollowUpCandidate $candidate,
        User $actor,
        ?Indicator $indicator = null,
        ?string $reviewNote = null,
    ): IlpPlan {
        return DB::transaction(function () use ($candidate, $actor, $indicator, $reviewNote): IlpPlan {
            $locked = FollowUpCandidate::query()
                ->with(['student', 'indicator', 'sourceObservation'])
                ->lockForUpdate()
                ->findOrFail($candidate->id);

            $this->authorizeActorForStudent($actor, $locked->student);
            $this->ensureOpen($locked);

            $resolvedIndicator = $locked->indicator ?? $indicator;

            if (! $resolvedIndicator) {
                throw ValidationException::withMessages([
                    'indicator_id' => 'Indicator wajib dipilih sebelum follow-up candidate dikonfirmasi menjadi support plan.',
                ]);
            }

            if (! $resolvedIndicator->is_active) {
                throw ValidationException::withMessages([
                    'indicator_id' => 'Indicator nonaktif tidak dapat digunakan untuk support plan baru.',
                ]);
            }

            $sourceAreaId = $locked->sourceObservation?->development_area_id;
            if ($sourceAreaId && (int) $resolvedIndicator->development_area_id !== (int) $sourceAreaId) {
                throw ValidationException::withMessages([
                    'indicator_id' => 'Indicator harus berasal dari area perkembangan yang sama dengan observation sumber.',
                ]);
            }

            $term = Term::query()->where('is_current', true)->first();

            $plan = IlpPlan::query()->create([
                'student_id' => $locked->student_id,
                'indicator_id' => $resolvedIndicator->id,
                'term_id' => $term?->id,
                'trigger_observation_id' => $locked->source_observation_id,
                'follow_up_candidate_id' => $locked->id,
                'status' => 'draft',
                'analysis' => null,
                'target' => null,
                'follow_up' => null,
                'starts_on' => null,
                'ends_on' => null,
            ]);

            $locked->update([
                'indicator_id' => $resolvedIndicator->id,
                'status' => FollowUpCandidate::STATUS_CONFIRMED,
                'reviewed_by' => $actor->id,
                'reviewed_at' => now(),
                'review_note' => $this->normalizeNullableText($reviewNote),
            ]);

            return $plan;
        });
    }

    public function dismiss(FollowUpCandidate $candidate, User $actor, ?string $reviewNote = null): FollowUpCandidate
    {
        return DB::transaction(function () use ($candidate, $actor, $reviewNote): FollowUpCandidate {
            $locked = FollowUpCandidate::query()
                ->with('student')
                ->lockForUpdate()
                ->findOrFail($candidate->id);

            $this->authorizeActorForStudent($actor, $locked->student);
            $this->ensureOpen($locked);

            $locked->update([
                'status' => FollowUpCandidate::STATUS_DISMISSED,
                'reviewed_by' => $actor->id,
                'reviewed_at' => now(),
                'review_note' => $this->normalizeNullableText($reviewNote),
            ]);

            return $locked->fresh();
        });
    }

    private function authorizeActorForStudent(User $actor, Student $student): void
    {
        if (! in_array($actor->role, [Role::SUPER_ADMIN, Role::ADMIN, Role::TEACHER], true)) {
            throw new AuthorizationException('Pengguna ini tidak diizinkan meninjau follow-up candidate.');
        }

        if (! $this->scope->canViewStudent($actor, $student)) {
            throw new AuthorizationException('Guide tidak memiliki akses ke anak ini.');
        }
    }

    private function ensureOpen(FollowUpCandidate $candidate): void
    {
        if ($candidate->status !== FollowUpCandidate::STATUS_OPEN) {
            throw ValidationException::withMessages([
                'candidate' => 'Follow-up candidate ini sudah ditinjau dan tidak dapat diproses ulang.',
            ]);
        }
    }

    private function normalizeNullableText(?string $value): ?string
    {
        $normalized = trim((string) $value);

        return $normalized === '' ? null : $normalized;
    }
}
