<?php

namespace Tests\Feature;

use App\Models\ChildSessionBooking;
use App\Models\ClassSession;
use App\Models\SessionOccurrence;
use App\Models\SessionTemplate;
use App\Models\User;
use App\Models\WeeklySchedule;
use App\Services\Scheduling\ChildBookingCancellationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SessionTerminalBookingGuardTest extends TestCase
{
    use RefreshDatabase;

    public function test_generic_roster_update_cannot_resurrect_terminal_booking_on_same_occurrence(): void
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

        $cancelled = ChildSessionBooking::query()
            ->where('session_occurrence_id', $occurrence->id)
            ->where('status', 'scheduled')
            ->firstOrFail();
        app(ChildBookingCancellationService::class)->cancel($cancelled, $admin, 'Explicit child cancellation');
        $cancelled->refresh();
        $this->assertSame('cancelled', $cancelled->status);

        $desiredStudentIds = ChildSessionBooking::query()
            ->where('session_occurrence_id', $occurrence->id)
            ->where('status', 'scheduled')
            ->pluck('student_id')
            ->map(fn ($id): int => (int) $id)
            ->push((int) $cancelled->student_id)
            ->unique()
            ->values()
            ->all();

        $this->actingAs($admin)
            ->patch(route('alpha.process.sessions.update', $legacy), [
                'school_class_id' => $occurrence->legacy_school_class_id,
                'teacher_id' => $occurrence->legacy_teacher_id,
                'room' => $occurrence->room,
                'capacity' => $occurrence->capacity,
                'session_date' => $occurrence->occurs_on->toDateString(),
                'starts_at' => $occurrence->starts_at->format('H:i'),
                'ends_at' => $occurrence->ends_at->format('H:i'),
                'topic' => $occurrence->legacy_topic,
                'status' => 'planned',
                'student_ids_present' => true,
                'student_ids' => $desiredStudentIds,
            ])
            ->assertSessionHasErrors('student_ids');

        $cancelled->refresh();
        $this->assertSame('cancelled', $cancelled->status);
        $this->assertSame(0, ChildSessionBooking::query()
            ->where('session_occurrence_id', $occurrence->id)
            ->where('student_id', $cancelled->student_id)
            ->where('status', 'scheduled')
            ->count());
        $this->assertSame(1, ChildSessionBooking::query()
            ->where('session_occurrence_id', $occurrence->id)
            ->where('student_id', $cancelled->student_id)
            ->where('status', 'cancelled')
            ->count());
    }
}
