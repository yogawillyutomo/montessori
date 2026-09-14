<?php

namespace Tests\Feature;

use App\Models\AcademicYear;
use App\Models\ChildEnrollment;
use App\Models\ClassLevel;
use App\Models\Guardian;
use App\Models\Report;
use App\Models\ReportCycle;
use App\Models\ReportPolicy;
use App\Models\ReportVersion;
use App\Models\ReportWorkflowEvent;
use App\Models\SchoolClass;
use App\Models\Student;
use App\Models\Term;
use App\Models\User;
use App\Services\Alpha\ReportBuilderService;
use App\Services\Reporting\ReportEligibilityService;
use App\Services\Reporting\ReportWorkflowService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use LogicException;
use Tests\TestCase;

class ReportWorkflowTest extends TestCase
{
    use RefreshDatabase;

    public function test_not_eligible_child_cannot_create_cycle_report(): void
    {
        $context = $this->context('M14-NOT-ELIGIBLE', 60, 8, true, '2026-09-20');

        $this->expectException(ValidationException::class);

        try {
            app(ReportWorkflowService::class)->createOrRefreshDraft(
                $context['student'],
                $context['cycle'],
                $context['admin'],
            );
        } finally {
            $this->assertSame(0, Report::query()->where('report_cycle_id', $context['cycle']->id)->count());
        }
    }

    public function test_eligible_child_gets_cycle_report_with_identity_and_audit_event(): void
    {
        $context = $this->context('M14-DRAFT');
        $this->makeEligible($context);

        $report = app(ReportWorkflowService::class)->createOrRefreshDraft(
            $context['student'],
            $context['cycle'],
            $context['admin'],
        );

        $this->assertSame('draft', $report->status);
        $this->assertSame(1, $report->working_revision);
        $this->assertSame($context['student']->id, $report->student_id);
        $this->assertSame($context['term']->id, $report->term_id);
        $this->assertSame($context['cycle']->id, $report->report_cycle_id);
        $this->assertSame($context['enrollment']->id, $report->child_enrollment_id);

        $event = ReportWorkflowEvent::query()->where('report_id', $report->id)->firstOrFail();
        $this->assertNull($event->from_status);
        $this->assertSame('draft', $event->to_status);
        $this->assertSame($context['admin']->id, $event->actor_id);
    }

    public function test_same_student_can_have_two_cycle_reports_inside_one_term(): void
    {
        $context = $this->context('M14-TWO-CYCLES');
        $this->makeEligible($context);
        $first = app(ReportWorkflowService::class)->createOrRefreshDraft(
            $context['student'],
            $context['cycle'],
            $context['admin'],
        );

        $secondCycle = $this->cycle(
            $context['policy'],
            'October 2026',
            '2026-10-01',
            '2026-10-31',
        );
        app(ReportEligibilityService::class)->evaluate($context['enrollment'], $secondCycle);
        $second = app(ReportWorkflowService::class)->createOrRefreshDraft(
            $context['student'],
            $secondCycle,
            $context['admin'],
        );

        $this->assertNotSame($first->id, $second->id);
        $this->assertSame($first->term_id, $second->term_id);
        $this->assertNotSame($first->report_cycle_id, $second->report_cycle_id);
        $this->assertSame(
            2,
            Report::query()
                ->where('student_id', $context['student']->id)
                ->where('term_id', $context['term']->id)
                ->whereNotNull('report_cycle_id')
                ->count(),
        );
    }

    public function test_legacy_and_cycle_report_can_coexist_for_same_student_and_term(): void
    {
        $context = $this->context('M14-COEXIST');
        $legacy = Report::query()->create([
            'student_id' => $context['student']->id,
            'term_id' => $context['term']->id,
            'status' => 'draft',
            'summary' => [],
        ]);
        $this->makeEligible($context);
        $cycleReport = app(ReportWorkflowService::class)->createOrRefreshDraft(
            $context['student'],
            $context['cycle'],
            $context['admin'],
        );

        $this->assertNull($legacy->report_cycle_id);
        $this->assertSame($context['cycle']->id, $cycleReport->report_cycle_id);
        $this->assertSame(2, Report::query()->where('student_id', $context['student']->id)->count());
    }

