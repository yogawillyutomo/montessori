<?php

namespace Tests\Feature;

use App\Services\Migration\LegacyContractionReadinessService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class LegacyContractionReadinessTest extends TestCase
{
    use RefreshDatabase;

    public function test_reconciled_seeded_data_passes_the_data_readiness_gate(): void
    {
        $this->seed();
        $this->linkSeededMarkedAttendancesToBookings();

        $readiness = app(LegacyContractionReadinessService::class);
        $report = $readiness->report();

        $this->assertTrue($readiness->dataReady(), json_encode($report, JSON_PRETTY_PRINT));

        foreach ($report as $check) {
            $this->assertTrue($check['table_ready']);
            $this->assertSame(0, $check['missing_total']);
            $this->assertSame(0, $check['duplicate_total']);
            $this->assertSame(0, $check['mismatch_total']);
        }

        $this->artisan('legacy:reconcile')
            ->expectsOutputToContain('DATA RECONCILIATION: READY.')
            ->assertExitCode(0);
    }

    public function test_gate_fails_closed_when_a_legacy_schedule_has_no_target_mapping(): void
    {
        $this->seed();
        $this->linkSeededMarkedAttendancesToBookings();

        $schedule = (array) DB::table('weekly_schedules')->orderBy('id')->first();
        unset($schedule['id']);
        $schedule['topic'] = 'Unbridged reconciliation test row';
        $schedule['created_at'] = now();
        $schedule['updated_at'] = now();

        DB::table('weekly_schedules')->insert($schedule);

        $report = app(LegacyContractionReadinessService::class)->report();

        $this->assertSame(1, $report['weekly_schedule_templates']['missing_total']);

        $this->artisan('legacy:reconcile')
            ->expectsOutputToContain('DATA RECONCILIATION: NOT READY.')
            ->assertExitCode(1);
    }

    public function test_gate_detects_identity_drift_even_when_legacy_mapping_id_exists(): void
    {
        $this->seed();
        $this->linkSeededMarkedAttendancesToBookings();

        $template = DB::table('session_templates')
            ->whereNotNull('legacy_weekly_schedule_id')
            ->orderBy('id')
            ->first();

        $otherTeacherId = DB::table('teachers')
            ->where('id', '!=', $template->legacy_teacher_id)
            ->orderBy('id')
            ->value('id');

        $this->assertNotNull($otherTeacherId);

        DB::table('session_templates')
            ->where('id', $template->id)
            ->update(['legacy_teacher_id' => $otherTeacherId]);

        $readiness = app(LegacyContractionReadinessService::class);
        $report = $readiness->report();

        $this->assertSame(0, $report['weekly_schedule_templates']['missing_total']);
        $this->assertSame(1, $report['weekly_schedule_templates']['mismatch_total']);
        $this->assertFalse($readiness->dataReady());

        $this->artisan('legacy:reconcile')->assertExitCode(1);
    }

    public function test_gate_treats_equivalent_session_date_serializations_as_the_same_calendar_date(): void
    {
        $this->seed();
        $this->linkSeededMarkedAttendancesToBookings();

        $legacy = DB::table('class_sessions')->orderBy('id')->first();
        $this->assertNotNull($legacy);

        $occurrenceId = DB::table('session_occurrences')
            ->where('legacy_class_session_id', $legacy->id)
            ->value('id');
        $this->assertNotNull($occurrenceId);

        DB::table('session_occurrences')
            ->where('id', $occurrenceId)
            ->update([
                'occurs_on' => substr((string) $legacy->session_date, 0, 10).' 00:00:00',
            ]);

        $report = app(LegacyContractionReadinessService::class)->report();

        $this->assertSame(0, $report['session_occurrences']['mismatch_total']);
    }

    public function test_gate_detects_true_session_occurrence_date_drift(): void
    {
        $this->seed();
        $this->linkSeededMarkedAttendancesToBookings();

        $legacy = DB::table('class_sessions')->orderBy('id')->first();
        $this->assertNotNull($legacy);

        $occurrenceId = DB::table('session_occurrences')
            ->where('legacy_class_session_id', $legacy->id)
            ->value('id');
        $this->assertNotNull($occurrenceId);

        DB::table('session_occurrences')
            ->where('id', $occurrenceId)
            ->update(['occurs_on' => '2099-12-31']);

        $readiness = app(LegacyContractionReadinessService::class);
        $report = $readiness->report();

        $this->assertSame(1, $report['session_occurrences']['mismatch_total']);
        $this->assertFalse($readiness->dataReady());
    }

    public function test_gate_blocks_marked_attendance_without_booking_linkage(): void
    {
        $this->seed();
        $this->linkSeededMarkedAttendancesToBookings();

        $attendanceId = DB::table('attendances')
            ->whereNotNull('marked_at')
            ->orderBy('id')
            ->value('id');

        $this->assertNotNull($attendanceId);

        DB::table('attendances')
            ->where('id', $attendanceId)
            ->update(['child_session_booking_id' => null]);

        $report = app(LegacyContractionReadinessService::class)->report();

        $this->assertSame(1, $report['marked_attendance_bookings']['missing_total']);

        $this->artisan('legacy:reconcile')->assertExitCode(1);
    }

    private function linkSeededMarkedAttendancesToBookings(): void
    {
        $attendances = DB::table('attendances')
            ->whereNotNull('marked_at')
            ->get(['id', 'class_session_id', 'student_id']);

        foreach ($attendances as $attendance) {
            $bookingId = DB::table('child_session_bookings')
                ->where('legacy_class_session_id', $attendance->class_session_id)
                ->where('student_id', $attendance->student_id)
                ->value('id');

            $this->assertNotNull(
                $bookingId,
                "Seeded attendance {$attendance->id} must have a legacy booking before reconciliation.",
            );

            DB::table('attendances')
                ->where('id', $attendance->id)
                ->update(['child_session_booking_id' => $bookingId]);
        }
    }
}
