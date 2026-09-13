<?php

namespace Tests\Feature;

use App\Models\ChildGuideResponsibility;
use App\Models\ChildSessionBooking;
use App\Models\DevelopmentArea;
use App\Models\MontessoriActivity;
use App\Models\SchoolClass;
use App\Models\SessionOccurrence;
use App\Models\Student;
use App\Models\Teacher;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MontessoriActivityPresentationTest extends TestCase
{
    use RefreshDatabase;

    public function test_teacher_can_record_sessionless_presentation_for_scoped_child_without_creating_observation_or_ilp(): void
    {
        [$user, $teacher, $student, $activity] = $this->teacherScenario('SESSIONLESS');

        $response = $this->actingAs($user)->post(route('alpha.presentations.store'), [
            'student_id' => $student->id,
            'teacher_id' => $teacher->id,
            'montessori_activity_id' => $activity->id,
            'presented_on' => now()->toDateString(),
            'presentation_type' => 'initial',
            'note' => 'First intentional presentation.',
        ]);

        $response->assertRedirect();
        $response->assertSessionHas('status', 'Presentation Montessori berhasil dicatat.');

        $this->assertDatabaseHas('presentations', [
            'student_id' => $student->id,
            'teacher_id' => $teacher->id,
            'montessori_activity_id' => $activity->id,
            'session_occurrence_id' => null,
            'presented_on' => now()->toDateString(),
            'presentation_type' => 'initial',
            'recorded_by' => $user->id,
        ]);
        $this->assertDatabaseCount('observations', 0);
        $this->assertDatabaseCount('ilp_plans', 0);
    }

    public function test_same_activity_can_be_presented_to_same_child_more_than_once(): void
    {
        [$user, $teacher, $student, $activity] = $this->teacherScenario('REPEAT');

        foreach (['initial', 'repeat'] as $type) {
            $this->actingAs($user)->post(route('alpha.presentations.store'), [
                'student_id' => $student->id,
                'teacher_id' => $teacher->id,
                'montessori_activity_id' => $activity->id,
                'presented_on' => now()->toDateString(),
                'presentation_type' => $type,
            ])->assertRedirect();
        }

        $this->assertDatabaseCount('presentations', 2);
    }

    public function test_teacher_cannot_record_presentation_for_out_of_scope_child(): void
    {
        [$user, $teacher, , $activity, $schoolClass] = $this->teacherScenario('OUT-SCOPE');
        $otherStudent = $this->student($schoolClass, 'OUT-SCOPE-OTHER');

        $this->actingAs($user)->post(route('alpha.presentations.store'), [
            'student_id' => $otherStudent->id,
            'teacher_id' => $teacher->id,
            'montessori_activity_id' => $activity->id,
            'presented_on' => now()->toDateString(),
        ])->assertForbidden();

        $this->assertDatabaseCount('presentations', 0);
    }

    public function test_teacher_cannot_record_presentation_on_behalf_of_another_teacher(): void
    {
        [$user, , $student, $activity] = $this->teacherScenario('IDENTITY');
        $otherUser = User::factory()->create(['role' => 'teacher']);
        $otherTeacher = Teacher::query()->create([
            'user_id' => $otherUser->id,
            'name' => 'Other Guide',
            'code' => 'M9-OTHER-GUIDE',
            'is_active' => true,
        ]);

        $this->actingAs($user)->post(route('alpha.presentations.store'), [
            'student_id' => $student->id,
            'teacher_id' => $otherTeacher->id,
            'montessori_activity_id' => $activity->id,
            'presented_on' => now()->toDateString(),
        ])->assertForbidden();

        $this->assertDatabaseCount('presentations', 0);
    }

    public function test_inactive_activity_and_future_presentation_are_rejected(): void
    {
        [$user, $teacher, $student, $activity] = $this->teacherScenario('VALIDATION');
        $activity->update(['is_active' => false]);

        $this->actingAs($user)->post(route('alpha.presentations.store'), [
            'student_id' => $student->id,
            'teacher_id' => $teacher->id,
            'montessori_activity_id' => $activity->id,
            'presented_on' => now()->toDateString(),
        ])->assertSessionHasErrors('montessori_activity_id');

        $activity->update(['is_active' => true]);

        $this->actingAs($user)->post(route('alpha.presentations.store'), [
            'student_id' => $student->id,
            'teacher_id' => $teacher->id,
            'montessori_activity_id' => $activity->id,
            'presented_on' => now()->addDay()->toDateString(),
        ])->assertSessionHasErrors('presented_on');

        $this->assertDatabaseCount('presentations', 0);
    }

    public function test_occurrence_link_requires_matching_date_and_active_child_booking(): void
    {
        [$user, $teacher, $student, $activity] = $this->teacherScenario('OCCURRENCE');
        $occurrence = $this->occurrence(now()->toDateString(), 'planned');

        $this->actingAs($user)->post(route('alpha.presentations.store'), [
            'student_id' => $student->id,
            'teacher_id' => $teacher->id,
            'montessori_activity_id' => $activity->id,
            'session_occurrence_id' => $occurrence->id,
            'presented_on' => now()->toDateString(),
        ])->assertSessionHasErrors('session_occurrence_id');

        ChildSessionBooking::query()->create([
            'student_id' => $student->id,
            'session_occurrence_id' => $occurrence->id,
            'booking_type' => 'regular',
            'status' => 'scheduled',
            'source_type' => 'm9_test',
            'created_by' => $user->id,
        ]);

        $this->actingAs($user)->post(route('alpha.presentations.store'), [
            'student_id' => $student->id,
            'teacher_id' => $teacher->id,
            'montessori_activity_id' => $activity->id,
            'session_occurrence_id' => $occurrence->id,
            'presented_on' => now()->subDay()->toDateString(),
        ])->assertSessionHasErrors('presented_on');

        $this->actingAs($user)->post(route('alpha.presentations.store'), [
            'student_id' => $student->id,
            'teacher_id' => $teacher->id,
            'montessori_activity_id' => $activity->id,
            'session_occurrence_id' => $occurrence->id,
            'presented_on' => now()->toDateString(),
        ])->assertRedirect();

        $this->assertDatabaseHas('presentations', [
            'student_id' => $student->id,
            'session_occurrence_id' => $occurrence->id,
        ]);
    }

    public function test_cancelled_occurrence_cannot_be_used_as_presentation_context(): void
    {
        [$user, $teacher, $student, $activity] = $this->teacherScenario('CANCELLED');
        $occurrence = $this->occurrence(now()->toDateString(), 'cancelled');

        $this->actingAs($user)->post(route('alpha.presentations.store'), [
            'student_id' => $student->id,
            'teacher_id' => $teacher->id,
            'montessori_activity_id' => $activity->id,
            'session_occurrence_id' => $occurrence->id,
            'presented_on' => now()->toDateString(),
        ])->assertSessionHasErrors('session_occurrence_id');

        $this->assertDatabaseCount('presentations', 0);
    }

    /**
     * @return array{User, Teacher, Student, MontessoriActivity, SchoolClass}
     */
    private function teacherScenario(string $suffix): array
    {
        $user = User::factory()->create(['role' => 'teacher']);
        $teacher = Teacher::query()->create([
            'user_id' => $user->id,
            'name' => 'Guide '.$suffix,
            'code' => 'M9-GUIDE-'.$suffix,
            'is_active' => true,
        ]);
        $schoolClass = SchoolClass::query()->create([
            'name' => 'M9 '.$suffix,
            'slug' => 'm9-'.strtolower($suffix),
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
            'name' => 'Practical Life '.$suffix,
            'slug' => 'practical-life-'.strtolower($suffix),
            'color' => 'sage',
            'sort_order' => 1,
        ]);
        $activity = MontessoriActivity::query()->create([
            'development_area_id' => $area->id,
            'code' => 'M9-ACT-'.$suffix,
            'name' => 'Pouring Water '.$suffix,
            'direct_aim' => 'Coordination and concentration',
            'sequence_order' => 1,
            'is_active' => true,
        ]);

        return [$user, $teacher, $student, $activity, $schoolClass];
    }

    private function student(SchoolClass $schoolClass, string $suffix): Student
    {
        return Student::query()->create([
            'school_class_id' => $schoolClass->id,
            'code' => 'M9-STUDENT-'.$suffix,
            'name' => 'Child '.$suffix,
            'status' => 'active',
        ]);
    }

    private function occurrence(string $date, string $status): SessionOccurrence
    {
        return SessionOccurrence::query()->create([
            'session_template_id' => null,
            'environment_id' => null,
            'occurs_on' => $date,
            'starts_at' => '10:00',
            'ends_at' => '11:00',
            'capacity' => 8,
            'room' => 'M9 '.$date.' '.$status,
            'status' => $status,
        ]);
    }
}
