<?php

namespace Tests\Feature;

use App\Models\AttendanceAudit;
use App\Models\ClassSession;
use App\Models\User;
use App\Models\WeeklySchedule;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AttendanceSemanticsTest extends TestCase
{
    use RefreshDatabase;

    public function test_new_session_attendance_is_stored_as_explicit_unmarked(): void
    {
        $this->seed();
        $this->actingAs($this->admin());

        $session = $this->createMondaySession();
        $attendanceIds = $session->attendances()->pluck('id');

        $this->assertSame($session->students()->count(), $session->attendances()->count());
        $this->assertSame(
            $session->students()->count(),
            $session->attendances()->where('status', 'unmarked')->whereNull('marked_at')->count()
        );
        $this->assertSame(0, $session->attendances()->where('status', 'present')->count());
        $this->assertSame(0, AttendanceAudit::query()->whereIn('attendance_id', $attendanceIds)->count());
    }

    public function test_mark_and_reset_preserves_explicit_state_and_audit_history(): void
    {
        $this->seed();
        $admin = $this->admin();
        $this->actingAs($admin);

        $session = $this->createMondaySession();
        $studentIds = $session->students()->pluck('students.id')->map(fn ($id): int => (int) $id)->all();
        $payload = collect($studentIds)
            ->mapWithKeys(fn (int $studentId): array => [$studentId => ['status' => 'unmarked', 'note' => null]])
            ->all();

        $this->patch(route('alpha.process.sessions.attendance', $session), [
            'attendance_action' => 'all_present',
            'attendance' => $payload,
        ])->assertRedirect()->assertSessionDoesntHaveErrors();

        $firstAttendance = $session->attendances()->where('student_id', $studentIds[0])->firstOrFail();
        $this->assertSame('present', $firstAttendance->status);
        $this->assertNotNull($firstAttendance->marked_at);

        $this->assertDatabaseHas('attendance_audits', [
            'attendance_id' => $firstAttendance->id,
            'old_status' => 'unmarked',
            'new_status' => 'present',
            'changed_by' => $admin->id,
        ]);

        $this->patch(route('alpha.process.sessions.attendance', $session), [
            'attendance_action' => 'reset',
            'attendance' => $payload,
        ])->assertRedirect()->assertSessionDoesntHaveErrors();

        $firstAttendance->refresh();
        $this->assertSame('unmarked', $firstAttendance->status);
        $this->assertNull($firstAttendance->marked_at);
        $this->assertNull($firstAttendance->marked_by);

        $this->assertDatabaseHas('attendance_audits', [
            'attendance_id' => $firstAttendance->id,
            'old_status' => 'present',
            'new_status' => 'unmarked',
            'changed_by' => $admin->id,
        ]);
    }

    public function test_session_can_close_unmarked_and_attendance_can_be_recorded_days_later(): void
    {
        $this->seed();
        $admin = $this->admin();
        $this->actingAs($admin);

        $session = $this->createMondaySession();
        $studentId = (int) $session->students()->value('students.id');

        $this->from('/process/attendance')
            ->patch(route('alpha.process.sessions.close', $session), [
                'class_note' => 'Sesi selesai, presensi akan diisi kemudian.',
                'follow_up_recommendation' => null,
            ])
            ->assertRedirect('/process/attendance')
            ->assertSessionDoesntHaveErrors();

        $session->refresh();
        $this->assertSame('completed', $session->status);
        $this->assertSame($session->students()->count(), $session->attendanceRecap()['unmarked']);

        Carbon::setTestNow('2026-06-10 18:30:00');

        try {
            $payload = $session->students()
                ->pluck('students.id')
                ->mapWithKeys(fn ($id): array => [
                    (int) $id => [
                        'status' => (int) $id === $studentId ? 'present' : 'unmarked',
                        'note' => null,
                    ],
                ])
                ->all();

            $this->patch(route('alpha.process.sessions.attendance', $session), [
                'attendance_action' => 'save',
                'attendance' => $payload,
            ])->assertRedirect()->assertSessionDoesntHaveErrors();
        } finally {
            Carbon::setTestNow();
        }

        $attendance = $session->attendances()->where('student_id', $studentId)->firstOrFail();
        $this->assertSame('present', $attendance->status);
        $this->assertSame('2026-06-10', $attendance->marked_at->toDateString());
        $this->assertSame('2026-06-08', $session->session_date->toDateString());

        $this->assertDatabaseHas('attendance_audits', [
            'attendance_id' => $attendance->id,
            'old_status' => 'unmarked',
            'new_status' => 'present',
            'changed_by' => $admin->id,
        ]);
    }

    private function admin(): User
    {
        return User::query()->where('email', 'ops@montessori.test')->firstOrFail();
    }

    private function createMondaySession(): ClassSession
    {
        $schedule = WeeklySchedule::query()->with('students')->where('day_of_week', 1)->firstOrFail();

        $this->from('/process/attendance')
            ->post(route('alpha.sessions.create-from-schedule'), [
                'weekly_schedule_id' => $schedule->id,
                'session_date' => '2026-06-08',
            ])
            ->assertRedirect('/process/attendance')
            ->assertSessionDoesntHaveErrors();

        return ClassSession::query()
            ->where('weekly_schedule_id', $schedule->id)
            ->whereDate('session_date', '2026-06-08')
            ->with(['students', 'attendances'])
            ->firstOrFail();
    }
}
