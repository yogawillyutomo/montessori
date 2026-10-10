<?php

namespace Tests\Feature;

use App\Models\ChildSessionBooking;
use App\Models\ClassSession;
use App\Models\Indicator;
use App\Models\Observation;
use App\Models\SessionOccurrence;
use App\Models\User;
use App\Services\Scheduling\BookingEvidenceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ObservationSessionOccurrenceCutoverTest extends TestCase
{
    use RefreshDatabase;

    public function test_seeded_scheduled_observations_have_canonical_occurrence_lineage(): void
    {
        $this->seed();

        $observations = Observation::query()
            ->whereNotNull('class_session_id')
            ->get();

        $this->assertNotEmpty($observations);

        foreach ($observations as $observation) {
            $this->assertNotNull($observation->session_occurrence_id);

            $occurrence = SessionOccurrence::query()->findOrFail($observation->session_occurrence_id);

            $this->assertSame((int) $observation->class_session_id, (int) $occurrence->legacy_class_session_id);
        }
    }

    public function test_scheduled_observation_write_records_target_occurrence_lineage(): void
    {
        $this->seed();

        $admin = User::query()->where('email', 'ops@montessori.test')->firstOrFail();
        $session = ClassSession::query()
            ->whereHas('students')
            ->orderBy('id')
            ->firstOrFail();
        $student = $session->students()->firstOrFail();
        $indicator = Indicator::query()->firstOrFail();
        $occurrence = SessionOccurrence::query()
            ->where('legacy_class_session_id', $session->id)
            ->firstOrFail();

        $this->actingAs($admin)
            ->post(route('alpha.observations.store'), [
                'class_session_id' => $session->id,
                'student_id' => $student->id,
                'teacher_id' => $session->teacher_id,
                'observed_on' => $session->session_date->toDateString(),
                'note' => 'M17.4 target occurrence observation.',
                'observations' => [
                    $indicator->id => ['status' => 'independent'],
                ],
            ])
            ->assertRedirect()
            ->assertSessionDoesntHaveErrors();

        $this->assertDatabaseHas('observations', [
            'class_session_id' => $session->id,
            'session_occurrence_id' => $occurrence->id,
            'student_id' => $student->id,
            'indicator_id' => $indicator->id,
        ]);
    }

    public function test_booking_evidence_uses_target_observation_link_without_legacy_observation_fk(): void
    {
        $this->seed();

        $observation = Observation::query()
            ->whereNotNull('session_occurrence_id')
            ->whereNotNull('class_session_id')
            ->firstOrFail();

        $booking = ChildSessionBooking::query()
            ->where('session_occurrence_id', $observation->session_occurrence_id)
            ->where('student_id', $observation->student_id)
            ->firstOrFail();

        DB::table('observations')
            ->where('id', $observation->id)
            ->update(['class_session_id' => null]);

        $this->assertTrue(app(BookingEvidenceService::class)->hasObservationEvidence($booking));
    }
}