    public function test_cycle_report_rejects_invalid_state_jump_and_legacy_publish_bypass(): void
    {
        $context = $this->context('M14-BYPASS');
        $report = $this->draft($context);

        try {
            $report->forceFill(['status' => 'approved'])->save();
            $this->fail('Draft cycle report must not jump directly to APPROVED.');
        } catch (ValidationException) {
            $this->assertSame('draft', $report->fresh()->status);
        }

        $this->expectException(ValidationException::class);
        app(ReportBuilderService::class)->publish($report->fresh(), $context['admin']);
    }

    public function test_full_cycle_workflow_publishes_one_immutable_version_and_audit_sequence(): void
    {
        $context = $this->context('M14-FLOW');
        $report = $this->draft($context);
        $workflow = app(ReportWorkflowService::class);

        $report = $workflow->saveContent($report, $context['admin'], [
            'teacher_narrative' => 'Version one narrative.',
            'manual_present_total' => 8,
        ]);
        $report = $workflow->submitForReview($report, $context['admin']);
        $this->assertSame('submitted_for_review', $report->status);
        $report = $workflow->startReview($report, $context['principal']);
        $this->assertSame('under_review', $report->status);
        $report = $workflow->approve($report, $context['principal']);
        $this->assertSame('approved', $report->status);
        $report = $workflow->publish($report, $context['admin']);

        $this->assertSame('published', $report->status);
        $this->assertNotNull($report->published_version_id);
        $this->assertSame(1, ReportVersion::query()->where('report_id', $report->id)->count());

        $version = $report->publishedVersion()->firstOrFail();
        $this->assertSame(1, $version->version_number);
        $this->assertSame('Version one narrative.', $version->snapshot['report']['teacher_narrative']);
        $this->assertSame('published', $version->snapshot['report']['status']);

        $this->assertSame(
            ['draft', 'submitted_for_review', 'under_review', 'approved', 'published'],
            ReportWorkflowEvent::query()
                ->where('report_id', $report->id)
                ->orderBy('id')
                ->pluck('to_status')
                ->all(),
        );
    }

    public function test_version_and_workflow_event_are_append_only(): void
    {
        $context = $this->context('M14-IMMUTABLE');
        $report = $this->publish($context, 'Immutable narrative.');
        $version = $report->publishedVersion()->firstOrFail();
        $event = ReportWorkflowEvent::query()->where('report_id', $report->id)->firstOrFail();

        try {
            $version->update(['version_number' => 99]);
            $this->fail('Published report version must not be mutable.');
        } catch (LogicException) {
            $this->assertSame(1, $version->fresh()->version_number);
        }

        try {
            $event->update(['note' => 'rewritten']);
            $this->fail('Workflow audit event must not be mutable.');
        } catch (LogicException) {
            $this->assertNotSame('rewritten', $event->fresh()->note);
        }

        $this->expectException(LogicException::class);
        $version->delete();
    }

    public function test_content_is_locked_after_submission(): void
    {
        $context = $this->context('M14-LOCK');
        $workflow = app(ReportWorkflowService::class);
        $report = $this->draft($context);
        $report = $workflow->submitForReview($report, $context['admin']);

        $this->expectException(ValidationException::class);

        $workflow->saveContent($report, $context['admin'], [
            'teacher_narrative' => 'This must not be saved.',
        ]);
    }

