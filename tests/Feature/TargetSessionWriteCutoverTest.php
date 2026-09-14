<?php

namespace Tests\Feature;

use App\Models\Attendance;
use App\Models\ChildSessionBooking;
use App\Models\ClassSession;
use App\Models\RecurringSchedule;
use App\Models\SessionOccurrence;
use App\Models\SessionTemplate;
use App\Models\Teacher;
use App\Models\User;
use App\Models\WeeklySchedule;
use App\Services\Migration\LegacyContractionReadinessService;
use App\Services\Scheduling\ChildBookingCancellationService;
use App\Services\Scheduling\SessionOccurrenceCancellationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class TargetSessionWriteCutoverTest extends TestCase
{
    use RefreshDatabase;

    public function test_create_writes_canonical_occurrence_and_bookings_then_explicit_unmarked_compatibility_rows(): void
    {
        $this->seed();
        [$schedule, $template, $occurrence, $legacy] = $this->createTargetSession('2026-06-08');

        $expectedStudentIds = RecurringSchedule::query()
            ->where('session_template_id', $template->id)
            ->where('is_active', true)
            ->whereNull('legacy_deleted_at')
            ->pluck('student_id')
            ->map(fn ($id): int => (int) $id)
            ->all();
        $bookings = ChildSessionBooking::query()
            ->where('session_occurrence_id', $occurrence->id)
            ->where('status', 'scheduled')
            ->get();

        $this->assertSame($schedule->id, (int) $occurrence->legacy_weekly_schedule_id);
        $this->assertSame($legacy->id, (int) $occurrence->legacy_class_session_id);
        $this->assertEqualsCanonicalizing($expectedStudentIds, $bookings->pluck('student_id')->map(fn ($id): int => (int) $id)->all());

        foreach ($bookings as $booking) {
            $this->assertNotNull($booking->legacy_class_session_student_id);
            $this->assertDatabaseHas('class_session_student', [
                'id' => $booking->legacy_class_session_student_id,
                'class_session_id' => $legacy->id,
                'student_id' => $booking->student_id,
            ]);
            $this->assertDatabaseHas('attendances', [
                'class_session_id' => $legacy->id,
                'student_id' => $booking->student_id,
                'child_session_booking_id' => $booking->id,
                'status' => 'unmarked',
                'marked_at' => null,
            ]);
        }

        $this->assertReconciled();
        $this->artisan('legacy:reconcile')->assertExitCode(0);
    }

    public function test_stale_legacy_session_and_orphan_pivot_do_not_override_or_resurrect_target_state(): void
    {
        $this->seed();
        [, , $occurrence, $legacy] = $this->createTargetSession('2026-06-08');
        $admin = $this->admin();
        $cancelled = ChildSessionBooking::query()
            ->where('session_occurrence_id', $occurrence->id)
            ->where('status', 'scheduled')
            ->firstOrFail();

        app(ChildBookingCancellationService::class)->cancel($cancelled, $admin, 'Roster correction');
        $cancelled->refresh();
        $this->assertSame('cancelled', $cancelled->status);
        $this->assertDatabaseMissing('class_session_student', [
            'id' => $cancelled->legacy_class_session_student_id,
        ]);

        DB::table('class_session_student')->insert([
            'class_session_id' => $legacy->id,
            'student_id' => $cancelled->student_id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('class_sessions')->where('id', $legacy->id)->update([
            'room' => 'STALE LEGACY ROOM',
            'topic' => 'STALE LEGACY TOPIC',
        ]);

        $payload = $this->updatePayload($occurrence, [
            'room' => 'Canonical Target Room',
            'topic' => 'Canonical Target Topic',
        ]);

        $this->actingAs($admin)
            ->patch(route('alpha.process.sessions.update', $legacy), $payload)
            ->assertRedirect();

        $occurrence->refresh();
        $cancelled->refresh();
        $legacy->refresh();

        $this->assertSame('Canonical Target Room', $occurrence->room);
        $this->assertSame('Canonical Target Topic', $occurrence->legacy_topic);
        $this->assertSame('Canonical Target Room', $legacy->room);
        $this->assertSame('Canonical Target Topic', $legacy->topic);
        $this->assertSame('cancelled', $cancelled->status);
        $this->assertDatabaseMissing('class_session_student', [
            'class_session_id' => $legacy->id,
            'student_id' => $cancelled->student_id,
        ]);

        $this->assertReconciled();
    }

    public function test_target_conflict_guard_sees_target_only_occurrence_that_legacy_validation_cannot_see(): void
    {
        $this->seed();
        [, , $occurrence, $legacy] = $this->createTargetSession('2026-06-08');

        SessionOccurrence::query()->create([
            'session_template_id' => null,
            'environment_id' => null,
            'occurs_on' => $occurrence->occurs_on->toDateString(),
            'starts_at' => $occurrence->starts_at->format('H:i'),
            'ends_at' => $occurrence->ends_at->format('H:i'),
            'capacity' => 5,
            'room' => 'Target Only Conflict',
            'status' => 'planned',
            'legacy_school_class_id' => $occurrence->legacy_school_class_id,
            'legacy_teacher_id' => null,
            'legacy_topic' => 'Invisible to legacy validator',
        ]);

        DB::table('class_sessions')->where('id', $legacy->id)->update([
            'room' => 'STALE NON CONFLICT',
        ]);

        $this->actingAs($this->admin())
            ->patch(route('alpha.process.sessions.update', $legacy), $this->updatePayload($occurrence))
            ->assertSessionHasErrors('starts_at');
    }

    public function test_close_mutates_occurrence_and_preserves_unmarked_attendance_semantics(): void
    {
        $this->seed();
        [, , $occurrence, $legacy] = $this->createTargetSession('2026-06-08');
        $admin = $this->admin();

        $this->actingAs($admin)
            ->patch(route('alpha.process.sessions.close', $legacy), [
                'class_note' => 'Closed with delayed attendance allowed.',
                'follow_up_recommendation' => null,
            ])
            ->assertRedirect();

        $occurrence->refresh();
        $legacy->refresh();
        $bookingIds = ChildSessionBooking::query()
            ->where('session_occurrence_id', $occurrence->id)
            ->pluck('id');

        $this->assertSame('completed', $occurrence->status);
        $this->assertSame($admin->id, $occurrence->completed_by);
        $this->assertNotNull($occurrence->completed_at);
        $this->assertSame('completed', $legacy->status);
        $this->assertSame($admin->id, $legacy->closed_by);
        $this->assertSame($bookingIds->count(), Attendance::query()
            ->whereIn('child_session_booking_id', $bookingIds)
            ->where('status', 'unmarked')
            ->whereNull('marked_at')
            ->count());
        $this->assertSame(0, Attendance::query()
            ->whereIn('child_session_booking_id', $bookingIds)
            ->where('status', 'absent')
            ->count());
    }

    public function test_school_cancellation_is_target_first_and_keeps_cancelled_membership_history(): void
    {
        $this->seed();
        [, , $occurrence, $legacy] = $this->createTargetSession('2026-06-08');
        $admin = $this->admin();
        $bookingIds = ChildSessionBooking::query()
            ->where('session_occurrence_id', $occurrence->id)
            ->pluck('id');

        app(SessionOccurrenceCancellationService::class)->cancel(
            $occurrence,
            $admin,
            'School operational closure',
        );

        $occurrence->refresh();
        $legacy->refresh();

        $this->assertSame('cancelled', $occurrence->status);
        $this->assertSame('cancelled', $legacy->status);
        $this->assertSame($bookingIds->count(), ChildSessionBooking::query()
            ->whereIn('id', $bookingIds)
            ->where('status', 'session_cancelled')
            ->whereNull('active_on')
            ->count());
        $this->assertSame($bookingIds->count(), DB::table('class_session_student')
            ->where('class_session_id', $legacy->id)
            ->count());
        $this->assertSame($bookingIds->count(), Attendance::query()
            ->whereIn('child_session_booking_id', $bookingIds)
            ->where('status', 'unmarked')
            ->whereNull('marked_at')
            ->count());
    }

    public function test_target_delete_tombstones_pristine_history_and_removes_only_compatibility_handle(): void
    {
        $this->seed();
        [, , $occurrence, $legacy] = $this->createTargetSession('2026-06-08');
        $occurrenceId = $occurrence->id;
        $legacyId = $legacy->id;
        $bookingIds = ChildSessionBooking::query()
            ->where('session_occurrence_id', $occurrenceId)
            ->pluck('id');

        $this->actingAs($this->admin())
            ->delete(route('alpha.process.sessions.destroy', $legacy))
            ->assertRedirect();

        $this->assertDatabaseMissing('class_sessions', ['id' => $legacyId]);
        $occurrence = SessionOccurrence::query()->findOrFail($occurrenceId);
        $this->assertSame('cancelled', $occurrence->status);
        $this->assertNotNull($occurrence->legacy_deleted_at);
        $this->assertSame($bookingIds->count(), ChildSessionBooking::query()
            ->whereIn('id', $bookingIds)
            ->where('status', 'cancelled')
            ->whereNotNull('legacy_deleted_at')
            ->count());
    }

    public function test_target_schedule_lineage_prevents_stale_legacy_teacher_authorization_bypass(): void
    {
        $this->seed();
        $schedule = $this->schedule();
        $template = SessionTemplate::query()
            ->where('legacy_weekly_schedule_id', $schedule->id)
            ->firstOrFail();
        $mira = User::query()->where('email', 'mira@montessori.test')->firstOrFail();
        $miraTeacher = Teacher::query()->where('user_id', $mira->id)->firstOrFail();

        $this->assertNotSame($miraTeacher->id, (int) $template->legacy_teacher_id);
        DB::table('weekly_schedules')->where('id', $schedule->id)->update([
            'teacher_id' => $miraTeacher->id,
        ]);

        $this->actingAs($mira)
            ->post(route('alpha.sessions.create-from-schedule'), [
                'weekly_schedule_id' => $schedule->id,
                'session_date' => '2026-06-08',
            ])
            ->assertForbidden();

        $this->assertDatabaseMissing('session_occurrences', [
            'session_template_id' => $template->id,
            'occurs_on' => '2026-06-08',
        ]);
    }

    public function test_session_write_source_can_roll_back_to_legacy_and_bridge_target_state(): void
    {
        $this->seed();
        config()->set('montessori.session.write_source', 'legacy');
        $schedule = $this->schedule();

        $this->actingAs($this->admin())
            ->post(route('alpha.sessions.create-from-schedule'), [
                'weekly_schedule_id' => $schedule->id,
                'session_date' => '2026-06-15',
            ])
            ->assertRedirect();

        $legacy = ClassSession::query()
            ->where('weekly_schedule_id', $schedule->id)
            ->whereDate('session_date', '2026-06-15')
            ->firstOrFail();
        $occurrence = SessionOccurrence::query()
            ->where('legacy_class_session_id', $legacy->id)
            ->firstOrFail();

        $this->assertSame('planned', $occurrence->status);
        $this->assertGreaterThan(0, ChildSessionBooking::query()
            ->where('session_occurrence_id', $occurrence->id)
            ->where('status', 'scheduled')
            ->count());

        $this->actingAs($this->admin())
            ->patch(route('alpha.process.sessions.update', $legacy), [
                'school_class_id' => $legacy->school_class_id,
                'teacher_id' => $legacy->teacher_id,
                'room' => 'Legacy Rollback Room',
                'capacity' => $legacy->capacity,
                'session_date' => $legacy->session_date->toDateString(),
                'starts_at' => $legacy->starts_at->format('H:i'),
                'ends_at' => $legacy->ends_at->format('H:i'),
                'topic' => 'Legacy Rollback Update',
                'status' => 'planned',
            ])
            ->assertRedirect();

        $occurrence->refresh();
        $this->assertSame('Legacy Rollback Room', $occurrence->room);
        $this->assertSame('Legacy Rollback Update', $occurrence->legacy_topic);

        $this->actingAs($this->admin())
            ->patch(route('alpha.process.sessions.close', $legacy), [
                'class_note' => 'Rollback close path',
                'follow_up_recommendation' => null,
            ])
            ->assertRedirect();

        $occurrence->refresh();
        $this->assertSame('completed', $occurrence->status);
        $this->assertNotNull($occurrence->completed_at);
    }

    /** @return array{WeeklySchedule, SessionTemplate, SessionOccurrence, ClassSession} */
    private function createTargetSession(string $date): array
    {
        config()->set('montessori.session.write_source', 'target');
        $schedule = $this->schedule();
        $admin = $this->admin();

        $this->actingAs($admin)
            ->post(route('alpha.sessions.create-from-schedule'), [
                'weekly_schedule_id' => $schedule->id,
                'session_date' => $date,
            ])
            ->assertRedirect();

        $template = SessionTemplate::query()
            ->where('legacy_weekly_schedule_id', $schedule->id)
            ->firstOrFail();
        $occurrence = SessionOccurrence::query()
            ->where('session_template_id', $template->id)
            ->whereDate('occurs_on', $date)
            ->firstOrFail();
        $legacy = ClassSession::query()->findOrFail($occurrence->legacy_class_session_id);

        return [$schedule, $template, $occurrence, $legacy];
    }

    /** @param array<string, mixed> $overrides @return array<string, mixed> */
    private function updatePayload(SessionOccurrence $occurrence, array $overrides = []): array
    {
        return [
            'school_class_id' => $occurrence->legacy_school_class_id,
            'teacher_id' => $occurrence->legacy_teacher_id,
            'room' => $occurrence->room,
            'capacity' => $occurrence->capacity,
            'session_date' => $occurrence->occurs_on->toDateString(),
            'starts_at' => $occurrence->starts_at->format('H:i'),
            'ends_at' => $occurrence->ends_at->format('H:i'),
            'topic' => $occurrence->legacy_topic,
            'status' => 'planned',
            ...$overrides,
        ];
    }

    private function schedule(): WeeklySchedule
    {
        return WeeklySchedule::query()
            ->where('day_of_week', 1)
            ->where('room', 'Ruang Sunny')
            ->firstOrFail();
    }

    private function admin(): User
    {
        return User::query()->where('email', 'ops@montessori.test')->firstOrFail();
    }

    private function assertReconciled(): void
    {
        $this->linkSeededMarkedAttendancesToBookings();
        $readiness = app(LegacyContractionReadinessService::class);
        $report = $readiness->report();

        $this->assertTrue(
            $readiness->dataReady(),
            json_encode($report, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR),
        );
    }

    private function linkSeededMarkedAttendancesToBookings(): void
    {
        $attendances = DB::table('attendances')
            ->whereNotNull('marked_at')
            ->whereNull('child_session_booking_id')
            ->get(['id', 'class_session_id', 'student_id']);

        foreach ($attendances as $attendance) {
            $bookingId = DB::table('child_session_bookings')
                ->where('legacy_class_session_id', $attendance->class_session_id)
                ->where('student_id', $attendance->student_id)
                ->value('id');

            $this->assertNotNull($bookingId, "Seeded attendance {$attendance->id} must have a target booking.");
            DB::table('attendances')->where('id', $attendance->id)->update([
                'child_session_booking_id' => $bookingId,
            ]);
        }
    }
}
