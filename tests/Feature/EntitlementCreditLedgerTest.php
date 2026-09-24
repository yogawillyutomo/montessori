<?php

namespace Tests\Feature;

use App\Models\ChildEnrollment;
use App\Models\ChildSessionBooking;
use App\Models\ClassLevel;
use App\Models\ClassSession;
use App\Models\EntitlementAdjustment;
use App\Models\EntitlementPeriod;
use App\Models\SessionCredit;
use App\Models\SessionOccurrence;
use App\Models\SessionPlan;
use App\Models\Student;
use App\Models\User;
use App\Services\Enrollment\ChildEnrollmentService;
use App\Services\Enrollment\EnrollmentPlanService;
use App\Services\Entitlement\CreditAllocationService;
use App\Services\Entitlement\EntitlementAdjustmentService;
use App\Services\Entitlement\EntitlementPeriodService;
use App\Services\Scheduling\BookingRescheduleService;
use App\Services\Scheduling\LegacySessionCompatibilityWriter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use LogicException;
use Tests\TestCase;

class EntitlementCreditLedgerTest extends TestCase
{
    use RefreshDatabase;

    public function test_full_month_entitlement_uses_plan_quantity_not_weekly_frequency_times_four(): void
    {
        $this->seed();

        [$student, $admin, $enrollment, $plan] = $this->enrollmentWithPlan(
            startsOn: '2026-09-01',
            quantity: 8,
            weeklyFrequency: 2,
            code: 'M7-FULL-8',
        );

        $period = app(EntitlementPeriodService::class)->generateMonthly(
            $enrollment,
            '2026-09-13',
            $admin,
        );

        $this->assertSame($plan->id, $period->session_plan_id);
        $this->assertSame('2026-09-01', $period->period_start->toDateString());
        $this->assertSame('2026-09-30', $period->period_end->toDateString());
        $this->assertSame(8, $period->base_quantity);
        $this->assertSame('plan', $period->quantity_source);
        $this->assertSame(8, $period->effectiveQuantity());
        $this->assertSame(8, $period->activeCreditCount());
        $this->assertSame(8, SessionCredit::query()->where('entitlement_period_id', $period->id)->count());
        $this->assertSame(1, SessionCredit::query()->where('entitlement_period_id', $period->id)->min('sequence_no'));
        $this->assertSame(8, SessionCredit::query()->where('entitlement_period_id', $period->id)->max('sequence_no'));
        $this->assertSame(
            ['2026-09-01'],
            SessionCredit::query()
                ->where('entitlement_period_id', $period->id)
                ->get()
                ->map(fn (SessionCredit $credit): string => $credit->origin_period_start->toDateString())
                ->unique()
                ->values()
                ->all(),
        );
    }

    public function test_mid_month_first_period_requires_explicit_confirmation_and_next_full_month_returns_to_plan(): void
    {
        $this->seed();

        [, $admin, $enrollment] = $this->enrollmentWithPlan(
            startsOn: '2026-09-20',
            quantity: 8,
            weeklyFrequency: 2,
            code: 'M7-PARTIAL-8',
        );

        try {
            app(EntitlementPeriodService::class)->generateMonthly(
                $enrollment,
                '2026-09-20',
                $admin,
            );
            $this->fail('Partial first period must not auto-prorate.');
        } catch (ValidationException) {
            $this->assertSame(0, EntitlementPeriod::query()->count());
        }

        $september = app(EntitlementPeriodService::class)->generateMonthly(
            $enrollment,
            '2026-09-20',
            $admin,
            3,
            'Joined on 20 September; school confirms three sessions.',
        );

        $this->assertSame(3, $september->base_quantity);
        $this->assertSame('confirmed_partial_period', $september->quantity_source);
        $this->assertSame($admin->id, $september->confirmed_by);
        $this->assertSame(3, $september->activeCreditCount());

        $october = app(EntitlementPeriodService::class)->generateMonthly(
            $enrollment,
            '2026-10-10',
            $admin,
        );

        $this->assertSame(8, $october->base_quantity);
        $this->assertSame('plan', $october->quantity_source);
        $this->assertSame(8, $october->activeCreditCount());
    }

    public function test_adjustment_ledger_reconciles_effective_quantity_and_active_credits(): void
    {
        $this->seed();

        [, $admin, $enrollment] = $this->enrollmentWithPlan(
            startsOn: '2026-09-01',
            quantity: 4,
            weeklyFrequency: 1,
            code: 'M7-ADJUST-4',
        );
        $period = app(EntitlementPeriodService::class)->generateMonthly($enrollment, '2026-09-01', $admin);

        $plus = app(EntitlementAdjustmentService::class)->adjust(
            $period,
            2,
            'mid_month_upgrade',
            $admin,
            'Temporary current-period increase.',
        );

        $period->refresh();
        $this->assertSame(6, $period->effectiveQuantity());
        $this->assertSame(6, $period->activeCreditCount());
        $this->assertSame(2, $plus->createdCredits()->count());
        $this->assertSame([5, 6], $plus->createdCredits()->pluck('sequence_no')->sort()->values()->all());

        $minus = app(EntitlementAdjustmentService::class)->adjust(
            $period,
            -1,
            'administrative_correction',
            $admin,
            'One unused session removed.',
        );

        $period->refresh();
        $this->assertSame(5, $period->effectiveQuantity());
        $this->assertSame(5, $period->activeCreditCount());
        $this->assertSame(1, $minus->voidedCredits()->count());
        $this->assertSame('available', $minus->voidedCredits()->firstOrFail()->status);
    }

