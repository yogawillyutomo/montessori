<?php

namespace Tests\Feature;

use App\Models\ChildEnrollment;
use App\Models\ChildSessionBooking;
use App\Models\ClassSession;
use App\Models\ClassLevel;
use App\Models\MakeupEligibility;
use App\Models\SessionCredit;
use App\Models\SessionOccurrence;
use App\Models\SessionPlan;
use App\Models\Student;
use App\Models\User;
use App\Services\Enrollment\ChildEnrollmentService;
use App\Services\Enrollment\EnrollmentPlanService;
use App\Services\Entitlement\AttendanceOutcomeService;
use App\Services\Entitlement\CreditAllocationService;
use App\Services\Entitlement\EntitlementPeriodService;
use App\Services\Scheduling\BookingRescheduleService;
use App\Services\Scheduling\ChildBookingCancellationService;
use App\Services\Scheduling\LegacySessionCompatibilityWriter;
use App\Services\Scheduling\MakeupBookingService;
use App\Services\Scheduling\SessionOccurrenceCancellationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class AttendanceCreditMakeupTest extends TestCase
{
    use RefreshDatabase;

    public function test_unmarked_keeps_credit_booked_and_creates_native_booking_attendance(): void
    {
        [$student, $admin, $enrollment, $credit, $booking] = $this->creditedBooking('M8-UNMARKED', '2026-09-07');

        $attendance = app(AttendanceOutcomeService::class)->recordForBooking(
            $booking,
            'unmarked',
            null,
            $admin,
        );

        $this->assertSame('booked', $credit->fresh()->status);
        $this->assertSame($booking->id, $attendance->child_session_booking_id);
        $this->assertNull($attendance->class_session_id);
        $this->assertSame($student->id, $attendance->student_id);
        $this->assertNull($attendance->marked_at);
        $this->assertSame(0, MakeupEligibility::query()->count());
    }

    public function test_present_and_late_settle_credit_as_used(): void
    {
        [, $admin, , $presentCredit, $presentBooking] = $this->creditedBooking('M8-PRESENT', '2026-09-07');

        app(AttendanceOutcomeService::class)->recordForBooking(
            $presentBooking,
            'present',
            null,
            $admin,
        );

        $this->assertSame('used', $presentCredit->fresh()->status);

        [, $admin, , $lateCredit, $lateBooking] = $this->creditedBooking('M8-LATE', '2026-09-08');

        app(AttendanceOutcomeService::class)->recordForBooking(
            $lateBooking,
            'late',
            'Arrived after session start.',
            $admin,
        );

        $this->assertSame('used', $lateCredit->fresh()->status);
    }

    public function test_sick_and_excused_follow_plan_policy_and_keep_credit_booked(): void
    {
        [, $admin, , $sickCredit, $sickBooking] = $this->creditedBooking('M8-SICK', '2026-09-07');

        $sickAttendance = app(AttendanceOutcomeService::class)->recordForBooking(
            $sickBooking,
            'sick',
            'Family reported illness.',
            $admin,
        );
        $sickEligibility = MakeupEligibility::query()->where('session_credit_id', $sickCredit->id)->firstOrFail();

        $this->assertSame('booked', $sickCredit->fresh()->status);
        $this->assertSame('pending', $sickEligibility->status);
        $this->assertSame('sick', $sickEligibility->reason_category);
        $this->assertSame($sickAttendance->id, $sickEligibility->source_attendance_id);

        [, $admin, , $excusedCredit, $excusedBooking] = $this->creditedBooking('M8-EXCUSED', '2026-09-08');

        app(AttendanceOutcomeService::class)->recordForBooking(
            $excusedBooking,
            'excused',
            'Approved family event.',
            $admin,
        );
        $excusedEligibility = MakeupEligibility::query()->where('session_credit_id', $excusedCredit->id)->firstOrFail();

        $this->assertSame('booked', $excusedCredit->fresh()->status);
        $this->assertSame('pending', $excusedEligibility->status);
        $this->assertSame('excused', $excusedEligibility->reason_category);
    }

    public function test_absent_no_show_forfeits_credit_when_plan_denies_makeup(): void
    {
        [, $admin, , $credit, $booking] = $this->creditedBooking('M8-NOSHOW', '2026-09-07');

        app(AttendanceOutcomeService::class)->recordForBooking(
            $booking,
            'absent',
            'No notice received.',
            $admin,
        );

        $this->assertSame('forfeited', $credit->fresh()->status);
        $this->assertFalse(MakeupEligibility::query()->where('session_credit_id', $credit->id)->exists());
    }

    public function test_school_cancellation_preserves_credit_and_grants_pending_makeup(): void
    {
        [, $admin, , $credit, $booking] = $this->creditedBooking('M8-SCHOOL-CANCEL', '2026-09-07');
        $occurrence = $booking->sessionOccurrence()->firstOrFail();

        app(SessionOccurrenceCancellationService::class)->cancel(
            $occurrence,
            $admin,
            'School closed due to facility issue.',
        );

        $eligibility = MakeupEligibility::query()->where('session_credit_id', $credit->id)->firstOrFail();

        $this->assertSame('cancelled', $occurrence->fresh()->status);
        $this->assertSame('session_cancelled', $booking->fresh()->status);
        $this->assertSame('booked', $credit->fresh()->status);
        $this->assertSame('pending', $eligibility->status);
        $this->assertSame('school_cancel', $eligibility->reason_category);
        $this->assertSame($booking->id, $eligibility->source_booking_id);
    }

    public function test_makeup_moves_same_credit_across_month_and_present_fulfills_it(): void
    {
        [, $admin, , $credit, $booking] = $this->creditedBooking('M8-MAKEUP', '2026-09-07');

        app(AttendanceOutcomeService::class)->recordForBooking(
            $booking,
            'sick',
            'Child was sick.',
            $admin,
        );
        $eligibility = MakeupEligibility::query()->where('session_credit_id', $credit->id)->firstOrFail();
        $destinationOccurrence = $this->compatibilityOccurrence('2026-10-05');

        $movement = app(MakeupBookingService::class)->schedule(
            $eligibility,
            $destinationOccurrence,
            $admin,
            'Approved replacement session.',
        );
        $destination = $movement->destinationBooking()->firstOrFail();

        $this->assertSame('makeup', $movement->movement_type);
        $this->assertSame('rescheduled_out', $booking->fresh()->status);
        $this->assertSame('makeup', $destination->booking_type);
        $this->assertSame($credit->id, $destination->session_credit_id);
        $this->assertSame('2026-09-01', $credit->fresh()->origin_period_start->toDateString());
        $this->assertSame('booked', $credit->fresh()->status);
        $this->assertSame('pending', $eligibility->fresh()->status);

        $destinationAttendance = app(AttendanceOutcomeService::class)->recordForBooking(
            $destination,
            'present',
            null,
            $admin,
        );

        $this->assertSame($destination->id, $destinationAttendance->child_session_booking_id);
        $this->assertNull($destinationAttendance->class_session_id);
        $this->assertSame('used', $credit->fresh()->status);
        $this->assertSame('fulfilled', $eligibility->fresh()->status);
        $this->assertSame($destination->id, $eligibility->fresh()->fulfilled_by_booking_id);
    }

    public function test_source_sick_corrected_to_present_revokes_unused_makeup_right(): void
    {
        [, $admin, , $credit, $booking] = $this->creditedBooking('M8-CORRECT', '2026-09-07');

        app(AttendanceOutcomeService::class)->recordForBooking(
            $booking,
            'sick',
            'Initial mistaken status.',
            $admin,
        );
        $eligibility = MakeupEligibility::query()->where('session_credit_id', $credit->id)->firstOrFail();

        app(AttendanceOutcomeService::class)->recordForBooking(
            $booking,
            'present',
            'Corrected after verification.',
            $admin,
        );

        $eligibility->refresh();
        $this->assertSame('used', $credit->fresh()->status);
        $this->assertSame('revoked', $eligibility->status);
        $this->assertNotNull($eligibility->revoked_at);
        $this->assertSame($admin->id, $eligibility->revoked_by);
    }

    public function test_source_attendance_cannot_be_rewritten_after_makeup_movement_exists(): void
    {
        [, $admin, , $credit, $booking] = $this->creditedBooking('M8-CORRECTION-GUARD', '2026-09-07');

        app(AttendanceOutcomeService::class)->recordForBooking(
            $booking,
            'sick',
            null,
            $admin,
        );
        $eligibility = MakeupEligibility::query()->where('session_credit_id', $credit->id)->firstOrFail();
        $movement = app(MakeupBookingService::class)->schedule(
            $eligibility,
            $this->compatibilityOccurrence('2026-09-14'),
            $admin,
            'Makeup scheduled.',
        );

        try {
            app(AttendanceOutcomeService::class)->recordForBooking(
                $booking,
                'present',
                'Attempt correction after downstream decision.',
                $admin,
            );
            $this->fail('Source attendance correction must be blocked after makeup movement exists.');
        } catch (ValidationException) {
            $this->assertSame('booked', $credit->fresh()->status);
            $this->assertSame('pending', $eligibility->fresh()->status);
            $this->assertSame('scheduled', $movement->destinationBooking()->firstOrFail()->status);
        }
    }

    public function test_recorded_missed_outcome_cannot_be_disguised_as_normal_reschedule(): void
    {
        [, $admin, , $credit, $booking] = $this->creditedBooking('M8-RESCHEDULE-GUARD', '2026-09-07');

        app(AttendanceOutcomeService::class)->recordForBooking(
            $booking,
            'sick',
            'Missed session has already happened.',
            $admin,
        );

        try {
            app(BookingRescheduleService::class)->reschedule(
                $booking,
                $this->occurrence('2026-09-14'),
                $admin,
                'Attempt to bypass makeup.',
            );
            $this->fail('Recorded missed attendance must require makeup workflow.');
        } catch (ValidationException) {
            $this->assertSame('scheduled', $booking->fresh()->status);
            $this->assertSame('booked', $credit->fresh()->status);
            $this->assertSame('pending', MakeupEligibility::query()->where('session_credit_id', $credit->id)->firstOrFail()->status);
        }
    }

    public function test_credited_child_cancellation_is_blocked_until_policy_reconciliation(): void
    {
        [, $admin, , $credit, $booking] = $this->creditedBooking('M8-CANCEL-GUARD', '2026-09-07');

        try {
            app(ChildBookingCancellationService::class)->cancel(
                $booking,
                $admin,
                'Family cancels in advance.',
            );
            $this->fail('Credited booking cancellation must not strand a BOOKED credit.');
        } catch (ValidationException) {
            $this->assertSame('scheduled', $booking->fresh()->status);
            $this->assertSame('booked', $credit->fresh()->status);
        }
    }

    /**
     * @return array{Student, User, ChildEnrollment, SessionCredit, ChildSessionBooking}
     */
    private function creditedBooking(string $code, string $date): array
    {
        if (! User::query()->exists()) {
            $this->seed();
        }

        $student = Student::query()->whereDoesntHave('childEnrollments')->first();

        if (! $student) {
            $template = Student::query()->firstOrFail();
            $student = Student::query()->create([
                'school_class_id' => $template->school_class_id,
                'guardian_id' => $template->guardian_id,
                'code' => 'M8-STUDENT-'.$code,
                'name' => 'M8 '.$code,
                'status' => 'active',
            ]);
        }

        $admin = User::query()->where('role', 'admin')->firstOrFail();
        $level = ClassLevel::query()->create([
            'name' => $code,
            'slug' => strtolower($code),
            'sequence' => 99,
            'is_active' => true,
        ]);
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
        $period = app(EntitlementPeriodService::class)->generateMonthly(
            $enrollment,
            '2026-09-01',
            $admin,
        );
        $credit = $period->credits()->where('status', 'available')->firstOrFail();
        $booking = ChildSessionBooking::query()->create([
            'student_id' => $student->id,
            'child_enrollment_id' => $enrollment->id,
            'session_occurrence_id' => $this->occurrence($date)->id,
            'booking_type' => 'regular',
            'status' => 'scheduled',
            'source_type' => 'm8_test',
            'created_by' => $admin->id,
        ]);
        $booking = app(CreditAllocationService::class)->allocate($booking, $credit, $admin);

        return [$student, $admin, $enrollment, $credit, $booking];
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
            'room' => 'M8 '.$date,
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
            'capacity' => 8,
            'room' => 'M8 '.$date,
            'status' => 'planned',
            'legacy_school_class_id' => $base->school_class_id,
            'legacy_teacher_id' => $base->teacher_id,
        ]);

        app(LegacySessionCompatibilityWriter::class)->syncOccurrence($occurrence);

        return $occurrence->fresh();
    }
}
