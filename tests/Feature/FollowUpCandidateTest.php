<?php

namespace Tests\Feature;

use App\Models\ChildGuideResponsibility;
use App\Models\DevelopmentArea;
use App\Models\FollowUpCandidate;
use App\Models\Indicator;
use App\Models\SchoolClass;
use App\Models\Student;
use App\Models\Teacher;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FollowUpCandidateTest extends TestCase
{
    use RefreshDatabase;

    public function test_follow_up_observation_creates_review_candidate_instead_of_automatic_ilp(): void
    {
        [$user, $teacher, $student, $area, $indicator] = $this->scenario('CAPTURE');

        $this->postObservation($user, $teacher, $student, $area, $indicator, true)
            ->assertRedirect(route('alpha.process.observations').'#monitoring-harian');

        $candidate = FollowUpCandidate::query()->sole();

        $this->assertSame($student->id, $candidate->student_id);
        $this->assertSame($indicator->id, $candidate->indicator_id);
        $this->assertSame(FollowUpCandidate::STATUS_OPEN, $candidate->status);
        $this->assertSame($user->id, $candidate->created_by);
        $this->assertNotNull($candidate->source_observation_id);
        $this->assertDatabaseCount('ilp_plans', 0);
    }

    public function test_observation_without_follow_up_flag_creates_neither_candidate_nor_ilp(): void
    {
        [$user, $teacher, $student, $area, $indicator] = $this->scenario('NO-FOLLOW-UP');

        $this->postObservation($user, $teacher, $student, $area, $indicator, false)
            ->assertRedirect();

        $this->assertDatabaseCount('follow_up_candidates', 0);
        $this->assertDatabaseCount('ilp_plans', 0);
    }

    public function test_confirming_candidate_creates_blank_draft_support_plan_only_after_human_review(): void
    {
        [$user, $teacher, $student, $area, $indicator] = $this->scenario('CONFIRM');
        $this->postObservation($user, $teacher, $student, $area, $indicator, true);
        $candidate = FollowUpCandidate::query()->sole();

        $response = $this->actingAs($user)->post(route('alpha.follow-up-candidates.confirm', $candidate), [
            'review_note' => 'Pattern needs structured support review.',
        ]);

        $response->assertRedirect();

        $candidate->refresh();
        $plan = $candidate->supportPlan()->sole();

        $this->assertSame(FollowUpCandidate::STATUS_CONFIRMED, $candidate->status);
        $this->assertSame($user->id, $candidate->reviewed_by);
        $this->assertNotNull($candidate->reviewed_at);
        $this->assertSame('Pattern needs structured support review.', $candidate->review_note);
        $this->assertSame('draft', $plan->status);
        $this->assertSame($student->id, $plan->student_id);
        $this->assertSame($indicator->id, $plan->indicator_id);
        $this->assertSame($candidate->source_observation_id, $plan->trigger_observation_id);
        $this->assertSame($candidate->id, $plan->follow_up_candidate_id);
        $this->assertNull($plan->analysis);
        $this->assertNull($plan->target);
        $this->assertNull($plan->follow_up);
        $this->assertNull($plan->starts_on);
        $this->assertNull($plan->ends_on);
    }

    public function test_dismissing_candidate_does_not_create_support_plan(): void
    {
        [$user, $teacher, $student, $area, $indicator] = $this->scenario('DISMISS');
        $this->postObservation($user, $teacher, $student, $area, $indicator, true);
        $candidate = FollowUpCandidate::query()->sole();

        $this->actingAs($user)->post(route('alpha.follow-up-candidates.dismiss', $candidate), [
            'review_note' => 'Single event; continue ordinary observation first.',
        ])->assertRedirect();

        $candidate->refresh();
        $this->assertSame(FollowUpCandidate::STATUS_DISMISSED, $candidate->status);
        $this->assertSame($user->id, $candidate->reviewed_by);
        $this->assertDatabaseCount('ilp_plans', 0);
    }

    public function test_reviewed_candidate_cannot_be_processed_again(): void
    {
        [$user, $teacher, $student, $area, $indicator] = $this->scenario('TERMINAL');
        $this->postObservation($user, $teacher, $student, $area, $indicator, true);
        $candidate = FollowUpCandidate::query()->sole();

        $this->actingAs($user)
            ->post(route('alpha.follow-up-candidates.confirm', $candidate))
            ->assertRedirect();

        $this->actingAs($user)
            ->post(route('alpha.follow-up-candidates.dismiss', $candidate))
            ->assertSessionHasErrors('candidate');

        $this->assertDatabaseCount('ilp_plans', 1);
        $this->assertSame(FollowUpCandidate::STATUS_CONFIRMED, $candidate->fresh()->status);
    }

    public function test_candidate_without_indicator_requires_explicit_indicator_before_confirmation(): void
    {
        [$user, $teacher, $student, $area, $indicator] = $this->scenario('NO-INDICATOR');

        $this->actingAs($user)->post(route('alpha.observations.store'), [
            'student_id' => $student->id,
            'teacher_id' => $teacher->id,
            'development_area_id' => $area->id,
            'observed_on' => now()->toDateString(),
            'level' => 'emerging',
            'note' => 'Needs follow-up but indicator not yet selected.',
            'needs_follow_up' => true,
        ])->assertRedirect();

        $candidate = FollowUpCandidate::query()->sole();
        $this->assertNull($candidate->indicator_id);

        $this->actingAs($user)
            ->post(route('alpha.follow-up-candidates.confirm', $candidate))
            ->assertSessionHasErrors('indicator_id');

        $this->assertDatabaseCount('ilp_plans', 0);
        $this->assertSame(FollowUpCandidate::STATUS_OPEN, $candidate->fresh()->status);

        $this->actingAs($user)->post(route('alpha.follow-up-candidates.confirm', $candidate), [
            'indicator_id' => $indicator->id,
        ])->assertRedirect();

        $this->assertDatabaseHas('ilp_plans', [
            'follow_up_candidate_id' => $candidate->id,
            'indicator_id' => $indicator->id,
            'status' => 'draft',
        ]);
    }

    public function test_teacher_cannot_review_candidate_for_out_of_scope_child(): void
    {
        [$user, , , , $indicator, $schoolClass] = $this->scenario('OUT-SCOPE');
        $otherStudent = Student::query()->create([
            'school_class_id' => $schoolClass->id,
            'code' => 'M11-STUDENT-OTHER',
            'name' => 'Other Child',
            'status' => 'active',
        ]);
        $candidate = FollowUpCandidate::query()->create([
            'student_id' => $otherStudent->id,
            'indicator_id' => $indicator->id,
            'status' => FollowUpCandidate::STATUS_OPEN,
            'reason_summary' => 'Manual test candidate.',
        ]);

        $this->actingAs($user)
            ->post(route('alpha.follow-up-candidates.confirm', $candidate))
            ->assertForbidden();

        $this->assertDatabaseCount('ilp_plans', 0);
        $this->assertSame(FollowUpCandidate::STATUS_OPEN, $candidate->fresh()->status);
    }

    public function test_principal_cannot_confirm_or_dismiss_follow_up_candidates(): void
    {
        [, , $student, , $indicator] = $this->scenario('PRINCIPAL');
        $principal = User::factory()->create([
            'role' => 'principal',
            'is_active' => true,
        ]);
        $candidate = FollowUpCandidate::query()->create([
            'student_id' => $student->id,
            'indicator_id' => $indicator->id,
            'status' => FollowUpCandidate::STATUS_OPEN,
            'reason_summary' => 'Principal must not decide support flow.',
        ]);

        $this->actingAs($principal)
            ->post(route('alpha.follow-up-candidates.confirm', $candidate))
            ->assertForbidden();
        $this->actingAs($principal)
            ->post(route('alpha.follow-up-candidates.dismiss', $candidate))
            ->assertForbidden();

        $this->assertDatabaseCount('ilp_plans', 0);
    }

    /**
     * @return array{User, Teacher, Student, DevelopmentArea, Indicator, SchoolClass}
     */
    private function scenario(string $suffix): array
    {
        $user = User::factory()->create([
            'role' => 'teacher',
            'is_active' => true,
        ]);
        $teacher = Teacher::query()->create([
            'user_id' => $user->id,
            'name' => 'Guide '.$suffix,
            'code' => 'M11-GUIDE-'.$suffix,
            'is_active' => true,
        ]);
        $schoolClass = SchoolClass::query()->create([
            'name' => 'M11 '.$suffix,
            'slug' => 'm11-'.strtolower($suffix),
            'level' => 'Infant',
            'capacity' => 12,
            'color' => 'sage',
            'is_active' => true,
        ]);
        $student = Student::query()->create([
            'school_class_id' => $schoolClass->id,
            'code' => 'M11-STUDENT-'.$suffix,
            'name' => 'Child '.$suffix,
            'status' => 'active',
        ]);
        ChildGuideResponsibility::query()->create([
            'student_id' => $student->id,
            'teacher_id' => $teacher->id,
            'responsibility_type' => 'primary_guide',
            'valid_from' => now()->subDay()->toDateString(),
            'valid_until' => null,
            'is_active' => true,
        ]);
        $area = DevelopmentArea::query()->create([
            'name' => 'Practical Life '.$suffix,
            'slug' => 'practical-life-'.strtolower($suffix),
            'color' => 'sage',
            'sort_order' => 1,
        ]);
        $indicator = Indicator::query()->create([
            'development_area_id' => $area->id,
            'code' => 'M11-IND-'.$suffix,
            'sub_area' => 'Care of Self',
            'description' => 'Works with increasing independence.',
            'level' => 'Infant',
            'is_active' => true,
        ]);

        return [$user, $teacher, $student, $area, $indicator, $schoolClass];
    }

    private function postObservation(
        User $user,
        Teacher $teacher,
        Student $student,
        DevelopmentArea $area,
        Indicator $indicator,
        bool $needsFollowUp,
    ) {
        return $this->actingAs($user)->post(route('alpha.observations.store'), [
            'student_id' => $student->id,
            'teacher_id' => $teacher->id,
            'development_area_id' => $area->id,
            'indicator_id' => $indicator->id,
            'observed_on' => now()->toDateString(),
            'level' => $needsFollowUp ? 'emerging' : 'developing',
            'note' => 'Objective classroom observation.',
            'needs_follow_up' => $needsFollowUp,
            'include_in_report' => false,
        ]);
    }
}