    public function test_published_revision_preserves_parent_snapshot_until_next_publish(): void
    {
        $context = $this->context('M14-REVISION');
        $workflow = app(ReportWorkflowService::class);
        $report = $this->publish($context, 'Version one public narrative.');
        $firstVersionId = $report->published_version_id;

        $report = $workflow->beginRevision($report, $context['admin'], 'Correct narrative wording.');
        $this->assertSame('draft', $report->status);
        $this->assertSame(2, $report->working_revision);
        $this->assertSame($firstVersionId, $report->published_version_id);

        $report = $workflow->saveContent($report, $context['admin'], [
            'teacher_narrative' => 'Version two working draft.',
        ]);

        $publicDuringRevision = $report->publishedDisplayReport();
        $this->assertNotNull($publicDuringRevision);
        $this->assertSame('Version one public narrative.', $publicDuringRevision->teacher_narrative);
        $this->assertSame('Version two working draft.', $report->fresh()->teacher_narrative);

        $report = $workflow->submitForReview($report, $context['admin']);
        $report = $workflow->startReview($report, $context['principal']);
        $report = $workflow->approve($report, $context['principal']);
        $report = $workflow->publish($report, $context['admin']);

        $this->assertSame(2, ReportVersion::query()->where('report_id', $report->id)->count());
        $this->assertNotSame($firstVersionId, $report->published_version_id);
        $this->assertSame('Version two working draft.', $report->publishedDisplayReport()?->teacher_narrative);

        $firstVersion = ReportVersion::query()->findOrFail($firstVersionId);
        $this->assertSame('Version one public narrative.', $firstVersion->snapshot['report']['teacher_narrative']);
    }

    public function test_parent_route_reads_published_snapshot_while_working_revision_is_private(): void
    {
        $context = $this->context('M14-PARENT');
        $workflow = app(ReportWorkflowService::class);
        $report = $this->publish($context, 'Parent may see version one only.');
        $report = $workflow->beginRevision($report, $context['admin'], 'Prepare version two.');
        $report = $workflow->saveContent($report, $context['admin'], [
            'teacher_narrative' => 'PRIVATE VERSION TWO WORKING DRAFT',
        ]);

        $response = $this->actingAs($context['parent'])->get(route('alpha.reports.show', $report));

        $response->assertOk();
        $response->assertSee('Parent may see version one only.');
        $response->assertDontSee('PRIVATE VERSION TWO WORKING DRAFT');
    }

    public function test_route_roles_block_teacher_approval_and_principal_publication(): void
    {
        $context = $this->context('M14-ROLES');
        $workflow = app(ReportWorkflowService::class);
        $report = $this->draft($context);
        $report = $workflow->submitForReview($report, $context['admin']);
        $report = $workflow->startReview($report, $context['principal']);

        $teacher = User::factory()->create([
            'role' => 'teacher',
            'is_active' => true,
        ]);

        $this->actingAs($teacher)
            ->post(route('alpha.cycle-reports.approve', $report))
            ->assertForbidden();

        $report = $workflow->approve($report, $context['principal']);

        $this->actingAs($context['principal'])
            ->post(route('alpha.cycle-reports.publish', $report))
            ->assertForbidden();
    }

    public function test_cycle_report_requires_exactly_one_term_covering_cutoff(): void
    {
        $context = $this->context('M14-TERM');
        $this->makeEligible($context);
        $context['term']->delete();

        $this->expectException(ValidationException::class);

        try {
            app(ReportWorkflowService::class)->createOrRefreshDraft(
                $context['student'],
                $context['cycle'],
                $context['admin'],
            );
        } finally {
            $this->assertSame(0, Report::query()->where('report_cycle_id', $context['cycle']->id)->count());
        }
    }

