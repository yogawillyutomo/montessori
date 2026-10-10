<?php

namespace Tests\Feature;

use App\Models\ChildSessionBooking;
use App\Models\ClassSession;
use App\Models\SessionOccurrence;
use App\Models\SessionTemplate;
use App\Models\User;
use App\Models\WeeklySchedule;
use App\Services\Scheduling\SessionWriteService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class SessionCompatibilitySelfHealingTest extends TestCase
{
    use RefreshDatabase;

    public function test_idempotent_target_create_self_heals_missing_legacy_session_and_booking_mirrors(): void
    {
        $this->seed();
        config()->set('montessori.session.write_source', 'target');

        $schedule = WeeklySchedule::query()
            ->where('day_of_week', 1)
            ->where('room', 'Ruang Sunny')
            ->firstOrFail();
        $admin = User::query()->where('email', 'ops@montessori.test')->firstOrFail();
        $writes = app(SessionWriteService::class);

        $legacy = $writes->createFromSchedule($schedule, '2026-06-08', $admin);
        $template = SessionTemplate::query()
            ->where('legacy_weekly_schedule_id', $schedule->id)
            ->firstOrFail();
        $occurrence = SessionOccurrence::query()
            ->where('session_template_id', $template->id)
            ->whereDate('occurs_on', '2026-06-08')
            ->firstOrFail();
        $bookings = ChildSessionBooking::query()
            ->where('session_occurrence_id', $occurrence->id)
            ->where('status', 'scheduled')
            ->get();

        $this->assertNotEmpty($bookings);
        $oldLegacyId = (int) $legacy->id;
        $bookingIds = $bookings->pluck('id');

        DB::table('attendances')
            ->where('class_session_id', $oldLegacyId)
            ->whereIn('child_session_booking_id', $bookingIds)
            ->delete();
        DB::table('class_session_student')
            ->where('class_session_id', $oldLegacyId)
            ->delete();
        DB::table('class_sessions')->where('id', $oldLegacyId)->delete();

        $this->assertDatabaseMissing('class_sessions', ['id' => $oldLegacyId]);
        $this->assertSame($oldLegacyId, (int) $occurrence->legacy_class_session_id);
        foreach ($bookings as $booking) {
            $this->assertSame($oldLegacyId, (int) $booking->legacy_class_session_id);
        }

        $healedLegacy = $writes->createFromSchedule($schedule, '2026-06-08', $admin);
        $occurrence->refresh();
        $bookings = ChildSessionBooking::query()
            ->whereIn('id', $bookingIds)
            ->get();

        $newLegacyId = (int) $healedLegacy->id;
        $this->assertNotSame($oldLegacyId, $newLegacyId);
        $this->assertSame($newLegacyId, (int) $occurrence->legacy_class_session_id);
        $this->assertDatabaseHas('class_sessions', [
            'id' => $newLegacyId,
            'session_date' => '2026-06-08',
        ]);

        foreach ($bookings as $booking) {
            $this->assertSame($newLegacyId, (int) $booking->legacy_class_session_id);
            $this->assertNotNull($booking->legacy_class_session_student_id);
            $this->assertDatabaseHas('class_session_student', [
                'id' => $booking->legacy_class_session_student_id,
                'class_session_id' => $newLegacyId,
                'student_id' => $booking->student_id,
            ]);
            $this->assertDatabaseHas('attendances', [
                'class_session_id' => $newLegacyId,
                'student_id' => $booking->student_id,
                'child_session_booking_id' => $booking->id,
                'status' => 'unmarked',
                'marked_at' => null,
            ]);
        }

        $this->assertSame(
            $bookings->count(),
            DB::table('class_session_student')->where('class_session_id', $newLegacyId)->count(),
        );
        $this->assertSame(
            $bookings->count(),
            DB::table('attendances')->where('class_session_id', $newLegacyId)->count(),
        );
        $this->assertNull(ClassSession::query()->find($oldLegacyId));
    }
}
