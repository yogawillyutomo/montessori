<?php

namespace App\Services\Alpha;

use App\Models\Report;
use App\Models\SchoolClass;
use App\Models\Student;
use App\Models\Teacher;
use App\Models\User;
use App\Support\Alpha\Role;
use Illuminate\Database\Eloquent\Builder;

class AccessScopeService
{
    /**
     * @return array<int>
     */
    public function accessibleStudentIds(User $user): array
    {
        if (Role::hasGlobalAccess($user->role)) {
            return Student::query()->pluck('id')->map(fn ($id): int => (int) $id)->all();
        }

        if ($user->role === Role::TEACHER) {
            $teacher = $this->teacherFor($user);

            return $teacher
                ? $this->studentIdsScheduledWithTeacher($teacher->id)
                : [];
        }

        if ($user->role === Role::PARENT) {
            return $user->guardian?->students()
                ->pluck('id')
                ->map(fn ($id): int => (int) $id)
                ->all() ?? [];
        }

        return [];
    }

    /**
     * @return array<int>
     */
    public function accessibleClassIds(User $user): array
    {
        if (Role::hasGlobalAccess($user->role)) {
            return SchoolClass::query()->pluck('id')->map(fn ($id): int => (int) $id)->all();
        }

        if ($user->role === Role::TEACHER) {
            $teacher = $this->teacherFor($user);

            if (! $teacher) {
                return [];
            }

            return SchoolClass::query()
                ->whereHas('weeklySchedules', fn (Builder $query) => $query->where('teacher_id', $teacher->id))
                ->orWhereHas('classSessions', fn (Builder $query) => $query->where('teacher_id', $teacher->id))
                ->pluck('id')
                ->map(fn ($id): int => (int) $id)
                ->all();
        }

        if ($user->role === Role::PARENT) {
            return Student::query()
                ->whereIn('id', $this->accessibleStudentIds($user))
                ->pluck('school_class_id')
                ->filter()
                ->unique()
                ->map(fn ($id): int => (int) $id)
                ->values()
                ->all();
        }

        return [];
    }

    public function canManageMasterData(User $user): bool
    {
        return in_array($user->role, [Role::SUPER_ADMIN, Role::ADMIN], true);
    }

    public function canGenerateReport(User $user): bool
    {
        return in_array($user->role, [Role::SUPER_ADMIN, Role::ADMIN, Role::TEACHER], true);
    }

    public function canApproveReport(User $user): bool
    {
        return in_array($user->role, [Role::SUPER_ADMIN, Role::PRINCIPAL], true);
    }

    public function canViewReport(User $user, Report $report): bool
    {
        if (in_array($user->role, [Role::SUPER_ADMIN, Role::ADMIN, Role::PRINCIPAL], true)) {
            return true;
        }

        if ($user->role === Role::PARENT && $report->status !== 'published') {
            return false;
        }

        return in_array((int) $report->student_id, $this->accessibleStudentIds($user), true);
    }

    public function teacherFor(User $user): ?Teacher
    {
        if ($user->role !== Role::TEACHER) {
            return null;
        }

        return $user->teacher ?? Teacher::query()->where('user_id', $user->id)->first();
    }

    /**
     * @return array<int>
     */
    public function studentIdsScheduledWithTeacher(int $teacherId): array
    {
        return Student::query()
            ->where(function (Builder $query) use ($teacherId): void {
                $query
                    ->whereHas('weeklySchedules', fn (Builder $scheduleQuery) => $scheduleQuery->where('teacher_id', $teacherId))
                    ->orWhereHas('classSessions', fn (Builder $sessionQuery) => $sessionQuery->where('teacher_id', $teacherId));
            })
            ->pluck('id')
            ->map(fn ($id): int => (int) $id)
            ->all();
    }

    public function hasGlobalDataAccess(User $user): bool
    {
        return Role::hasGlobalAccess($user->role);
    }
}
