<?php

namespace Tests\Feature;

use App\Services\Migration\LegacyContractionAuditService;
use App\Services\Migration\LegacyContractionReadinessService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class LegacyContractionAuditTest extends TestCase
{
    use RefreshDatabase;

    public function test_reconciled_data_does_not_imply_legacy_deletion_is_safe(): void
    {
        $this->seed();

        $readiness = app(LegacyContractionReadinessService::class);
        $audit = app(LegacyContractionAuditService::class);
        $report = $audit->report();

        $this->assertTrue($readiness->dataReady(), json_encode($readiness->report(), JSON_PRETTY_PRINT));
        $this->assertTrue($report['data_reconciliation']['ready']);
        $this->assertTrue($report['scheduled_observation_target_link']['ready']);
        $this->assertTrue($report['all_attendance_booking_link']['ready']);

        $this->assertFalse($report['observation_legacy_fk_retired']['ready']);
        $this->assertFalse($report['attendance_legacy_fk_retired']['ready']);
        $this->assertFalse($report['session_notes_target_home']['ready']);
        $this->assertFalse($report['compatibility_read_model_retired']['ready']);
        $this->assertFalse($report['legacy_rollback_retired']['ready']);
        $this->assertFalse($audit->deletionReady());

        $this->artisan('legacy:contraction-audit')
            ->expectsOutputToContain('DELETION READINESS: BLOCKED.')
            ->assertExitCode(1);
    }

    public function test_reconciliation_blocks_when_scheduled_observation_loses_target_occurrence_link(): void
    {
        $this->seed();

        $observationId = DB::table('observations')
            ->whereNotNull('class_session_id')
            ->whereNotNull('session_occurrence_id')
            ->orderBy('id')
            ->value('id');

        $this->assertNotNull($observationId);

        DB::table('observations')
            ->where('id', $observationId)
            ->update(['session_occurrence_id' => null]);

        $readiness = app(LegacyContractionReadinessService::class);
        $report = $readiness->report();

        $this->assertSame(1, $report['scheduled_observation_occurrences']['missing_total']);
        $this->assertSame(1, $report['scheduled_observation_occurrences']['mismatch_total']);
        $this->assertFalse($readiness->dataReady());
    }

    public function test_reconciliation_detects_observation_linked_to_wrong_occurrence(): void
    {
        $this->seed();

        $observation = DB::table('observations')
            ->whereNotNull('class_session_id')
            ->whereNotNull('session_occurrence_id')
            ->orderBy('id')
            ->first();

        $this->assertNotNull($observation);

        $otherOccurrenceId = DB::table('session_occurrences')
            ->where('id', '!=', $observation->session_occurrence_id)
            ->where('legacy_class_session_id', '!=', $observation->class_session_id)
            ->orderBy('id')
            ->value('id');

        $this->assertNotNull($otherOccurrenceId);

        DB::table('observations')
            ->where('id', $observation->id)
            ->update(['session_occurrence_id' => $otherOccurrenceId]);

        $readiness = app(LegacyContractionReadinessService::class);
        $report = $readiness->report();

        $this->assertSame(0, $report['scheduled_observation_occurrences']['missing_total']);
        $this->assertSame(1, $report['scheduled_observation_occurrences']['mismatch_total']);
        $this->assertFalse($readiness->dataReady());
    }
}
