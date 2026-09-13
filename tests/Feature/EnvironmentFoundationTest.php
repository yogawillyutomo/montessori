<?php

namespace Tests\Feature;

use App\Models\ChildGuideResponsibility;
use App\Models\Environment;
use App\Models\EnvironmentGuideAssignment;
use App\Models\EnvironmentMembership;
use App\Models\Student;
use App\Models\Teacher;
use App\Models\User;
use App\Services\Alpha\AccessScopeService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EnvironmentFoundationTest extends TestCase
{
    use RefreshDatabase;

    public function test_active_guide_assignment_grants_scope_only_while_environment_and_period_are_active(): void
    {
        $this->seed();
        Carbon::setTestNow('2026-09-13 09:00:00');

        try {
            [$teacherUser, $teacher, $outsideStudent] = $this->teacherAndOutsideStudent();
            $environment = $this->environment();

            EnvironmentMembership::query()->create([
                'environment_id' => $environment->id,
                'student_id' => $outsideStudent->id,
                'valid_from' => '2026-09-01',
                'valid_until' => null,
                'status' => 'active',
            ]);

            $assignment = EnvironmentGuideAssignment::query()->create([
                'environment_id' => $environment->id,
                'teacher_id' => $teacher->id,
                'assignment_role' => 'guide',
                'valid_from' => '2026-09-01',
                'valid_until' => null,
                'is_active' => true,
            ]);

            $this->assertContains($outsideStudent->id, $this->accessibleStudentIds($teacherUser));

            $assignment->update(['valid_until' => '2026-09-12']);
            $this->assertNotContains($outsideStudent->id, $this->accessibleStudentIds($teacherUser));

            $assignment->update(['valid_until' => null]);
            $environment->update(['is_active' => false]);
            $this->assertNotContains($outsideStudent->id, $this->accessibleStudentIds($teacherUser));
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_assistant_assignment_does_not_grant_broad_child_scope(): void
    {
        $this->seed();
        Carbon::setTestNow('2026-09-13 09:00:00');

        try {
            [$teacherUser, $teacher, $outsideStudent] = $this->teacherAndOutsideStudent();
            $environment = $this->environment();

            EnvironmentMembership::query()->create([
                'environment_id' => $environment->id,
                'student_id' => $outsideStudent->id,
                'valid_from' => '2026-09-01',
                'valid_until' => null,
                'status' => 'active',
            ]);

            $assignment = EnvironmentGuideAssignment::query()->create([
                'environment_id' => $environment->id,
                'teacher_id' => $teacher->id,
                'assignment_role' => 'assistant',
                'valid_from' => '2026-09-01',
                'valid_until' => null,
                'is_active' => true,
            ]);

            $this->assertNotContains($outsideStudent->id, $this->accessibleStudentIds($teacherUser));

            $assignment->update(['assignment_role' => 'lead_guide']);
            $this->assertContains($outsideStudent->id, $this->accessibleStudentIds($teacherUser));
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_expired_or_future_membership_does_not_grant_scope(): void
    {
        $this->seed();
        Carbon::setTestNow('2026-09-13 09:00:00');

        try {
            [$teacherUser, $teacher, $outsideStudent] = $this->teacherAndOutsideStudent();
            $environment = $this->environment();

            EnvironmentGuideAssignment::query()->create([
                'environment_id' => $environment->id,
                'teacher_id' => $teacher->id,
                'assignment_role' => 'guide',
                'valid_from' => '2026-09-01',
                'valid_until' => null,
                'is_active' => true,
            ]);

            $membership = EnvironmentMembership::query()->create([
                'environment_id' => $environment->id,
                'student_id' => $outsideStudent->id,
                'valid_from' => '2026-09-14',
                'valid_until' => null,
                'status' => 'active',
            ]);

            $this->assertNotContains($outsideStudent->id, $this->accessibleStudentIds($teacherUser));

            $membership->update([
                'valid_from' => '2026-09-01',
                'valid_until' => '2026-09-12',
            ]);
            $this->assertNotContains($outsideStudent->id, $this->accessibleStudentIds($teacherUser));

            $membership->update(['valid_until' => '2026-09-13']);
            $this->assertContains($outsideStudent->id, $this->accessibleStudentIds($teacherUser));
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_explicit_child_responsibility_grants_and_revokes_scope(): void
    {
        $this->seed();
        Carbon::setTestNow('2026-09-13 09:00:00');

        try {
            [$teacherUser, $teacher, $outsideStudent] = $this->teacherAndOutsideStudent();

            $responsibility = ChildGuideResponsibility::query()->create([
                'student_id' => $outsideStudent->id,
                'teacher_id' => $teacher->id,
                'responsibility_type' => 'primary_guide',
                'valid_from' => '2026-09-01',
                'valid_until' => null,
                'is_active' => true,
            ]);

            $this->assertContains($outsideStudent->id, $this->accessibleStudentIds($teacherUser));

            $responsibility->update(['is_active' => false]);
            $this->assertNotContains($outsideStudent->id, $this->accessibleStudentIds($teacherUser));
        } finally {
            Carbon::setTestNow();
        }
    }

    /**
     * @return array{0: User, 1: Teacher, 2: Student}
     */
    private function teacherAndOutsideStudent(): array
    {
        $teacherUser = User::query()->where('email', 'raras@montessori.test')->firstOrFail();
        $teacher = Teacher::query()->where('user_id', $teacherUser->id)->firstOrFail();
        $outsideStudent = Student::query()
            ->whereNotIn('id', $this->accessibleStudentIds($teacherUser))
            ->firstOrFail();

        return [$teacherUser, $teacher, $outsideStudent];
    }

    private function environment(): Environment
    {
        return Environment::query()->create([
            'name' => 'Children House Test',
            'code' => 'ENV-TEST',
            'age_range' => '3-6 years',
            'capacity' => 12,
            'is_active' => true,
        ]);
    }

    /**
     * @return array<int>
     */
    private function accessibleStudentIds(User $user): array
    {
        return app(AccessScopeService::class)->accessibleStudentIds($user);
    }
}
