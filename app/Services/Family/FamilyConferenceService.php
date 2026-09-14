<?php

namespace App\Services\Family;

use App\Models\FamilyConference;
use App\Models\Student;
use App\Models\Teacher;
use App\Models\User;
use App\Services\Alpha\AccessScopeService;
use App\Support\Alpha\Role;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class FamilyConferenceService
{
    public function __construct(
        private readonly AccessScopeService $scope,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(Student $student, User $actor, array $data): FamilyConference
    {
        $this->assertCanManage($actor, $student);
        $payload = $this->normalizePayload($student, $actor, $data);

        return DB::transaction(function () use ($student, $actor, $payload): FamilyConference {
            return FamilyConference::query()->create([
                'student_id' => $student->id,
                ...$payload,
                'created_by' => $actor->id,
                'updated_by' => $actor->id,
            ])->fresh(['student', 'leadTeacher', 'reportVersion.report.reportCycle', 'createdBy', 'updatedBy']);
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(FamilyConference $conference, User $actor, array $data): FamilyConference
    {
        $conference->loadMissing('student');
        $this->assertCanManage($actor, $conference->student);
        $payload = $this->normalizePayload($conference->student, $actor, $data);

        return DB::transaction(function () use ($conference, $actor, $payload): FamilyConference {
            $locked = FamilyConference::query()
                ->whereKey($conference->id)
                ->lockForUpdate()
                ->firstOrFail();

            $locked->forceFill([
                ...$payload,
                'updated_by' => $actor->id,
            ])->save();

            return $locked->fresh(['student', 'leadTeacher', 'reportVersion.report.reportCycle', 'createdBy', 'updatedBy']);
        });
    }

    public function canView(User $actor, FamilyConference $conference): bool
    {
        $conference->loadMissing('student');

        if (! $this->scope->canViewStudent($actor, $conference->student)) {
            return false;
        }

        if ($actor->role === Role::PARENT) {
            return $conference->share_with_parent;
        }

        return true;
    }

    public function canViewStudent(User $actor, Student $student): bool
    {
        return $this->scope->canViewStudent($actor, $student);
    }

    public function canManage(User $actor, Student $student): bool
    {
        return $this->scope->canGenerateReport($actor)
            && $this->scope->canViewStudent($actor, $student);
    }

    private function assertCanManage(User $actor, Student $student): void
    {
        if (! $this->canManage($actor, $student)) {
            throw ValidationException::withMessages([
                'actor' => 'Actor tidak berwenang mencatat atau mengubah family conference anak ini.',
            ]);
        }
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function normalizePayload(Student $student, User $actor, array $data): array
    {
        $leadTeacherId = $data['lead_teacher_id'] ?? null;

        if ($actor->role === Role::TEACHER) {
            $teacher = $this->scope->teacherFor($actor);
            if (! $teacher) {
                throw ValidationException::withMessages([
                    'lead_teacher_id' => 'Akun teacher harus terhubung ke profil teacher untuk mencatat family conference.',
                ]);
            }

            $leadTeacherId = $teacher->id;
        }

        if ($leadTeacherId !== null) {
            $teacher = Teacher::query()->find($leadTeacherId);
            if (! $teacher || ! $teacher->is_active) {
                throw ValidationException::withMessages([
                    'lead_teacher_id' => 'Lead teacher harus menggunakan profil teacher yang aktif.',
                ]);
            }
        }

        $summary = $this->nullableText($data['summary'] ?? null);
        $shareWithParent = (bool) ($data['share_with_parent'] ?? false);

        if ($shareWithParent && $summary === null) {
            throw ValidationException::withMessages([
                'summary' => 'Ringkasan konferensi wajib diisi sebelum dibagikan kepada orang tua.',
            ]);
        }

        return [
            'conference_on' => $data['conference_on'],
            'lead_teacher_id' => $leadTeacherId,
            'report_version_id' => $data['report_version_id'] ?? null,
            'summary' => $summary,
            'strengths' => $this->nullableText($data['strengths'] ?? null),
            'areas_to_support' => $this->nullableText($data['areas_to_support'] ?? null),
            'parent_observation' => $this->nullableText($data['parent_observation'] ?? null),
            'agreed_follow_up' => $this->nullableText($data['agreed_follow_up'] ?? null),
            'next_review_on' => $data['next_review_on'] ?? null,
            'share_with_parent' => $shareWithParent,
        ];
    }

    private function nullableText(mixed $value): ?string
    {
        $text = trim((string) $value);

        return $text === '' ? null : $text;
    }
}
