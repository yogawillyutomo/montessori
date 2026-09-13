<?php

namespace Tests\Feature;

use App\Models\ClassLevel;
use App\Models\SessionPlan;
use App\Models\Student;
use App\Models\User;
use App\Services\Enrollment\ChildEnrollmentService;
use App\Services\Enrollment\EnrollmentPlanService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EnrollmentPlanLifecycleTest extends TestCase
{
    use RefreshDatabase;

    public function test_transfer_closes_effective_assignment_and_audits_future_assignment_cancellation(): void
    {
        $this->seed();

        $student = Student::query()->firstOrFail();
        $admin = User::query()->where('role', 'admin')->firstOrFail();
        $sourceLevel = $this->level('lifecycle-source-m6', 'Lifecycle Source M6');
        $destinationLevel = $this->level('lifecycle-destination-m6', 'Lifecycle Destination M6');
        $plan4 = $this->plan($sourceLevel, 'LIFECYCLE-M6-4', 4, true);
        $plan8 = $this->plan($sourceLevel, 'LIFECYCLE-M6-8', 8, false);
        $enrollment = app(ChildEnrollmentService::class)->enroll(
            $student,
            $sourceLevel,
            '2026-09-01',
            $admin,
        );
        $initial = app(EnrollmentPlanService::class)->assignInitialPlan(
            $enrollment,
            $plan4,
            $admin,
        );
        $future = app(EnrollmentPlanService::class)->schedulePlanChange(
            $enrollment,
            $plan8,
            $admin,
            'Upgrade planned for October.',
            '2026-10-01',
        );

        $destination = app(ChildEnrollmentService::class)->transfer(
            $enrollment,
            $destinationLevel,
            '2026-09-20',
            $admin,
            'Program transition before October.',
        );

        $this->assertSame('2026-09-19', $initial->fresh()->valid_until->toDateString());
        $future->refresh();
        $this->assertNotNull($future->cancelled_at);
        $this->assertSame($admin->id, $future->cancelled_by);
        $this->assertSame('Program transfer: Program transition before October.', $future->cancellation_reason);
        $this->assertNull(app(EnrollmentPlanService::class)->assignmentOn($enrollment, '2026-10-01'));
        $this->assertSame($enrollment->id, $destination->previous_enrollment_id);
    }

    private function level(string $slug, string $name): ClassLevel
    {
        return ClassLevel::query()->create([
            'name' => $name,
            'slug' => $slug,
            'sequence' => 91,
            'is_active' => true,
        ]);
    }

    private function plan(ClassLevel $level, string $code, int $quantity, bool $default): SessionPlan
    {
        return SessionPlan::query()->create([
            'name' => $code,
            'code' => $code,
            'class_level_id' => $level->id,
            'entitlement_quantity' => $quantity,
            'entitlement_period' => 'monthly',
            'preferred_weekly_frequency' => 1,
            'makeup_policy' => ['school_cancel' => true],
            'rollover_policy' => ['mode' => 'off'],
            'is_default' => $default,
            'is_active' => true,
        ]);
    }
}