    public function test_negative_adjustment_cannot_remove_committed_booked_credits(): void
    {
        $this->seed();

        [$student, $admin, $enrollment] = $this->enrollmentWithPlan(
            startsOn: '2026-09-01',
            quantity: 4,
            weeklyFrequency: 1,
            code: 'M7-COMMITTED-4',
        );
        $period = app(EntitlementPeriodService::class)->generateMonthly($enrollment, '2026-09-01', $admin);
        $credits = $period->credits()->get();

        foreach ([5, 6, 7] as $index => $day) {
            $booking = $this->booking($student, $enrollment, $this->occurrence("2026-09-{$day}"));
            app(CreditAllocationService::class)->allocate($booking, $credits[$index], $admin);
        }

        $this->assertSame(3, $period->fresh()->committedCreditCount());
        $this->assertSame(1, $period->fresh()->availableCreditCount());

        try {
            app(EntitlementAdjustmentService::class)->adjust(
                $period,
                -2,
                'downgrade_correction',
                $admin,
            );
            $this->fail('Negative adjustment must not consume booked credits.');
        } catch (ValidationException) {
            $this->assertSame(0, EntitlementAdjustment::query()->count());
            $this->assertSame(4, $period->fresh()->effectiveQuantity());
            $this->assertSame(4, $period->fresh()->activeCreditCount());
        }
    }

    public function test_adjustment_reversal_reconciles_the_same_credits_and_cannot_reverse_used_allocation(): void
    {
        $this->seed();

        [$student, $admin, $enrollment] = $this->enrollmentWithPlan(
            startsOn: '2026-09-01',
            quantity: 4,
            weeklyFrequency: 1,
            code: 'M7-REVERSAL-4',
        );
        $period = app(EntitlementPeriodService::class)->generateMonthly($enrollment, '2026-09-01', $admin);

        $plus = app(EntitlementAdjustmentService::class)->adjust(
            $period,
            2,
            'courtesy',
            $admin,
        );
        $plusCreditIds = $plus->createdCredits()->orderBy('sequence_no')->pluck('id')->all();

        $reversal = app(EntitlementAdjustmentService::class)->reverse($plus, $admin, 'Courtesy entry was incorrect.');

        $this->assertSame(-2, $reversal->quantity_delta);
        $this->assertSame($plus->id, $reversal->reversal_of_adjustment_id);
        $this->assertSame(4, $period->fresh()->effectiveQuantity());
        $this->assertSame(4, $period->fresh()->activeCreditCount());
        $this->assertEqualsCanonicalizing(
            $plusCreditIds,
            SessionCredit::query()->where('voided_by_adjustment_id', $reversal->id)->pluck('id')->all(),
        );

        $minus = app(EntitlementAdjustmentService::class)->adjust(
            $period,
            -1,
            'administrative_correction',
            $admin,
        );
        $voidedCreditId = $minus->voidedCredits()->firstOrFail()->id;

        app(EntitlementAdjustmentService::class)->reverse($minus, $admin);

        $this->assertNull(SessionCredit::query()->findOrFail($voidedCreditId)->voided_at);
        $this->assertSame(4, $period->fresh()->effectiveQuantity());
        $this->assertSame(4, $period->fresh()->activeCreditCount());

        $extra = app(EntitlementAdjustmentService::class)->adjust($period, 1, 'bonus', $admin);
        $extraCredit = $extra->createdCredits()->firstOrFail();
        $booking = $this->booking($student, $enrollment, $this->occurrence('2026-09-20'));
        app(CreditAllocationService::class)->allocate($booking, $extraCredit, $admin);

        $this->expectException(ValidationException::class);
        app(EntitlementAdjustmentService::class)->reverse($extra, $admin);
    }

    public function test_credit_allocation_requires_same_enrollment_and_only_one_active_booking(): void
    {
        $this->seed();

        [$student, $admin, $enrollment] = $this->enrollmentWithPlan(
            startsOn: '2026-09-01',
            quantity: 4,
            weeklyFrequency: 1,
            code: 'M7-ALLOC-4',
        );
        $period = app(EntitlementPeriodService::class)->generateMonthly($enrollment, '2026-09-01', $admin);
        $credit = $period->credits()->firstOrFail();
        $booking = $this->booking($student, $enrollment, $this->occurrence('2026-09-10'));

        $allocated = app(CreditAllocationService::class)->allocate($booking, $credit, $admin);

        $this->assertSame($credit->id, $allocated->session_credit_id);
        $this->assertSame($admin->id, $allocated->credit_allocated_by);
        $this->assertNotNull($allocated->credit_allocated_at);
        $this->assertSame('booked', $credit->fresh()->status);

        $otherBooking = $this->booking($student, $enrollment, $this->occurrence('2026-09-11'));

        $this->expectException(ValidationException::class);
        app(CreditAllocationService::class)->allocate($otherBooking, $credit, $admin);
    }

