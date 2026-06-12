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
        return $this->accessibleStudentsQuery($user)
            ->pluck('id')
            ->map(fn ($id): int => (int) $id)
            ->all();
    }

    public function accessibleStudentsQuery(User $user): Builder
    {
        if (Role::hasGlobalAccess($user->role)) {
            return Student::query();
        }

        if ($user->role === Role::TEACHER) {
            $teacher = $this->teacherFor($user);

            return Student::query()
                ->when($teacher, fn (Builder $query) => $this->scopeStudentsForTeacher($query, $teacher->id))
                ->when(! $teacher, fn (Builder $query) => $query->whereRaw('1 = 0'));
        }

        if ($user->role === Role::PARENT) {
            return Student::query()
                ->when(
                    $user->guardian,
                    fn (Builder $query) => $query->where('guardian_id', $user->guardian->id),
                    fn (Builder $query) => $query->whereRaw('1 = 0')
                );
        }

        return Student::query()->whereRaw('1 = 0');
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

    /**
     * @return array<int>
     */
    public function accessibleTeacherIds(User $user): array
    {
        if (Role::hasGlobalAccess($user->role)) {
            return Teacher::query()
                ->where('is_active', true)
                ->pluck('id')
                ->map(fn ($id): int => (int) $id)
                ->all();
        }

        if ($user->role === Role::TEACHER) {
            $teacher = $this->teacherFor($user);

            return $teacher ? [(int) $teacher->id] : [];
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

    public function canViewStudent(User $user, Student $student): bool
    {
        if (Role::hasGlobalAccess($user->role)) {
            return true;
        }

        return $this->accessibleStudentsQuery($user)
            ->whereKey($student->id)
            ->exists();
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
            ->where(fn (Builder $query) => $this->scopeStudentsForTeacher($query, $teacherId))
            ->pluck('id')
            ->map(fn ($id): int => (int) $id)
            ->all();
    }

    public function scopeStudentsForTeacher(Builder $query, int $teacherId): Builder
    {
        return $query->where(function (Builder $teacherQuery) use ($teacherId): void {
            $teacherQuery
                ->whereHas('weeklySchedules', fn (Builder $scheduleQuery) => $scheduleQuery->where('teacher_id', $teacherId))
                ->orWhereHas('classSessions', fn (Builder $sessionQuery) => $sessionQuery->where('teacher_id', $teacherId))
                ->orWhereHas('observations', fn (Builder $observationQuery) => $observationQuery->where('teacher_id', $teacherId));
        });
    }

    public function hasGlobalDataAccess(User $user): bool
    {
        return Role::hasGlobalAccess($user->role);
    }
}
