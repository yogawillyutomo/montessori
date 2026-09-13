<?php

namespace Tests\Feature;

use App\Models\ChildGuideResponsibility;
use App\Models\ClassSession;
use App\Models\DevelopmentArea;
use App\Models\Indicator;
use App\Models\SchoolClass;
use App\Models\Student;
use App\Models\Teacher;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ScheduledFollowUpSignalTest extends TestCase
{
    use RefreshDatabase;

    public function test_scheduled_emerging_observation_remains_evidence_without_automatic_follow_up_candidate(): void
    {
        [$user, $teacher, $student, $indicator, $session] = $this->scenario('EMERGING');

        $this->actingAs($user)->post(route('alpha.observations.store'), [
            'class_session_id' => $session->id,
            'student_id' => $student->id,
            'teacher_id' => $teacher->id,
            'observed_on' => now()->toDateString(),
            'observations' => [
                $indicator->id => ['status' => 'emerging'],
            ],
        ])->assertRedirect();

        $this->assertDatabaseHas('observations', [
            'student_id' => $student->id,
            'indicator_id' => $indicator->id,
            'level' => 'emerging',
            'needs_follow_up' => false,
        ]);
        $this->assertDatabaseCount('follow_up_candidates', 0);
        $this->assertDatabaseCount('ilp_plans', 0);
    }

    public function test_scheduled_needs_support_signal_creates_candidate_but_not_support_plan(): void
    {
        [$user, $teacher, $student, $indicator, $session] = $this->scenario('NEEDS-SUPPORT');

        $this->actingAs($user)->post(route('alpha.observations.store'), [
            'class_session_id' => $session->id,
            'student_id' => $student->id,
            'teacher_id' => $teacher->id,
            'observed_on' => now()->toDateString(),
            'observations' => [
                $indicator->id => ['status' => 'needs_support'],
            ],
        ])->assertRedirect();

        $this->assertDatabaseHas('observations', [
            'student_id' => $student->id,
            'indicator_id' => $indicator->id,
            'level' => 'emerging',
            'needs_follow_up' => true,
        ]);
        $this->assertDatabaseHas('follow_up_candidates', [
            'student_id' => $student->id,
            'indicator_id' => $indicator->id,
            'status' => 'open',
        ]);
        $this->assertDatabaseCount('ilp_plans', 0);
    }

    /**
     * @return array{User, Teacher, Student, Indicator, ClassSession}
     */
    private function scenario(string $suffix): array
    {
        $user = User::factory()->create([
            'role' => 'teacher',
            'is_active' => true,
        ]);
        $teacher = Teacher::query()->create([
            'user_id' => $user->id,
            'name' => 'Scheduled Guide '.$suffix,
            'code' => 'M11-SCHEDULED-GUIDE-'.$suffix,
            'is_active' => true,
        ]);
        $schoolClass = SchoolClass::query()->create([
            'name' => 'M11 Scheduled '.$suffix,
            'slug' => 'm11-scheduled-'.strtolower($suffix),
            'level' => 'Infant',
            'capacity' => 12,
            'color' => 'sage',
            'is_active' => true,
        ]);
        $student = Student::query()->create([
            'school_class_id' => $schoolClass->id,
            'code' => 'M11-SCHEDULED-STUDENT-'.$suffix,
            'name' => 'Scheduled Child '.$suffix,
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
            'name' => 'Language '.$suffix,
            'slug' => 'language-'.strtolower($suffix),
            'color' => 'sage',
            'sort_order' => 1,
        ]);
        $indicator = Indicator::query()->create([
            'development_area_id' => $area->id,
            'code' => 'M11-SCHEDULED-IND-'.$suffix,
            'sub_area' => 'Oral Language',
            'description' => 'Participates in oral language work.',
            'level' => 'Infant',
            'is_active' => true,
        ]);
        $session = ClassSession::query()->create([
            'school_class_id' => $schoolClass->id,
            'teacher_id' => $teacher->id,
            'session_date' => now()->toDateString(),
            'starts_at' => '08:00',
            'ends_at' => '09:00',
            'capacity' => 12,
            'status' => 'planned',
        ]);
        $session->students()->attach($student->id);

        return [$user, $teacher, $student, $indicator, $session];
    }
}
