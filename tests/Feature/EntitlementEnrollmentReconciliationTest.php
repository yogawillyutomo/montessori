<?php

namespace Tests\Feature;

use App\Models\ChildEnrollment;
use App\Models\ChildSessionBooking;
use App\Models\ClassLevel;
use App\Models\EntitlementPeriod;
use App\Models\SessionOccurrence;
use App\Models\SessionPlan;
use App\Models\Student;
use App\Models\User;
use App\Services\Enrollment\ChildEnrollmentService;
use App\Services\Enrollment\EnrollmentPlanService;
use App\Services\Entitlement\CreditAllocationService;
use App\Services\Entitlement\EntitlementPeriodService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class EntitlementEnrollmentReconciliationTest extends TestCase
{
    use RefreshDatabase;

    public function test_transfer_cancels_unused_future_entitlement_period_with_audit(): void
    {
        $this->seed();

        [$student, $admin, $sourceEnrollment] = $this->sourceEnrollment('M7-TRANSFER-CANCEL');
        $october = app(EntitlementPeriodService::class)->generateMonthly(
            $sourceEnrollment,
            '2026-10-01',
            $admin,
        );
        $destinationLevel = $this->level('m7-transfer-destination', 'M7 Transfer Destination');

        $destination = app(ChildEnrollmentService::class)->transfer(
            $sourceEnrollment,
            $destinationLevel,
            '2026-10-01',
            $admin,
            'Move to next Montessori program.',
        );

        $october->refresh();
        $sourceEnrollment->refresh();

        $this->assertSame('cancelled', $october->status);
        $this->assertSame($admin->id, $october->cancelled_by);
        $this->assertNotNull($october->cancelled_at);
        $this->assertSame('Program transfer: Move to next Montessori program.', $october->cancellation_reason);
        $this->assertSame('ended', $sourceEnrollment->status);
        $this->assertSame('2026-09-30', $sourceEnrollment->ends_on->toDateString());
        $this->assertSame($sourceEnrollment->id, $destination->previous_enrollment_id);
        $this->assertSame('2026-10-01', $destination->starts_on->toDateString());
        $this->assertSame(0, $october->committedCreditCount());
        $this->assertGreaterThan(0, $october->activeCreditCount());
    }

    public function test_transfer_refuses_future_period_with_committed_credit_even_after_booking_is_cancelled(): void
    {
        $this->seed();

        [$student, $admin, $sourceEnrollment] = $this->sourceEnrollment('M7-TRANSFER-COMMITTED');
        $october = app(EntitlementPeriodService::class)->generateMonthly(
            $sourceEnrollment,
            '2026-10-01',
            $admin,
        );
        $credit = $october->credits()->firstOrFail();
        $booking = $this->booking(
            $student,
            $sourceEnrollment,
            $this->occurrence('2026-10-05'),
        );
        app(CreditAllocationService::class)->allocate($booking, $credit, $admin);
        $booking->update(['status' => 'cancelled']);

        $destinationLevel = $this->level('m7-transfer-blocked', 'M7 Transfer Blocked');

        try {
            app(ChildEnrollmentService::class)->transfer(
                $sourceEnrollment,
                $destinationLevel,
                '2026-10-01',
                $admin,
                'Transfer after cancelled booking.',
            );
            $this->fail('Committed future entitlement must block transfer until credit reconciliation.');
        } catch (ValidationException) {
            $this->assertSame('active', $sourceEnrollment->fresh()->status);
            $this->assertSame('open', $october->fresh()->status);
            $this->assertSame('booked', $credit->fresh()->status);
            $this->assertSame(1, $october->fresh()->committedCreditCount());
        }
    }

    /**
     * @return array{Student, User, ChildEnrollment}
     */
    private function sourceEnrollment(string $code): array
    {
        $student = Student::query()->firstOrFail();
        $admin = User::query()->where('role', 'admin')->firstOrFail();
        $level = $this->level(strtolower($code), $code);
        $plan = SessionPlan::query()->create([
            'name' => $code,
            'code' => $code,
            'class_level_id' => $level->id,
            'entitlement_quantity' => 4,
            'entitlement_period' => 'monthly',
            'preferred_weekly_frequency' => 1,
            'makeup_policy' => [
                'sick' => true,
                'excused' => true,
                'no_show' => false,
                'school_cancel' => true,
            ],
            'rollover_policy' => ['mode' => 'off'],
            'is_default' => true,
            'is_active' => true,
        ]);
        $enrollment = app(ChildEnrollmentService::class)->enroll(
            $student,
            $level,
            '2026-09-01',
            $admin,
        );
        app(EnrollmentPlanService::class)->assignInitialPlan(
            $enrollment,
            $plan,
            $admin,
        );

        return [$student, $admin, $enrollment];
    }

    private function level(string $slug, string $name): ClassLevel
    {
        return ClassLevel::query()->create([
            'name' => $name,
            'slug' => $slug,
            'sequence' => 96,
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
            'capacity' => 12,
            'room' => 'M7 Reconcile '.$date,
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
            'source_type' => 'm7_reconcile_test',
        ]);
    }
}