    public function test_initial_allocation_cannot_consume_origin_credit_outside_its_period(): void
    {
        $this->seed();

        [$student, $admin, $enrollment] = $this->enrollmentWithPlan(
            startsOn: '2026-09-01',
            quantity: 4,
            weeklyFrequency: 1,
            code: 'M7-CROSS-PERIOD-4',
        );
        $period = app(EntitlementPeriodService::class)->generateMonthly($enrollment, '2026-09-01', $admin);
        $credit = $period->credits()->firstOrFail();
        $octoberBooking = $this->booking($student, $enrollment, $this->occurrence('2026-10-02'));

        $this->expectException(ValidationException::class);
        app(CreditAllocationService::class)->allocate($octoberBooking, $credit, $admin);
    }

    public function test_reschedule_keeps_same_origin_credit_even_when_destination_is_next_month(): void
    {
        $this->seed();

        [$student, $admin, $enrollment] = $this->enrollmentWithPlan(
            startsOn: '2026-09-01',
            quantity: 4,
            weeklyFrequency: 1,
            code: 'M7-MOVE-4',
        );
        $period = app(EntitlementPeriodService::class)->generateMonthly($enrollment, '2026-09-01', $admin);
        $credit = $period->credits()->firstOrFail();
        $source = $this->booking($student, $enrollment, $this->occurrence('2026-09-28'));
        app(CreditAllocationService::class)->allocate($source, $credit, $admin);
        $destinationOccurrence = $this->compatibilityOccurrence('2026-10-02');

        $movement = app(BookingRescheduleService::class)->reschedule(
            $source,
            $destinationOccurrence,
            $admin,
            'Family requested another day.',
        );

        $source->refresh();
        $destination = $movement->destinationBooking()->firstOrFail();
        $credit->refresh();

        $this->assertSame('rescheduled_out', $source->status);
        $this->assertSame($credit->id, $source->session_credit_id);
        $this->assertSame($credit->id, $destination->session_credit_id);
        $this->assertSame('2026-09-01', $credit->origin_period_start->toDateString());
        $this->assertSame('booked', $credit->status);
        $this->assertSame(1, ChildSessionBooking::query()->active()->where('session_credit_id', $credit->id)->count());
    }

    public function test_period_and_credit_origin_are_immutable_history(): void
    {
        $this->seed();

        [, $admin, $enrollment] = $this->enrollmentWithPlan(
            startsOn: '2026-09-01',
            quantity: 4,
            weeklyFrequency: 1,
            code: 'M7-IMMUTABLE-4',
        );
        $period = app(EntitlementPeriodService::class)->generateMonthly($enrollment, '2026-09-01', $admin);
        $credit = $period->credits()->firstOrFail();

        try {
            $period->update(['base_quantity' => 5]);
            $this->fail('Entitlement period base quantity must be immutable.');
        } catch (LogicException) {
            $this->assertSame(4, $period->fresh()->base_quantity);
        }

        $this->expectException(LogicException::class);
        $credit->update(['origin_period_start' => '2026-10-01']);
    }

    /**
     * @return array{Student, User, ChildEnrollment, SessionPlan}
     */
    private function enrollmentWithPlan(
        string $startsOn,
        int $quantity,
        int $weeklyFrequency,
        string $code,
    ): array {
        $student = Student::query()->firstOrFail();
        $admin = User::query()->where('role', 'admin')->firstOrFail();
        $level = ClassLevel::query()->create([
            'name' => $code,
            'slug' => strtolower($code),
            'sequence' => 95,
            'is_active' => true,
        ]);
        $plan = SessionPlan::query()->create([
            'name' => $code,
            'code' => $code,
            'class_level_id' => $level->id,
            'entitlement_quantity' => $quantity,
            'entitlement_period' => 'monthly',
            'preferred_weekly_frequency' => $weeklyFrequency,
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
            $startsOn,
            $admin,
        );
        app(EnrollmentPlanService::class)->assignInitialPlan(
            $enrollment,
            $plan,
            $admin,
        );

        return [$student, $admin, $enrollment, $plan];
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
            'room' => 'M7 Room '.$date,
            'status' => 'planned',
        ]);
    }

    private function compatibilityOccurrence(string $date): SessionOccurrence
    {
        $base = ClassSession::query()->firstOrFail();
        $occurrence = SessionOccurrence::query()->create([
            'session_template_id' => null,
            'environment_id' => null,
            'occurs_on' => $date,
            'starts_at' => '10:00',
            'ends_at' => '11:00',
            'capacity' => 12,
            'room' => 'M7 Room '.$date,
            'status' => 'planned',
            'legacy_school_class_id' => $base->school_class_id,
            'legacy_teacher_id' => $base->teacher_id,
        ]);

        app(LegacySessionCompatibilityWriter::class)->syncOccurrence($occurrence);

        return $occurrence->fresh();
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
            'source_type' => 'm7_test',
        ]);
    }
}
