<?php

namespace Tests\Feature;

use App\Models\ClassSession;
use App\Models\SessionOccurrence;
use App\Models\User;
use App\Models\WeeklySchedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
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
        $this->closeRollbackSession($session, $admin);

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

    public function test_legacy_rollback_structural_update_refuses_completed_session(): void
    {
        $this->seed();
        config()->set('montessori.session.write_source', 'legacy');

        [$session, $admin] = $this->createRollbackSession('2026-06-15');
        $this->closeRollbackSession($session, $admin);
        $session->refresh();
        $originalRoom = $session->room;

        $this->actingAs($admin)
            ->patch(route('alpha.process.sessions.update', $session), [
                'school_class_id' => $session->school_class_id,
                'teacher_id' => $session->teacher_id,
                'room' => 'Should Not Mutate',
                'capacity' => $session->capacity,
                'session_date' => $session->session_date->toDateString(),
                'starts_at' => $session->starts_at->format('H:i'),
                'ends_at' => $session->ends_at->format('H:i'),
                'topic' => $session->topic,
                'status' => 'planned',
            ])
            ->assertSessionHasErrors('status');

        $session->refresh();
        $this->assertSame('completed', $session->status);
        $this->assertSame($originalRoom, $session->room);
    }

    public function test_legacy_rollback_roster_update_refuses_removing_child_with_marked_attendance(): void
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
        $remainingStudentIds = $session->students()
            ->where('students.id', '!=', $attendance->student_id)
            ->pluck('students.id')
            ->map(fn ($id): int => (int) $id)
            ->all();

        $this->actingAs($admin)
            ->patch(route('alpha.process.sessions.update', $session), [
                'school_class_id' => $session->school_class_id,
                'teacher_id' => $session->teacher_id,
                'room' => $session->room,
                'capacity' => $session->capacity,
                'session_date' => $session->session_date->toDateString(),
                'starts_at' => $session->starts_at->format('H:i'),
                'ends_at' => $session->ends_at->format('H:i'),
                'topic' => $session->topic,
                'status' => 'planned',
                'student_ids_present' => true,
                'student_ids' => $remainingStudentIds,
            ])
            ->assertSessionHasErrors('student_ids');

        $this->assertTrue($session->students()->whereKey($attendance->student_id)->exists());
        $this->assertDatabaseHas('attendances', [
            'id' => $attendance->id,
            'status' => 'present',
        ]);
    }

    public function test_legacy_rollback_close_is_idempotent_for_completion_metadata(): void
    {
        $this->seed();
        config()->set('montessori.session.write_source', 'legacy');

        [$session, $admin] = $this->createRollbackSession('2026-06-15');
        $this->closeRollbackSession($session, $admin);
        $session->refresh();
        $firstClosedAt = $session->closed_at?->copy();
        $firstClosedBy = $session->closed_by;

        Carbon::setTestNow(now()->addHour());
        try {
            $this->actingAs($admin)
                ->patch(route('alpha.process.sessions.close', $session), [
                    'class_note' => 'Second close only updates compatibility note.',
                    'follow_up_recommendation' => null,
                ])
                ->assertRedirect();
        } finally {
            Carbon::setTestNow();
        }

        $session->refresh();
        $this->assertSame('completed', $session->status);
        $this->assertSame($firstClosedBy, $session->closed_by);
        $this->assertTrue($session->closed_at?->equalTo($firstClosedAt) ?? false);
        $this->assertSame('Second close only updates compatibility note.', $session->class_note);
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

    private function closeRollbackSession(ClassSession $session, User $admin): void
    {
        $this->actingAs($admin)
            ->patch(route('alpha.process.sessions.close', $session), [
                'class_note' => 'Rollback close evidence boundary',
                'follow_up_recommendation' => null,
            ])
            ->assertRedirect();
    }
}