    /**
     * @return array{
     *   admin: User,
     *   principal: User,
     *   parent: User,
     *   student: Student,
     *   enrollment: ChildEnrollment,
     *   policy: ReportPolicy,
     *   cycle: ReportCycle,
     *   term: Term
     * }
     */
    private function context(
        string $code,
        int $minimumDays = 0,
        int $minimumSessions = 0,
        bool $requireGuideConfirmation = false,
        string $enrollmentStart = '2026-07-01',
    ): array {
        $admin = User::factory()->create([
            'role' => 'admin',
            'is_active' => true,
        ]);
        $principal = User::factory()->create([
            'role' => 'principal',
            'is_active' => true,
        ]);
        $parent = User::factory()->create([
            'role' => 'parent',
            'is_active' => true,
        ]);
        $guardian = Guardian::query()->create([
            'user_id' => $parent->id,
            'name' => $code.' Parent',
            'relationship' => 'Orangtua',
        ]);
        $level = ClassLevel::query()->create([
            'name' => $code,
            'slug' => strtolower($code),
            'sequence' => 700,
            'is_active' => true,
        ]);
        $schoolClass = SchoolClass::query()->create([
            'class_level_id' => $level->id,
            'name' => $code.' Class',
            'slug' => strtolower($code).'-class',
            'level' => $code,
            'capacity' => 12,
            'color' => 'sage',
            'is_active' => true,
        ]);
        $student = Student::query()->create([
            'school_class_id' => $schoolClass->id,
            'guardian_id' => $guardian->id,
            'code' => $code.'-STUDENT',
            'name' => $code.' Student',
            'status' => 'active',
        ]);
        $enrollment = ChildEnrollment::query()->create([
            'student_id' => $student->id,
            'class_level_id' => $level->id,
            'starts_on' => $enrollmentStart,
            'first_session_on' => $enrollmentStart,
            'status' => 'active',
            'created_by' => $admin->id,
        ]);
        $academicYear = AcademicYear::query()->create([
            'name' => $code.' Academic Year',
            'starts_on' => '2026-07-01',
            'ends_on' => '2027-06-30',
            'is_active' => true,
        ]);
        $term = Term::query()->create([
            'academic_year_id' => $academicYear->id,
            'name' => 'Semester 1',
            'starts_on' => '2026-07-01',
            'ends_on' => '2026-12-31',
            'is_current' => true,
        ]);
        $policy = ReportPolicy::query()->create([
            'class_level_id' => $level->id,
            'name' => $code.' Policy',
            'reporting_frequency' => 'monthly',
            'minimum_observation_days' => $minimumDays,
            'minimum_attended_sessions' => $minimumSessions,
            'require_guide_confirmation' => $requireGuideConfirmation,
            'area_coverage_mode' => 'advisory',
            'is_active' => true,
        ]);
        $cycle = $this->cycle($policy, 'September 2026', '2026-09-01', '2026-09-30');

        return compact('admin', 'principal', 'parent', 'student', 'enrollment', 'policy', 'cycle', 'term');
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

    /**
     * @param  array{enrollment: ChildEnrollment, cycle: ReportCycle}  $context
     */
    private function makeEligible(array $context): void
    {
        $eligibility = app(ReportEligibilityService::class)->evaluate(
            $context['enrollment'],
            $context['cycle'],
        );

        $this->assertSame('eligible', $eligibility->status);
    }

    /**
     * @param  array{admin: User, student: Student, enrollment: ChildEnrollment, cycle: ReportCycle}  $context
     */
    private function draft(array $context): Report
    {
        $this->makeEligible($context);

        return app(ReportWorkflowService::class)->createOrRefreshDraft(
            $context['student'],
            $context['cycle'],
            $context['admin'],
        );
    }

    /**
     * @param  array{admin: User, principal: User, student: Student, enrollment: ChildEnrollment, cycle: ReportCycle}  $context
     */
    private function publish(array $context, string $narrative): Report
    {
        $workflow = app(ReportWorkflowService::class);
        $report = $this->draft($context);
        $report = $workflow->saveContent($report, $context['admin'], [
            'teacher_narrative' => $narrative,
        ]);
        $report = $workflow->submitForReview($report, $context['admin']);
        $report = $workflow->startReview($report, $context['principal']);
        $report = $workflow->approve($report, $context['principal']);

        return $workflow->publish($report, $context['admin']);
    }
}
