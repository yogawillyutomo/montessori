<?php

namespace Tests\Feature;

use App\Models\Attendance;
use App\Models\ChildEnrollment;
use App\Models\ChildSessionBooking;
use App\Models\ClassLevel;
use App\Models\Report;
use App\Models\ReportCycle;
use App\Models\ReportPolicy;
use App\Models\SessionOccurrence;
use App\Models\Student;
use App\Models\User;
use App\Services\Reporting\ReportEligibilityService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class ReportEligibilityTest extends TestCase
{
    use RefreshDatabase;

    public function test_new_child_before_maturity_is_not_eligible_and_no_report_is_created(): void
    {
        [$student, $admin, $enrollment, $policy] = $this->context('M13-NEW', '2026-09-20', 60, 8, true);
        $cycle = $this->cycle($policy, 'September', '2026-09-01', '2026-09-30');

        $eligibility = app(ReportEligibilityService::class)->evaluate($enrollment, $cycle);

        $this->assertSame('not_eligible', $eligibility->status);
        $this->assertContains('minimum_duration_not_met', $eligibility->reason_codes);
        $this->assertContains('minimum_attendance_not_met', $eligibility->reason_codes);
        $this->assertContains('guide_confirmation_pending', $eligibility->reason_codes);
        $this->assertSame(10, $eligibility->observation_days);
        $this->assertSame(0, Report::query()->where('student_id', $student->id)->count());
    }

    public function test_only_actual_present_attendance_counts_for_first_report(): void
    {
        [, $admin, $enrollment, $policy] = $this->context('M13-ATTENDANCE', '2026-07-01', 30, 2, false);
        $cycle = $this->cycle($policy, 'September', '2026-09-01', '2026-09-30');

        $this->attendance($enrollment, '2026-09-02', 'present', $admin);
        $this->attendance($enrollment, '2026-09-04', 'late', $admin);
        $this->attendance($enrollment, '2026-09-06', 'sick', $admin);
        $this->attendance($enrollment, '2026-09-08', 'excused', $admin);
        $this->attendance($enrollment, '2026-09-10', 'absent', $admin);
        $this->attendance($enrollment, '2026-09-12', 'unmarked', $admin);

        $eligibility = app(ReportEligibilityService::class)->evaluate($enrollment, $cycle);

        $this->assertSame(1, $eligibility->attended_sessions);
        $this->assertSame('not_eligible', $eligibility->status);
        $this->assertSame(['minimum_attendance_not_met'], $eligibility->reason_codes);

        $this->attendance($enrollment, '2026-09-14', 'present', $admin);
        $eligibility = app(ReportEligibilityService::class)->evaluate($enrollment, $cycle);

        $this->assertSame(2, $eligibility->attended_sessions);
        $this->assertSame('eligible', $eligibility->status);
        $this->assertSame([], $eligibility->reason_codes);
    }

    public function test_guide_confirmation_is_final_checkpoint_after_objective_criteria(): void
    {
        [, $admin, $enrollment, $policy] = $this->context('M13-GUIDE', '2026-07-01', 30, 2, true);
        $cycle = $this->cycle($policy, 'September', '2026-09-01', '2026-09-30');
        $this->attendance($enrollment, '2026-09-02', 'present', $admin);
        $this->attendance($enrollment, '2026-09-09', 'present', $admin);

        $eligibility = app(ReportEligibilityService::class)->evaluate($enrollment, $cycle);

        $this->assertSame('not_eligible', $eligibility->status);
        $this->assertSame(['guide_confirmation_pending'], $eligibility->reason_codes);

        $eligibility = app(ReportEligibilityService::class)->confirmGuideReadiness(
            $eligibility,
            $admin,
            'Evidence reviewed and sufficient.',
        );

        $this->assertSame('eligible', $eligibility->status);
        $this->assertSame([], $eligibility->reason_codes);
        $this->assertSame($admin->id, $eligibility->guide_confirmed_by);
        $this->assertNotNull($eligibility->guide_confirmed_at);
    }

    public function test_guide_cannot_confirm_while_objective_criteria_are_still_blocking(): void
    {
        [, $admin, $enrollment, $policy] = $this->context('M13-GUIDE-BLOCK', '2026-09-20', 60, 8, true);
        $cycle = $this->cycle($policy, 'September', '2026-09-01', '2026-09-30');
        $eligibility = app(ReportEligibilityService::class)->evaluate($enrollment, $cycle);

        $this->expectException(ValidationException::class);

        app(ReportEligibilityService::class)->confirmGuideReadiness(
            $eligibility,
            $admin,
            'Too early.',
        );
    }

    public function test_admin_override_is_explicit_audited_and_does_not_erase_blocking_reasons(): void
    {
        [, $admin, $enrollment, $policy] = $this->context('M13-OVERRIDE', '2026-09-20', 60, 8, true);
        $cycle = $this->cycle($policy, 'September', '2026-09-01', '2026-09-30');
        $eligibility = app(ReportEligibilityService::class)->evaluate($enrollment, $cycle);

        $eligibility = app(ReportEligibilityService::class)->overrideEligibility(
            $eligibility,
            $admin,
            'Returning child with verified prior-school documentation.',
        );

        $this->assertSame('eligible', $eligibility->status);
        $this->assertContains('minimum_duration_not_met', $eligibility->reason_codes);
        $this->assertContains('minimum_attendance_not_met', $eligibility->reason_codes);
        $this->assertSame($admin->id, $eligibility->override_by);
        $this->assertNotNull($eligibility->override_at);
        $this->assertSame('Returning child with verified prior-school documentation.', $eligibility->override_reason);
    }

    public function test_teacher_cannot_use_admin_eligibility_override_route(): void
    {
        [$student, , $enrollment, $policy] = $this->context('M13-OVERRIDE-ROUTE', '2026-09-20', 60, 8, true);
        $cycle = $this->cycle($policy, 'September', '2026-09-01', '2026-09-30');
        $eligibility = app(ReportEligibilityService::class)->evaluate($enrollment, $cycle);
        $teacherUser = User::factory()->create([
            'role' => 'teacher',
            'is_active' => true,
        ]);

        $response = $this->actingAs($teacherUser)->post(route('alpha.report-eligibility.override', $eligibility), [
            'reason' => 'Unauthorized override attempt.',
        ]);

        $response->assertForbidden();
        $this->assertSame('not_eligible', $eligibility->fresh()->status);
        $this->assertNull($eligibility->fresh()->override_at);
        $this->assertSame(0, Report::query()->where('student_id', $student->id)->count());
    }

    public function test_cutoff_excludes_attendance_that_happens_after_cycle(): void
    {
        [, $admin, $enrollment, $policy] = $this->context('M13-CUTOFF', '2026-07-01', 30, 2, false);
        $cycle = $this->cycle($policy, 'September', '2026-09-01', '2026-09-30');
        $this->attendance($enrollment, '2026-09-15', 'present', $admin);
        $this->attendance($enrollment, '2026-10-01', 'present', $admin);

        $eligibility = app(ReportEligibilityService::class)->evaluate($enrollment, $cycle);

        $this->assertSame(1, $eligibility->attended_sessions);
        $this->assertSame('not_eligible', $eligibility->status);
        $this->assertContains('minimum_attendance_not_met', $eligibility->reason_codes);
    }

    public function test_initial_maturity_gate_does_not_restart_after_first_eligible_cycle(): void
    {
        [, $admin, $enrollment, $policy] = $this->context('M13-FIRST-GATE', '2026-07-01', 30, 2, false);
        $firstCycle = $this->cycle($policy, 'September', '2026-09-01', '2026-09-30');
        $this->attendance($enrollment, '2026-09-05', 'present', $admin);
        $this->attendance($enrollment, '2026-09-12', 'present', $admin);

        $first = app(ReportEligibilityService::class)->evaluate($enrollment, $firstCycle);
        $this->assertSame('eligible', $first->status);
        $this->assertTrue($first->is_first_report_gate);

        $secondCycle = $this->cycle($policy, 'October', '2026-10-01', '2026-10-31');
        $second = app(ReportEligibilityService::class)->evaluate($enrollment, $secondCycle);

        $this->assertFalse($second->is_first_report_gate);
        $this->assertSame('eligible', $second->status);
        $this->assertSame([], $second->reason_codes);
        $this->assertSame(0, $second->attended_sessions);
    }

    public function test_cycle_and_enrollment_level_must_match(): void
    {
        [, , $enrollment] = $this->context('M13-LEVEL-A', '2026-07-01', 0, 0, false);
        $otherLevel = ClassLevel::query()->create([
            'name' => 'M13 Level B',
            'slug' => 'm13-level-b',
            'sequence' => 500,
            'is_active' => true,
        ]);
        $otherPolicy = $this->policy($otherLevel, 'M13 Other Policy', 0, 0, false);
        $cycle = $this->cycle($otherPolicy, 'September', '2026-09-01', '2026-09-30');

        $this->expectException(ValidationException::class);

        app(ReportEligibilityService::class)->evaluate($enrollment, $cycle);
    }

    /**
     * @return array{Student, User, ChildEnrollment, ReportPolicy}
     */
    private function context(
        string $code,
        string $startsOn,
        int $minimumDays,
        int $minimumSessions,
        bool $requireGuideConfirmation,
    ): array {
        if (! User::query()->exists()) {
            $this->seed();
        }

        $admin = User::query()->where('role', 'admin')->where('is_active', true)->firstOrFail();
        $template = Student::query()->firstOrFail();
        $student = Student::query()->create([
            'school_class_id' => $template->school_class_id,
            'guardian_id' => $template->guardian_id,
            'code' => $code.'-STUDENT',
            'name' => $code.' Student',
            'status' => 'active',
        ]);

        $level = ClassLevel::query()->create([
            'name' => $code,
            'slug' => strtolower($code),
            'sequence' => 400,
            'is_active' => true,
        ]);
        $enrollment = ChildEnrollment::query()->create([
            'student_id' => $student->id,
            'class_level_id' => $level->id,
            'starts_on' => $startsOn,
            'status' => 'active',
            'first_session_on' => $startsOn,
            'created_by' => $admin->id,
        ]);
        $policy = $this->policy(
            $level,
            $code.' Policy',
            $minimumDays,
            $minimumSessions,
            $requireGuideConfirmation,
        );

        return [$student, $admin, $enrollment, $policy];
    }

    private function policy(
        ClassLevel $level,
        string $name,
        int $minimumDays,
        int $minimumSessions,
        bool $requireGuideConfirmation,
    ): ReportPolicy {
        return ReportPolicy::query()->create([
            'class_level_id' => $level->id,
            'name' => $name,
            'reporting_frequency' => 'monthly',
            'minimum_observation_days' => $minimumDays,
            'minimum_attended_sessions' => $minimumSessions,
            'require_guide_confirmation' => $requireGuideConfirmation,
            'area_coverage_mode' => 'advisory',
            'is_active' => true,
        ]);
    }

    private function cycle(
        ReportPolicy $policy,
        string $name,
        string $start,
        string $end,
    ): ReportCycle {
        return ReportCycle::query()->create([
            'report_policy_id' => $policy->id,
            'name' => $name,
            'window_start' => $start,
            'window_end' => $end,
            'cutoff_date' => $end,
            'status' => 'open',
        ]);
    }

    private function attendance(
        ChildEnrollment $enrollment,
        string $date,
        string $status,
        User $actor,
    ): Attendance {
        $occurrence = SessionOccurrence::query()->create([
            'session_template_id' => null,
            'environment_id' => null,
            'occurs_on' => $date,
            'starts_at' => '09:00',
            'ends_at' => '10:00',
            'capacity' => 10,
            'room' => 'M13 '.$date,
            'status' => 'planned',
        ]);
        $booking = ChildSessionBooking::query()->create([
            'student_id' => $enrollment->student_id,
            'child_enrollment_id' => $enrollment->id,
            'session_occurrence_id' => $occurrence->id,
            'booking_type' => 'regular',
            'status' => 'scheduled',
            'source_type' => 'm13_test',
            'created_by' => $actor->id,
        ]);
        $marked = $status !== 'unmarked';

        return Attendance::query()->create([
            'class_session_id' => null,
            'student_id' => $enrollment->student_id,
            'child_session_booking_id' => $booking->id,
            'status' => $status,
            'note' => null,
            'marked_by' => $marked ? $actor->id : null,
            'marked_at' => $marked ? now() : null,
        ]);
    }
}
