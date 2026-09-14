<?php

namespace Tests\Feature;

use App\Models\ChildGuideResponsibility;
use App\Models\DevelopmentArea;
use App\Models\FollowUpCandidate;
use App\Models\Indicator;
use App\Models\Observation;
use App\Models\SchoolClass;
use App\Models\Student;
use App\Models\Teacher;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FollowUpReviewQueueTest extends TestCase
{
    use RefreshDatabase;

    public function test_teacher_review_queue_only_lists_intentionally_scoped_children(): void
    {
        [$user, $teacher, $scopedStudent, $area, $indicator, $schoolClass] = $this->scenario('QUEUE');
        $otherStudent = Student::query()->create([
            'school_class_id' => $schoolClass->id,
            'code' => 'M11-QUEUE-OTHER',
            'name' => 'Hidden Child',
            'status' => 'active',
        ]);

        $this->candidateFor($scopedStudent, $teacher, $area, $indicator, 'Scoped Child Candidate');
        $this->candidateFor($otherStudent, $teacher, $area, $indicator, 'Hidden Child Candidate');

        $this->actingAs($user)
            ->get(route('alpha.process.follow-up'))
            ->assertOk()
            ->assertSee('Scoped Child Candidate')
            ->assertDontSee('Hidden Child Candidate')
            ->assertSee('Konfirmasi &amp; Buat Draft Support Plan', false);
    }

    public function test_principal_can_view_queue_but_does_not_receive_review_actions(): void
    {
        [, $teacher, $student, $area, $indicator] = $this->scenario('PRINCIPAL-QUEUE');
        $candidate = $this->candidateFor($student, $teacher, $area, $indicator, 'Principal Oversight Candidate');
        $principal = User::factory()->create([
            'role' => 'principal',
            'is_active' => true,
        ]);

        $this->actingAs($principal)
            ->get(route('alpha.process.follow-up'))
            ->assertOk()
            ->assertSee('Principal Oversight Candidate')
            ->assertSee('Mode lihat saja.')
            ->assertDontSee('Konfirmasi &amp; Buat Draft Support Plan', false);

        $this->actingAs($principal)
            ->post(route('alpha.follow-up-candidates.confirm', $candidate))
            ->assertForbidden();
    }

    public function test_confirmation_rejects_indicator_from_different_source_area(): void
    {
        [$user, $teacher, $student, $sourceArea] = $this->scenario('AREA-MISMATCH');
        $observation = Observation::query()->create([
            'student_id' => $student->id,
            'indicator_id' => null,
            'development_area_id' => $sourceArea->id,
            'teacher_id' => $teacher->id,
            'observation_type' => 'spontaneous',
            'observed_on' => now()->toDateString(),
            'level' => 'emerging',
            'status' => 'saved',
            'score' => 35,
            'note' => 'Needs review without a selected indicator.',
            'needs_follow_up' => true,
            'include_in_report' => false,
        ]);
        $candidate = FollowUpCandidate::query()->create([
            'student_id' => $student->id,
            'source_observation_id' => $observation->id,
            'indicator_id' => null,
            'status' => FollowUpCandidate::STATUS_OPEN,
            'reason_summary' => 'Area consistency test.',
            'created_by' => $user->id,
        ]);
        $otherArea = DevelopmentArea::query()->create([
            'name' => 'Sensorial Mismatch',
            'slug' => 'sensorial-mismatch',
            'color' => 'sage',
            'sort_order' => 2,
        ]);
        $otherIndicator = Indicator::query()->create([
            'development_area_id' => $otherArea->id,
            'code' => 'M11-MISMATCH-IND',
            'sub_area' => 'Visual',
            'description' => 'Different area indicator.',
            'level' => 'Infant',
            'is_active' => true,
        ]);

        $this->actingAs($user)
            ->post(route('alpha.follow-up-candidates.confirm', $candidate), [
                'indicator_id' => $otherIndicator->id,
            ])
            ->assertSessionHasErrors('indicator_id');

        $this->assertDatabaseCount('ilp_plans', 0);
        $this->assertSame(FollowUpCandidate::STATUS_OPEN, $candidate->fresh()->status);
    }

    public function test_dashboard_surfaces_open_candidate_as_pending_review_not_automatic_ilp(): void
    {
        [$user, $teacher, $student, $area, $indicator] = $this->scenario('DASHBOARD');
        $this->candidateFor($student, $teacher, $area, $indicator, 'Dashboard Review Candidate');

        $this->actingAs($user)
            ->get(route('alpha.dashboard'))
            ->assertOk()
            ->assertSee('Follow-up terbuka')
            ->assertSee('Dashboard Review Candidate')
            ->assertSee('Buka Review Queue')
            ->assertDontSee('masuk ILP');
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
            'name' => 'Review Guide '.$suffix,
            'code' => 'M11-REVIEW-GUIDE-'.$suffix,
            'is_active' => true,
        ]);
        $schoolClass = SchoolClass::query()->create([
            'name' => 'M11 Review '.$suffix,
            'slug' => 'm11-review-'.strtolower($suffix),
            'level' => 'Infant',
            'capacity' => 12,
            'color' => 'sage',
            'is_active' => true,
        ]);
        $student = Student::query()->create([
            'school_class_id' => $schoolClass->id,
            'code' => 'M11-REVIEW-STUDENT-'.$suffix,
            'name' => 'Review Child '.$suffix,
            'status' => 'active',
        ]);
        ChildGuideResponsibility::query()->create([
            'student_id' => $student->id,
            'teacher_id' => $teacher->id,
            'responsibility_type' => 'primary_guide',
            'valid_from' => now()->subDay()->toDateString(),
            'is_active' => true,
        ]);
        $area = DevelopmentArea::query()->create([
            'name' => 'Practical Life Review '.$suffix,
            'slug' => 'practical-life-review-'.strtolower($suffix),
            'color' => 'sage',
            'sort_order' => 1,
        ]);
        $indicator = Indicator::query()->create([
            'development_area_id' => $area->id,
            'code' => 'M11-REVIEW-IND-'.$suffix,
            'sub_area' => 'Care of Self',
            'description' => 'Review indicator '.$suffix,
            'level' => 'Infant',
            'is_active' => true,
        ]);

        return [$user, $teacher, $student, $area, $indicator, $schoolClass];
    }

    private function candidateFor(
        Student $student,
        Teacher $teacher,
        DevelopmentArea $area,
        Indicator $indicator,
        string $note,
    ): FollowUpCandidate {
        $observation = Observation::query()->create([
            'student_id' => $student->id,
            'indicator_id' => $indicator->id,
            'development_area_id' => $area->id,
            'teacher_id' => $teacher->id,
            'observation_type' => 'spontaneous',
            'observed_on' => now()->toDateString(),
            'level' => 'emerging',
            'status' => 'saved',
            'score' => 35,
            'note' => $note,
            'needs_follow_up' => true,
            'include_in_report' => false,
        ]);

        return FollowUpCandidate::query()->create([
            'student_id' => $student->id,
            'source_observation_id' => $observation->id,
            'indicator_id' => $indicator->id,
            'status' => FollowUpCandidate::STATUS_OPEN,
            'reason_summary' => $note,
        ]);
    }
}
