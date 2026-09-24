<?php

namespace Tests\Feature;

use App\Models\BookingMovement;
use App\Models\ChildSessionBooking;
use App\Models\ClassLevel;
use App\Models\DevelopmentArea;
use App\Models\MakeupEligibility;
use App\Models\MontessoriActivity;
use App\Models\Presentation;
use App\Models\SessionOccurrence;
use App\Models\SessionPlan;
use App\Models\Student;
use App\Models\Teacher;
use App\Models\User;
use App\Services\Enrollment\ChildEnrollmentService;
use App\Services\Enrollment\EnrollmentPlanService;
use App\Services\Entitlement\AttendanceOutcomeService;
use App\Services\Entitlement\CreditAllocationService;
use App\Services\Entitlement\EntitlementPeriodService;
use App\Services\Scheduling\MakeupBookingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class MakeupPresentationEvidenceGuardTest extends TestCase
{
    use RefreshDatabase;

    public function test_makeup_refuses_source_booking_with_presentation_evidence(): void
    {
        $this->seed();

        $admin = User::query()->where('role', 'admin')->firstOrFail();
        $teacher = Teacher::query()->firstOrFail();
        $templateStudent = Student::query()->firstOrFail();
        $student = Student::query()->create([
            'school_class_id' => $templateStudent->school_class_id,
            'guardian_id' => $templateStudent->guardian_id,
            'code' => 'M17-MAKEUP-PRESENTATION',
            'name' => 'M17 Makeup Presentation Guard',
            'status' => 'active',
        ]);

        $level = ClassLevel::query()->create([
            'name' => 'M17 Makeup Presentation',
            'slug' => 'm17-makeup-presentation',
            'sequence' => 199,
            'is_active' => true,
        ]);
        $plan = SessionPlan::query()->create([
            'name' => 'M17 Makeup Presentation Plan',
            'code' => 'M17-MAKEUP-PRESENTATION',
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

        $sourceOccurrence = $this->occurrence('2026-09-07', 'M17 Source');
        $booking = ChildSessionBooking::query()->create([
            'student_id' => $student->id,
            'child_enrollment_id' => $enrollment->id,
            'session_occurrence_id' => $sourceOccurrence->id,
            'booking_type' => 'regular',
            'status' => 'scheduled',
            'source_type' => 'm17_test',
            'created_by' => $admin->id,
        ]);
        $booking = app(CreditAllocationService::class)->allocate($booking, $credit, $admin);

        app(AttendanceOutcomeService::class)->recordForBooking(
            $booking,
            'sick',
            'Marked sick before contradictory presentation evidence is discovered.',
            $admin,
        );
        $eligibility = MakeupEligibility::query()
            ->where('session_credit_id', $credit->id)
            ->firstOrFail();

        $area = DevelopmentArea::query()->firstOrFail();
        $activity = MontessoriActivity::query()->create([
            'development_area_id' => $area->id,
            'code' => 'M17-PRESENTATION-GUARD',
            'name' => 'M17 Presentation Guard Activity',
            'is_active' => true,
        ]);
        Presentation::query()->create([
            'student_id' => $student->id,
            'teacher_id' => $teacher->id,
            'montessori_activity_id' => $activity->id,
            'session_occurrence_id' => $sourceOccurrence->id,
            'presented_on' => '2026-09-07',
            'presentation_type' => 'initial',
            'note' => 'Historical presentation evidence must block destructive movement.',
            'recorded_by' => $admin->id,
        ]);

        try {
            app(MakeupBookingService::class)->schedule(
                $eligibility,
                $this->occurrence('2026-09-14', 'M17 Destination'),
                $admin,
                'Attempt makeup after presentation evidence.',
            );
            $this->fail('Makeup must fail closed after presentation evidence exists.');
        } catch (ValidationException) {
            $this->assertSame('scheduled', $booking->fresh()->status);
            $this->assertSame('booked', $credit->fresh()->status);
            $this->assertSame('pending', $eligibility->fresh()->status);
            $this->assertSame(0, BookingMovement::query()
                ->where('source_booking_id', $booking->id)
                ->count());
        }
    }

    private function occurrence(string $date, string $room): SessionOccurrence
    {
        return SessionOccurrence::query()->create([
            'session_template_id' => null,
            'environment_id' => null,
            'occurs_on' => $date,
            'starts_at' => '10:00',
            'ends_at' => '11:00',
            'capacity' => 8,
            'room' => $room,
            'status' => 'planned',
        ]);
    }
}
