<?php

namespace Tests\Feature;

use App\Models\ChildSessionBooking;
use App\Models\ClassSession;
use App\Models\SessionOccurrence;
use App\Models\SessionTemplate;
use App\Models\Student;
use App\Models\User;
use App\Models\WeeklySchedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class SessionDeleteEvidenceGuardTest extends TestCase
{
    use RefreshDatabase;

    public function test_session_delete_fails_closed_when_legacy_marked_attendance_is_orphaned_from_target_booking(): void
    {
        $this->seed();
        config()->set('montessori.session.write_source', 'target');

        $schedule = WeeklySchedule::query()
            ->where('day_of_week', 1)
            ->where('room', 'Ruang Sunny')
            ->firstOrFail();
        $admin = User::query()->where('email', 'ops@montessori.test')->firstOrFail();

        $this->actingAs($admin)
            ->post(route('alpha.sessions.create-from-schedule'), [
                'weekly_schedule_id' => $schedule->id,
                'session_date' => '2026-06-08',
            ])
            ->assertRedirect();

        $template = SessionTemplate::query()
            ->where('legacy_weekly_schedule_id', $schedule->id)
            ->firstOrFail();
        $occurrence = SessionOccurrence::query()
            ->where('session_template_id', $template->id)
            ->whereDate('occurs_on', '2026-06-08')
            ->firstOrFail();
        $legacy = ClassSession::query()->findOrFail($occurrence->legacy_class_session_id);
        $bookingIds = ChildSessionBooking::query()
            ->where('session_occurrence_id', $occurrence->id)
            ->pluck('id');
        $bookedStudentIds = ChildSessionBooking::query()
            ->where('session_occurrence_id', $occurrence->id)
            ->pluck('student_id');
        $orphanStudent = Student::query()
            ->whereNotIn('id', $bookedStudentIds)
            ->firstOrFail();

        DB::table('attendances')->insert([
            'class_session_id' => $legacy->id,
            'student_id' => $orphanStudent->id,
            'child_session_booking_id' => null,
            'status' => 'present',
            'note' => 'Legacy evidence must not be cascade-deleted.',
            'marked_by' => $admin->id,
            'marked_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->actingAs($admin)
            ->delete(route('alpha.process.sessions.destroy', $legacy))
            ->assertSessionHasErrors('session');

        $occurrence->refresh();
        $this->assertSame('planned', $occurrence->status);
        $this->assertNull($occurrence->legacy_deleted_at);
        $this->assertDatabaseHas('class_sessions', ['id' => $legacy->id]);
        $this->assertDatabaseHas('attendances', [
            'class_session_id' => $legacy->id,
            'student_id' => $orphanStudent->id,
            'status' => 'present',
        ]);
        $this->assertSame($bookingIds->count(), ChildSessionBooking::query()
            ->whereIn('id', $bookingIds)
            ->where('status', 'scheduled')
            ->count());
    }
}
