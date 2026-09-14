<?php

namespace Tests\Feature;

use App\Models\ClassSession;
use App\Models\SessionOccurrence;
use App\Models\User;
use App\Models\WeeklySchedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LegacySessionRollbackDeleteGuardTest extends TestCase
{
    use RefreshDatabase;

    public function test_legacy_rollback_delete_refuses_marked_attendance_evidence(): void
    {
        $this->seed();
        config()->set('montessori.session.write_source', 'legacy');

        [$session, $admin] = $this->createRollbackSession('2026-06-15');
        $attendance = $session->attendances()->firstOrFail();
        $attendance->forceFill([
            'status' => 'present',
            'marked_by' => $admin->id,
            'marked_at' => now(),
        ])->save();

        $this->actingAs($admin)
            ->delete(route('alpha.process.sessions.destroy', $session))
            ->assertSessionHasErrors('session');

        $this->assertDatabaseHas('class_sessions', ['id' => $session->id]);
        $this->assertDatabaseHas('attendances', [
            'id' => $attendance->id,
            'status' => 'present',
        ]);
        $this->assertNotNull(SessionOccurrence::query()
            ->where('legacy_class_session_id', $session->id)
            ->first());
    }

    public function test_legacy_rollback_delete_refuses_completed_session_even_when_attendance_is_unmarked(): void
    {
        $this->seed();
        config()->set('montessori.session.write_source', 'legacy');

        [$session, $admin] = $this->createRollbackSession('2026-06-15');

        $this->actingAs($admin)
            ->patch(route('alpha.process.sessions.close', $session), [
                'class_note' => 'Rollback close evidence boundary',
                'follow_up_recommendation' => null,
            ])
            ->assertRedirect();

        $session->refresh();
        $this->assertSame('completed', $session->status);

        $this->actingAs($admin)
            ->delete(route('alpha.process.sessions.destroy', $session))
            ->assertSessionHasErrors('session');

        $this->assertDatabaseHas('class_sessions', ['id' => $session->id]);
        $this->assertSame('completed', SessionOccurrence::query()
            ->where('legacy_class_session_id', $session->id)
            ->firstOrFail()
            ->status);
    }

    /** @return array{ClassSession, User} */
    private function createRollbackSession(string $date): array
    {
        $schedule = WeeklySchedule::query()
            ->where('day_of_week', 1)
            ->where('room', 'Ruang Sunny')
            ->firstOrFail();
        $admin = User::query()->where('email', 'ops@montessori.test')->firstOrFail();

        $this->actingAs($admin)
            ->post(route('alpha.sessions.create-from-schedule'), [
                'weekly_schedule_id' => $schedule->id,
                'session_date' => $date,
            ])
            ->assertRedirect();

        $session = ClassSession::query()
            ->where('weekly_schedule_id', $schedule->id)
            ->whereDate('session_date', $date)
            ->firstOrFail();

        return [$session, $admin];
    }
}
