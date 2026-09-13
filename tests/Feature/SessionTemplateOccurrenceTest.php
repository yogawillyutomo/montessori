<?php

namespace Tests\Feature;

use App\Models\ClassSession;
use App\Models\SessionOccurrence;
use App\Models\SessionTemplate;
use App\Models\User;
use App\Models\WeeklySchedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SessionTemplateOccurrenceTest extends TestCase
{
    use RefreshDatabase;

    public function test_seeded_legacy_schedules_and_sessions_have_one_to_one_target_mappings(): void
    {
        $this->seed();

        $this->assertSame(
            WeeklySchedule::query()->count(),
            SessionTemplate::query()->whereNotNull('legacy_weekly_schedule_id')->count()
        );

        $this->assertSame(
            ClassSession::query()->count(),
            SessionOccurrence::query()->whereNotNull('legacy_class_session_id')->count()
        );

        $schedule = WeeklySchedule::query()->firstOrFail();
        $template = SessionTemplate::query()
            ->where('legacy_weekly_schedule_id', $schedule->id)
            ->firstOrFail();

        $this->assertNull($template->environment_id);
        $this->assertNull($template->valid_from);
        $this->assertSame((int) $schedule->day_of_week, $template->day_of_week);
        $this->assertSame($schedule->starts_at->format('H:i'), $template->starts_at->format('H:i'));
        $this->assertSame($schedule->ends_at->format('H:i'), $template->ends_at->format('H:i'));
        $this->assertSame($schedule->room, $template->room);
        $this->assertSame($schedule->capacity, $template->capacity);
        $this->assertSame($schedule->school_class_id, $template->legacy_school_class_id);
        $this->assertSame($schedule->teacher_id, $template->legacy_teacher_id);
        $this->assertSame($schedule->topic, $template->legacy_topic);

        $session = ClassSession::query()->firstOrFail();
        $occurrence = SessionOccurrence::query()
            ->where('legacy_class_session_id', $session->id)
            ->firstOrFail();

        $this->assertNull($occurrence->environment_id);
        $this->assertSame($session->session_date->toDateString(), $occurrence->occurs_on->toDateString());
        $this->assertSame($session->starts_at->format('H:i'), $occurrence->starts_at->format('H:i'));
        $this->assertSame($session->ends_at->format('H:i'), $occurrence->ends_at->format('H:i'));
        $this->assertSame($session->room, $occurrence->room);
        $this->assertSame($session->capacity, $occurrence->capacity);
        $this->assertSame($session->status, $occurrence->status);
        $this->assertSame($session->school_class_id, $occurrence->legacy_school_class_id);
        $this->assertSame($session->teacher_id, $occurrence->legacy_teacher_id);
        $this->assertSame($session->topic, $occurrence->legacy_topic);

        if ($session->weekly_schedule_id) {
            $this->assertSame(
                SessionTemplate::query()
                    ->where('legacy_weekly_schedule_id', $session->weekly_schedule_id)
                    ->value('id'),
                $occurrence->session_template_id
            );
        }
    }

    public function test_legacy_updates_are_reflected_without_inventing_environment_or_validity(): void
    {
        $this->seed();

        $schedule = WeeklySchedule::query()->firstOrFail();
        $schedule->update([
            'room' => 'Environment Room B',
            'capacity' => 9,
            'topic' => 'Updated legacy topic',
        ]);

        $template = SessionTemplate::query()
            ->where('legacy_weekly_schedule_id', $schedule->id)
            ->firstOrFail();

        $this->assertSame('Environment Room B', $template->room);
        $this->assertSame(9, $template->capacity);
        $this->assertSame('Updated legacy topic', $template->legacy_topic);
        $this->assertNull($template->environment_id);
        $this->assertNull($template->valid_from);
        $this->assertNull($template->valid_until);

        $session = ClassSession::query()->firstOrFail();
        $admin = User::query()->where('email', 'ops@montessori.test')->firstOrFail();
        $session->update([
            'status' => 'completed',
            'closed_by' => $admin->id,
            'closed_at' => '2026-09-13 17:00:00',
            'room' => 'Environment Room C',
        ]);

        $occurrence = SessionOccurrence::query()
            ->where('legacy_class_session_id', $session->id)
            ->firstOrFail();

        $this->assertSame('completed', $occurrence->status);
        $this->assertSame($admin->id, $occurrence->completed_by);
        $this->assertSame('2026-09-13 17:00:00', $occurrence->completed_at->format('Y-m-d H:i:s'));
        $this->assertSame('Environment Room C', $occurrence->room);
        $this->assertNull($occurrence->environment_id);
    }

    public function test_session_without_legacy_weekly_schedule_still_maps_without_fake_template(): void
    {
        $this->seed();

        $source = ClassSession::query()->firstOrFail();
        $standalone = ClassSession::query()->create([
            'weekly_schedule_id' => null,
            'school_class_id' => $source->school_class_id,
            'teacher_id' => $source->teacher_id,
            'room' => 'Ad hoc Room',
            'capacity' => 4,
            'session_date' => '2026-09-20',
            'starts_at' => '10:00',
            'ends_at' => '11:00',
            'topic' => 'Ad hoc legacy session',
            'status' => 'planned',
        ]);

        $occurrence = SessionOccurrence::query()
            ->where('legacy_class_session_id', $standalone->id)
            ->firstOrFail();

        $this->assertNull($occurrence->session_template_id);
        $this->assertNull($occurrence->environment_id);
        $this->assertNull($occurrence->legacy_weekly_schedule_id);
        $this->assertSame('2026-09-20', $occurrence->occurs_on->toDateString());
        $this->assertSame('Ad hoc legacy session', $occurrence->legacy_topic);
    }

    public function test_deleting_legacy_records_preserves_target_lineage(): void
    {
        $this->seed();

        $session = ClassSession::query()
            ->doesntHave('observations')
            ->firstOrFail();
        $occurrenceId = SessionOccurrence::query()
            ->where('legacy_class_session_id', $session->id)
            ->value('id');

        $session->delete();

        $occurrence = SessionOccurrence::query()->findOrFail($occurrenceId);
        $this->assertNotNull($occurrence->legacy_deleted_at);
        $this->assertNotNull($occurrence->legacy_class_session_id);

        $schedule = WeeklySchedule::query()
            ->doesntHave('classSessions')
            ->first();

        if ($schedule) {
            $templateId = SessionTemplate::query()
                ->where('legacy_weekly_schedule_id', $schedule->id)
                ->value('id');

            $schedule->delete();

            $template = SessionTemplate::query()->findOrFail($templateId);
            $this->assertFalse($template->is_active);
            $this->assertNotNull($template->legacy_deleted_at);
            $this->assertNotNull($template->legacy_weekly_schedule_id);
        }
    }
}
