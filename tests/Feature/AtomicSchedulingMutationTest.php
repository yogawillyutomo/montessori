<?php

namespace Tests\Feature;

use App\Models\SessionTemplate;
use App\Models\Student;
use App\Models\User;
use App\Models\WeeklySchedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AtomicSchedulingMutationTest extends TestCase
{
    use RefreshDatabase;

    public function test_schedule_creation_rolls_back_when_later_participant_conflicts(): void
    {
        $this->seed();

        $source = WeeklySchedule::query()
            ->with('students')
            ->where('is_active', true)
            ->firstOrFail();
        $conflictingStudent = $source->students->firstOrFail();
        $validStudent = Student::query()
            ->whereDoesntHave('weeklySchedules', function ($query) use ($source): void {
                $query
                    ->where('day_of_week', $source->day_of_week)
                    ->where('is_active', true);
            })
            ->firstOrFail();
        $admin = User::query()->where('email', 'ops@montessori.test')->firstOrFail();

        $topic = 'Atomic rollback schedule';

        $this->actingAs($admin)
            ->from('/process/schedules')
            ->post(route('alpha.process.schedules.store'), [
                'school_class_id' => $source->school_class_id,
                'teacher_id' => $source->teacher_id,
                'room' => 'Atomic Test Room',
                'capacity' => 8,
                'day_of_week' => $source->day_of_week,
                'starts_at' => '23:00',
                'ends_at' => '23:30',
                'topic' => $topic,
                'student_ids' => [$validStudent->id, $conflictingStudent->id],
            ])
            ->assertRedirect('/process/schedules')
            ->assertSessionHasErrors('student_ids');

        $this->assertDatabaseMissing('weekly_schedules', ['topic' => $topic]);
        $this->assertFalse(SessionTemplate::query()->where('legacy_topic', $topic)->exists());
    }
}
