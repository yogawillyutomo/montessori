<?php

namespace Tests\Feature;

use App\Models\ChildGuideResponsibility;
use App\Models\DevelopmentArea;
use App\Models\DevelopmentProgress;
use App\Models\Indicator;
use App\Models\MontessoriActivity;
use App\Models\SchoolClass;
use App\Models\Student;
use App\Models\Teacher;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class DevelopmentProgressTest extends TestCase
{
    use RefreshDatabase;

    public function test_teacher_can_record_indicator_progress_as_professional_judgement_without_mutating_evidence(): void
    {
        [$user, $teacher, $student, $indicator] = $this->teacherScenario('INDICATOR');

        $response = $this->actingAs($user)->post(route('alpha.development-progress.store'), [
            'student_id' => $student->id,
            'teacher_id' => $teacher->id,
            'indicator_id' => $indicator->id,
            'progress_state' => 'developing',
            'judged_at' => now()->subMinute()->toDateTimeString(),
            'note' => 'Guide judgement after reviewing longitudinal evidence.',
        ]);

        $response->assertRedirect();
        $response->assertSessionHas('status', 'Development progress berhasil dicatat.');

        $progress = DevelopmentProgress::query()->sole();
        $this->assertSame($student->id, $progress->student_id);
        $this->assertSame($indicator->id, $progress->indicator_id);
        $this->assertNull($progress->montessori_activity_id);
        $this->assertSame('developing', $progress->progress_state);
        $this->assertSame($teacher->id, $progress->judged_by);
        $this->assertSame($user->id, $progress->recorded_by);
        $this->assertDatabaseCount('observations', 0);
        $this->assertDatabaseCount('presentations', 0);
        $this->assertDatabaseCount('ilp_plans', 0);
    }

    public function test_activity_progress_history_is_append_only_and_regression_is_allowed(): void
    {
        [$user, $teacher, $student, , $activity] = $this->teacherScenario('HISTORY');

        foreach (['independent', 'developing'] as $state) {
            $this->actingAs($user)->post(route('alpha.development-progress.store'), [
                'student_id' => $student->id,
                'teacher_id' => $teacher->id,
                'montessori_activity_id' => $activity->id,
                'progress_state' => $state,
            ])->assertRedirect();
        }

        $history = DevelopmentProgress::query()->orderBy('id')->get();

        $this->assertCount(2, $history);
        $this->assertSame('independent', $history[0]->progress_state);
        $this->assertSame('developing', $history[1]->progress_state);
        $this->assertSame($activity->id, $history[0]->montessori_activity_id);
        $this->assertSame($activity->id, $history[1]->montessori_activity_id);
    }

    public function test_existing_progress_history_cannot_be_updated_or_deleted(): void
    {
        [$user, $teacher, $student, $indicator] = $this->teacherScenario('IMMUTABLE');

        $this->actingAs($user)->post(route('alpha.development-progress.store'), [
            'student_id' => $student->id,
            'teacher_id' => $teacher->id,
            'indicator_id' => $indicator->id,
            'progress_state' => 'developing',
        ])->assertRedirect();

        $progress = DevelopmentProgress::query()->sole();

        try {
            $progress->update(['progress_state' => 'mastered']);
            $this->fail('Existing progress history should reject updates.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('development_progress', $exception->errors());
        }

        $progress->refresh();
        $this->assertSame('developing', $progress->progress_state);

        try {
            $progress->delete();
            $this->fail('Existing progress history should reject deletion.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('development_progress', $exception->errors());
        }

        $this->assertDatabaseCount('development_progress', 1);
        $this->assertDatabaseHas('development_progress', [
            'id' => $progress->id,
            'progress_state' => 'developing',
        ]);
    }

    public function test_teacher_cannot_record_progress_for_out_of_scope_child(): void
    {
        [$user, $teacher, , $indicator, , $schoolClass] = $this->teacherScenario('OUT-SCOPE');
        $otherStudent = $this->student($schoolClass, 'OUT-SCOPE-OTHER');

        $this->actingAs($user)->post(route('alpha.development-progress.store'), [
            'student_id' => $otherStudent->id,
            'teacher_id' => $teacher->id,
            'indicator_id' => $indicator->id,
            'progress_state' => 'practicing',
        ])->assertForbidden();

        $this->assertDatabaseCount('development_progress', 0);
    }

    public function test_teacher_cannot_record_progress_on_behalf_of_another_guide(): void
    {
        [$user, , $student, $indicator] = $this->teacherScenario('IDENTITY');
        $otherUser = User::factory()->create([
            'role' => 'teacher',
            'is_active' => true,
        ]);
        $otherTeacher = Teacher::query()->create([
            'user_id' => $otherUser->id,
            'name' => 'Other Guide',
            'code' => 'M10-OTHER-GUIDE',
            'is_active' => true,
        ]);

        $this->actingAs($user)->post(route('alpha.development-progress.store'), [
            'student_id' => $student->id,
            'teacher_id' => $otherTeacher->id,
            'indicator_id' => $indicator->id,
            'progress_state' => 'developing',
        ])->assertForbidden();

        $this->assertDatabaseCount('development_progress', 0);
    }

    public function test_progress_requires_exactly_one_target(): void
    {
        [$user, $teacher, $student, $indicator, $activity] = $this->teacherScenario('TARGET');

        $this->actingAs($user)->post(route('alpha.development-progress.store'), [
            'student_id' => $student->id,
            'teacher_id' => $teacher->id,
            'progress_state' => 'presented',
        ])->assertSessionHasErrors(['indicator_id', 'montessori_activity_id']);

        $this->actingAs($user)->post(route('alpha.development-progress.store'), [
            'student_id' => $student->id,
            'teacher_id' => $teacher->id,
            'indicator_id' => $indicator->id,
            'montessori_activity_id' => $activity->id,
            'progress_state' => 'presented',
        ])->assertSessionHasErrors(['indicator_id', 'montessori_activity_id']);

        $this->assertDatabaseCount('development_progress', 0);
    }

    public function test_inactive_targets_and_future_judgement_are_rejected(): void
    {
        [$user, $teacher, $student, $indicator, $activity] = $this->teacherScenario('VALIDATION');

        $indicator->update(['is_active' => false]);
        $this->actingAs($user)->post(route('alpha.development-progress.store'), [
            'student_id' => $student->id,
            'teacher_id' => $teacher->id,
            'indicator_id' => $indicator->id,
            'progress_state' => 'developing',
        ])->assertSessionHasErrors('indicator_id');

        $activity->update(['is_active' => false]);
        $this->actingAs($user)->post(route('alpha.development-progress.store'), [
            'student_id' => $student->id,
            'teacher_id' => $teacher->id,
            'montessori_activity_id' => $activity->id,
            'progress_state' => 'developing',
        ])->assertSessionHasErrors('montessori_activity_id');

        $indicator->update(['is_active' => true]);
        $this->actingAs($user)->post(route('alpha.development-progress.store'), [
            'student_id' => $student->id,
            'teacher_id' => $teacher->id,
            'indicator_id' => $indicator->id,
            'progress_state' => 'developing',
            'judged_at' => now()->addDay()->toDateTimeString(),
        ])->assertSessionHasErrors('judged_at');

        $this->assertDatabaseCount('development_progress', 0);
    }

    public function test_presentation_does_not_automatically_create_development_progress(): void
    {
        [$user, $teacher, $student, , $activity] = $this->teacherScenario('NO-AUTO-PRESENTATION');

        $this->actingAs($user)->post(route('alpha.presentations.store'), [
            'student_id' => $student->id,
            'teacher_id' => $teacher->id,
            'montessori_activity_id' => $activity->id,
            'presented_on' => now()->toDateString(),
            'presentation_type' => 'initial',
        ])->assertRedirect();

        $this->assertDatabaseCount('presentations', 1);
        $this->assertDatabaseCount('development_progress', 0);
    }

    public function test_observation_does_not_automatically_create_development_progress(): void
    {
        [$user, $teacher, $student, $indicator] = $this->teacherScenario('NO-AUTO-OBSERVATION');

        $this->actingAs($user)->post(route('alpha.observations.store'), [
            'student_id' => $student->id,
            'teacher_id' => $teacher->id,
            'development_area_id' => $indicator->development_area_id,
            'indicator_id' => $indicator->id,
            'observed_on' => now()->toDateString(),
            'level' => 'developing',
            'note' => 'Objective observation evidence only; not a progress judgement.',
            'needs_follow_up' => false,
            'include_in_report' => false,
        ])->assertRedirect();

        $this->assertDatabaseCount('observations', 1);
        $this->assertDatabaseCount('development_progress', 0);
    }

    public function test_admin_can_record_on_behalf_of_guide_with_separate_audit_actor(): void
    {
        [, $teacher, $student, $indicator] = $this->teacherScenario('ADMIN');
        $admin = User::factory()->create([
            'role' => 'admin',
            'is_active' => true,
        ]);

        $this->actingAs($admin)->post(route('alpha.development-progress.store'), [
            'student_id' => $student->id,
            'teacher_id' => $teacher->id,
            'indicator_id' => $indicator->id,
            'progress_state' => 'independent',
        ])->assertRedirect();

        $progress = DevelopmentProgress::query()->sole();
        $this->assertSame($teacher->id, $progress->judged_by);
        $this->assertSame($admin->id, $progress->recorded_by);
    }

    public function test_principal_cannot_record_development_progress(): void
    {
        [, $teacher, $student, $indicator] = $this->teacherScenario('PRINCIPAL');
        $principal = User::factory()->create([
            'role' => 'principal',
            'is_active' => true,
        ]);

        $this->actingAs($principal)->post(route('alpha.development-progress.store'), [
            'student_id' => $student->id,
            'teacher_id' => $teacher->id,
            'indicator_id' => $indicator->id,
            'progress_state' => 'developing',
        ])->assertForbidden();

        $this->assertDatabaseCount('development_progress', 0);
    }

    /**
     * @return array{User, Teacher, Student, Indicator, MontessoriActivity, SchoolClass}
     */
    private function teacherScenario(string $suffix): array
    {
        $user = User::factory()->create([
            'role' => 'teacher',
            'is_active' => true,
        ]);
        $teacher = Teacher::query()->create([
            'user_id' => $user->id,
            'name' => 'Guide '.$suffix,
            'code' => 'M10-GUIDE-'.$suffix,
            'is_active' => true,
        ]);
        $schoolClass = SchoolClass::query()->create([
            'name' => 'M10 '.$suffix,
            'slug' => 'm10-'.strtolower($suffix),
            'level' => 'Infant',
            'capacity' => 12,
            'color' => 'sage',
            'is_active' => true,
        ]);
        $student = $this->student($schoolClass, $suffix);
        ChildGuideResponsibility::query()->create([
            'student_id' => $student->id,
            'teacher_id' => $teacher->id,
            'responsibility_type' => 'primary_guide',
            'valid_from' => now()->subDay()->toDateString(),
            'valid_until' => null,
            'is_active' => true,
        ]);
        $area = DevelopmentArea::query()->create([
            'name' => 'Sensorial '.$suffix,
            'slug' => 'sensorial-'.strtolower($suffix),
            'color' => 'sage',
            'sort_order' => 1,
        ]);
        $indicator = Indicator::query()->create([
            'development_area_id' => $area->id,
            'code' => 'M10-IND-'.$suffix,
            'sub_area' => 'Visual Discrimination',
            'description' => 'Discriminates dimension with increasing independence.',
            'level' => 'Infant',
            'is_active' => true,
        ]);
        $activity = MontessoriActivity::query()->create([
            'development_area_id' => $area->id,
            'code' => 'M10-ACT-'.$suffix,
            'name' => 'Pink Tower '.$suffix,
            'direct_aim' => 'Visual discrimination of dimension',
            'sequence_order' => 1,
            'is_active' => true,
        ]);

        return [$user, $teacher, $student, $indicator, $activity, $schoolClass];
    }

    private function student(SchoolClass $schoolClass, string $suffix): Student
    {
        return Student::query()->create([
            'school_class_id' => $schoolClass->id,
            'code' => 'M10-STUDENT-'.$suffix,
            'name' => 'Child '.$suffix,
            'status' => 'active',
        ]);
    }
}
