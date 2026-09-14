<?php

namespace Tests\Feature;

use App\Models\ChildSessionBooking;
use App\Models\ClassSession;
use App\Models\SessionOccurrence;
use App\Models\SessionTemplate;
use App\Models\User;
use App\Models\WeeklySchedule;
use App\Services\Scheduling\SchedulingReadModelService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class TargetSchedulingReadCutoverTest extends TestCase
{
    use RefreshDatabase;

    public function test_process_schedule_page_reads_target_template_when_legacy_mirror_is_stale(): void
    {
        $this->seed();

        $admin = User::query()->where('role', 'admin')->firstOrFail();
        $legacy = WeeklySchedule::query()->firstOrFail();
        $template = SessionTemplate::query()
            ->where('legacy_weekly_schedule_id', $legacy->id)
            ->firstOrFail();

        DB::table('session_templates')->where('id', $template->id)->update([
            'room' => 'Target Authority Room',
            'legacy_topic' => 'Target Authority Topic',
        ]);
        DB::table('weekly_schedules')->where('id', $legacy->id)->update([
            'room' => 'STALE LEGACY ROOM',
            'topic' => 'STALE LEGACY TOPIC',
        ]);

        $this->actingAs($admin)
            ->get(route('alpha.process.schedules'))
            ->assertOk()
            ->assertSee('Target Authority Room')
            ->assertSee('Target Authority Topic')
            ->assertDontSee('STALE LEGACY ROOM')
            ->assertDontSee('STALE LEGACY TOPIC');
    }

    public function test_target_schedule_membership_comes_from_recurring_schedule_not_legacy_pivot(): void
    {
        $this->seed();

        $legacy = WeeklySchedule::query()->with('students')->firstOrFail();
        $student = $legacy->students->firstOrFail();

        DB::table('student_weekly_schedule')
            ->where('weekly_schedule_id', $legacy->id)
            ->where('student_id', $student->id)
            ->delete();

        $schedule = app(SchedulingReadModelService::class)
            ->schedules([$legacy->school_class_id], null)
            ->firstWhere('id', $legacy->id);

        $this->assertNotNull($schedule);
        $this->assertTrue($schedule->students->contains('id', $student->id));
    }

    public function test_target_session_values_and_membership_are_authoritative_for_process_read_model(): void
    {
        $this->seed();

        $legacy = ClassSession::query()->with('students')->firstOrFail();
        $occurrence = SessionOccurrence::query()
            ->where('legacy_class_session_id', $legacy->id)
            ->firstOrFail();
        $student = $legacy->students->firstOrFail();
        $booking = ChildSessionBooking::query()
            ->where('session_occurrence_id', $occurrence->id)
            ->where('student_id', $student->id)
            ->firstOrFail();

        DB::table('session_occurrences')->where('id', $occurrence->id)->update([
            'room' => 'Target Occurrence Room',
            'legacy_topic' => 'Target Occurrence Topic',
        ]);
        DB::table('class_sessions')->where('id', $legacy->id)->update([
            'room' => 'STALE SESSION ROOM',
            'topic' => 'STALE SESSION TOPIC',
        ]);
        DB::table('class_session_student')
            ->where('class_session_id', $legacy->id)
            ->where('student_id', $student->id)
            ->delete();

        $session = app(SchedulingReadModelService::class)
            ->sessions([$legacy->school_class_id], null)
            ->firstWhere('id', $legacy->id);

        $this->assertNotNull($session);
        $this->assertSame('Target Occurrence Room', $session->room);
        $this->assertSame('Target Occurrence Topic', $session->topic);
        $this->assertTrue($session->students->contains('id', $student->id));
        $this->assertSame($booking->id, ChildSessionBooking::query()->findOrFail($booking->id)->id);
    }

    public function test_read_source_can_be_rolled_back_to_legacy_without_data_rewrite(): void
    {
        $this->seed();

        $legacy = WeeklySchedule::query()->firstOrFail();
        $template = SessionTemplate::query()
            ->where('legacy_weekly_schedule_id', $legacy->id)
            ->firstOrFail();

        DB::table('session_templates')->where('id', $template->id)->update(['room' => 'TARGET ROOM']);
        DB::table('weekly_schedules')->where('id', $legacy->id)->update(['room' => 'LEGACY ROLLBACK ROOM']);

        config()->set('montessori.scheduling.read_source', 'legacy');

        $schedule = app(SchedulingReadModelService::class)
            ->schedules([$legacy->school_class_id], null)
            ->firstWhere('id', $legacy->id);

        $this->assertNotNull($schedule);
        $this->assertSame('LEGACY ROLLBACK ROOM', $schedule->room);
    }
}
