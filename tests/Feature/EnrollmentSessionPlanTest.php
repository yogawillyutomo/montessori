<?php

namespace Tests\Feature;

use App\Models\ChildEnrollment;
use App\Models\ChildSessionBooking;
use App\Models\ClassLevel;
use App\Models\EnrollmentPlanAssignment;
use App\Models\EntitlementPeriod;
use App\Models\RecurringSchedule;
use App\Models\SessionOccurrence;
use App\Models\SessionPlan;
use App\Models\Student;
use App\Models\User;
use App\Services\Enrollment\ChildEnrollmentService;
use App\Services\Enrollment\EnrollmentPlanService;
use App\Services\Scheduling\BookingRescheduleService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class EnrollmentSessionPlanTest extends TestCase
{
    use RefreshDatabase;

    public function test_m6_does_not_infer_enrollment_or_plan_from_legacy_schedule_frequency(): void
    {
        $this->seed();

        $this->assertSame(0, ChildEnrollment::query()->count());
        $this->assertSame(0, SessionPlan::query()->count());
        $this->assertSame(0, EnrollmentPlanAssignment::query()->count());
        $this->assertSame(0, RecurringSchedule::query()->whereNotNull('child_enrollment_id')->count());
        $this->assertSame(0, ChildSessionBooking::query()->whereNotNull('child_enrollment_id')->count());
    }

    public function test_mid_month_enrollment_can_receive_explicit_default_plan_without_creating_period_entitlement(): void
    {
        $this->seed();

        $student = Student::query()->firstOrFail();
        $admin = User::query()->where('role', 'admin')->firstOrFail();
        $level = $this->level('infant-m6', 'Infant M6');
        $plan = $this->plan($level, 'INFANT-M6-8', 8, true);

        $enrollment = app(ChildEnrollmentService::class)->enroll(
            $student,
            $level,
            '2026-09-20',
            $admin,
            '2026-09-22',
        );
        $assignment = app(EnrollmentPlanService::class)->assignInitialPlan(
            $enrollment,
            $plan,
            $admin,
        );

        $this->assertSame('2026-09-20', $enrollment->starts_on->toDateString());
        $this->assertSame('2026-09-22', $enrollment->first_session_on->toDateString());
        $this->assertSame(8, $plan->entitlement_quantity);
        $this->assertSame('monthly', $plan->entitlement_period);
        $this->assertSame('default', $assignment->assignment_type);
        $this->assertSame('2026-09-20', $assignment->valid_from->toDateString());
        $this->assertNull($assignment->valid_until);
        $this->assertSame(0, EntitlementPeriod::query()->count());
    }

    public function test_custom_plan_override_requires_authorized_actor_and_reason(): void
    {
        $this->seed();

        $student = Student::query()->firstOrFail();
        $admin = User::query()->where('role', 'admin')->firstOrFail();
        $teacher = User::query()->where('role', 'teacher')->firstOrFail();
        $level = $this->level('glow-m6', 'Glow M6');
        $plan = $this->plan($level, 'GLOW-M6-8-OVERRIDE', 8, false);
        $enrollment = app(ChildEnrollmentService::class)->enroll($student, $level, '2026-09-01', $admin);

        try {
            app(EnrollmentPlanService::class)->assignInitialPlan($enrollment, $plan, $teacher, null, 'Teacher request');
            $this->fail('Teacher must not assign service plan overrides.');
        } catch (ValidationException) {
            $this->assertSame(0, $enrollment->planAssignments()->count());
        }

        try {
            app(EnrollmentPlanService::class)->assignInitialPlan($enrollment, $plan, $admin);
            $this->fail('Non-default plan must require override reason.');
        } catch (ValidationException) {
            $this->assertSame(0, $enrollment->planAssignments()->count());
        }

        $assignment = app(EnrollmentPlanService::class)->assignInitialPlan(
            $enrollment,
            $plan,
            $admin,
            null,
            'Individual service agreement.',
        );

        $this->assertSame('override', $assignment->assignment_type);
        $this->assertSame('Individual service agreement.', $assignment->reason);
        $this->assertSame($admin->id, $assignment->created_by);
    }

    public function test_assigned_plan_core_is_immutable_and_plan_change_preserves_effective_dated_history(): void
    {
        $this->seed();

        $student = Student::query()->firstOrFail();
        $admin = User::query()->where('role', 'admin')->firstOrFail();
        $level = $this->level('plan-history-m6', 'Plan History M6');
        $plan8 = $this->plan($level, 'PLAN-HISTORY-8', 8, true);
        $plan12 = $this->plan($level, 'PLAN-HISTORY-12', 12, false);
        $enrollment = app(ChildEnrollmentService::class)->enroll($student, $level, '2026-09-01', $admin);
        $initial = app(EnrollmentPlanService::class)->assignInitialPlan($enrollment, $plan8, $admin);

        try {
            $plan8->update(['entitlement_quantity' => 10]);
            $this->fail('Assigned plan entitlement must be immutable.');
        } catch (ValidationException) {
            $this->assertSame(8, $plan8->fresh()->entitlement_quantity);
        }

        try {
            app(EnrollmentPlanService::class)->schedulePlanChange(
                $enrollment,
                $plan12,
                $admin,
                'Requested upgrade.',
                '2026-09-15',
            );
            $this->fail('Mid-period permanent monthly plan change should be rejected in M6.');
        } catch (ValidationException) {
            $this->assertNull($initial->fresh()->valid_until);
        }

        $change = app(EnrollmentPlanService::class)->schedulePlanChange(
            $enrollment,
            $plan12,
            $admin,
            'Requested upgrade.',
            '2026-10-01',
        );

        $this->assertSame('2026-09-30', $initial->fresh()->valid_until->toDateString());
        $this->assertSame('change', $change->assignment_type);
        $this->assertSame('2026-10-01', $change->valid_from->toDateString());
        $this->assertSame($plan8->id, app(EnrollmentPlanService::class)->assignmentOn($enrollment, '2026-09-30')?->session_plan_id);
        $this->assertSame($plan12->id, app(EnrollmentPlanService::class)->assignmentOn($enrollment, '2026-10-01')?->session_plan_id);
    }

    public function test_default_monthly_plan_change_without_explicit_date_uses_next_month_boundary(): void
    {
        $this->seed();
        CarbonImmutable::setTestNow('2026-09-13 10:00:00');

        try {
            $student = Student::query()->firstOrFail();
            $admin = User::query()->where('role', 'admin')->firstOrFail();
            $level = $this->level('default-change-m6', 'Default Change M6');
            $plan4 = $this->plan($level, 'DEFAULT-CHANGE-4', 4, true);
            $plan8 = $this->plan($level, 'DEFAULT-CHANGE-8', 8, false);
            $enrollment = app(ChildEnrollmentService::class)->enroll($student, $level, '2026-09-01', $admin);
            app(EnrollmentPlanService::class)->assignInitialPlan($enrollment, $plan4, $admin);

            $change = app(EnrollmentPlanService::class)->schedulePlanChange(
                $enrollment,
                $plan8,
                $admin,
                'Upgrade next period.',
            );

            $this->assertSame('2026-10-01', $change->valid_from->toDateString());
        } finally {
            CarbonImmutable::setTestNow();
        }
    }

    public function test_only_one_active_default_plan_is_allowed_per_program(): void
    {
        $this->seed();

        $level = $this->level('one-default-m6', 'One Default M6');
        $this->plan($level, 'ONE-DEFAULT-4', 4, true);

        $this->expectException(ValidationException::class);
        $this->plan($level, 'ONE-DEFAULT-8', 8, true);
    }

    public function test_child_enrollment_periods_cannot_overlap(): void
    {
        $this->seed();

        $student = Student::query()->firstOrFail();
        $admin = User::query()->where('role', 'admin')->firstOrFail();
        $levelA = $this->level('overlap-a-m6', 'Overlap A M6');
        $levelB = $this->level('overlap-b-m6', 'Overlap B M6');

        app(ChildEnrollmentService::class)->enroll($student, $levelA, '2026-09-01', $admin);

        $this->expectException(ValidationException::class);
        app(ChildEnrollmentService::class)->enroll($student, $levelB, '2026-09-15', $admin);
    }

    public function test_program_transfer_requires_future_booking_reconciliation_and_preserves_old_enrollment(): void
    {
        $this->seed();

        $student = Student::query()->firstOrFail();
        $admin = User::query()->where('role', 'admin')->firstOrFail();
        $levelA = $this->level('transfer-a-m6', 'Transfer A M6');
        $levelB = $this->level('transfer-b-m6', 'Transfer B M6');
        $source = app(ChildEnrollmentService::class)->enroll($student, $levelA, '2026-09-01', $admin);
        $occurrence = $this->occurrence('2026-09-20');
        $booking = $this->booking($student, $source, $occurrence);

        try {
            app(ChildEnrollmentService::class)->transfer(
                $source,
                $levelB,
                '2026-09-15',
                $admin,
                'Move to new program.',
            );
            $this->fail('Transfer with future active booking should be rejected.');
        } catch (ValidationException) {
            $this->assertSame('active', $source->fresh()->status);
        }

        $booking->update(['status' => 'cancelled']);
        $destination = app(ChildEnrollmentService::class)->transfer(
            $source,
            $levelB,
            '2026-09-15',
            $admin,
            'Move to new program.',
        );

        $source->refresh();
        $this->assertSame('ended', $source->status);
        $this->assertSame('2026-09-14', $source->ends_on->toDateString());
        $this->assertSame('Program transfer: Move to new program.', $source->ended_reason);
        $this->assertSame($source->id, $destination->previous_enrollment_id);
        $this->assertSame($levelB->id, $destination->class_level_id);
        $this->assertSame('2026-09-15', $destination->starts_on->toDateString());
    }

    public function test_suspension_requires_booking_reconciliation_and_blocks_new_bookings_in_suspended_window(): void
    {
        $this->seed();

        $student = Student::query()->firstOrFail();
        $admin = User::query()->where('role', 'admin')->firstOrFail();
        $level = $this->level('suspend-m6', 'Suspend M6');
        $enrollment = app(ChildEnrollmentService::class)->enroll($student, $level, '2026-09-01', $admin);
        $occurrence = $this->occurrence('2026-09-20');
        $booking = $this->booking($student, $enrollment, $occurrence);

        try {
            app(ChildEnrollmentService::class)->suspend(
                $enrollment,
                '2026-09-15',
                '2026-09-30',
                $admin,
                'Family pause.',
            );
            $this->fail('Suspension with active booking should be rejected.');
        } catch (ValidationException) {
            $this->assertSame('active', $enrollment->fresh()->status);
        }

        $booking->update(['status' => 'cancelled']);
        $suspended = app(ChildEnrollmentService::class)->suspend(
            $enrollment,
            '2026-09-15',
            '2026-09-30',
            $admin,
            'Family pause.',
        );

        $this->assertSame('suspended', $suspended->status);
        $this->assertSame('Family pause.', $suspended->suspension_reason);

        $this->expectException(ValidationException::class);
        $this->booking($student, $suspended, $this->occurrence('2026-09-25'));
    }

    public function test_reschedule_preserves_child_enrollment_context(): void
    {
        $this->seed();

        $student = Student::query()->firstOrFail();
        $admin = User::query()->where('role', 'admin')->firstOrFail();
        $level = $this->level('movement-m6', 'Movement M6');
        $enrollment = app(ChildEnrollmentService::class)->enroll($student, $level, '2026-09-01', $admin);
        $sourceOccurrence = $this->occurrence('2026-09-10');
        $destinationOccurrence = $this->occurrence('2026-09-11');
        $source = $this->booking($student, $enrollment, $sourceOccurrence);

        $movement = app(BookingRescheduleService::class)->reschedule(
            $source,
            $destinationOccurrence,
            $admin,
            'Move within enrollment.',
        );

        $destination = $movement->destinationBooking()->firstOrFail();
        $this->assertSame($enrollment->id, $destination->child_enrollment_id);
        $this->assertSame($student->id, $destination->student_id);
    }

    private function level(string $slug, string $name): ClassLevel
    {
        return ClassLevel::query()->create([
            'name' => $name,
            'slug' => $slug,
            'sequence' => 90,
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
            'preferred_weekly_frequency' => $quantity >= 8 ? 2 : 1,
            'makeup_policy' => [
                'sick' => true,
                'excused' => true,
                'no_show' => false,
                'school_cancel' => true,
            ],
            'rollover_policy' => ['mode' => 'off'],
            'is_default' => $default,
            'is_active' => true,
        ]);
    }

    private function occurrence(string $date): SessionOccurrence
    {
        return SessionOccurrence::query()->create([
            'session_template_id' => null,
            'environment_id' => null,
            'occurs_on' => $date,
            'starts_at' => '10:00',
            'ends_at' => '11:00',
            'capacity' => 8,
            'room' => 'M6 Room '.$date,
            'status' => 'planned',
        ]);
    }

    private function booking(
        Student $student,
        ChildEnrollment $enrollment,
        SessionOccurrence $occurrence,
    ): ChildSessionBooking {
        return ChildSessionBooking::query()->create([
            'student_id' => $student->id,
            'child_enrollment_id' => $enrollment->id,
            'session_occurrence_id' => $occurrence->id,
            'booking_type' => 'regular',
            'status' => 'scheduled',
            'source_type' => 'test',
        ]);
    }
}
