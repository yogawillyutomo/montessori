<?php

namespace Tests\Feature;

use App\Models\ChildSessionBooking;
use App\Models\ClassSession;
use App\Models\Indicator;
use App\Models\Observation;
use App\Models\SessionOccurrence;
use App\Models\User;
use App\Models\WeeklySchedule;
use App\Services\Scheduling\BookingEvidenceService;
use App\Services\Scheduling\SchedulingReadModelService;
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

    public function test_scheduled_observation_uses_target_authority_when_legacy_teacher_and_pivot_are_stale(): void
    {
        $this->seed();

        $teacherUser = User::query()->where('email', 'raras@montessori.test')->firstOrFail();
        $session = ClassSession::query()
            ->whereHas('teacher', fn ($query) => $query->where('user_id', $teacherUser->id))
            ->whereHas('students', fn ($query) => $query->where('code', 'SUN01'))
            ->firstOrFail();
        $student = $session->students()->where('code', 'SUN01')->firstOrFail();
        $indicator = Indicator::query()->where('code', 'BHS02')->firstOrFail();
        $occurrence = SessionOccurrence::query()
            ->where('legacy_class_session_id', $session->id)
            ->firstOrFail();
        $otherTeacherId = DB::table('teachers')
            ->where('id', '!=', $occurrence->legacy_teacher_id)
            ->orderBy('id')
            ->value('id');

        $this->assertNotNull($otherTeacherId);

        DB::table('class_sessions')
            ->where('id', $session->id)
            ->update(['teacher_id' => $otherTeacherId]);
        DB::table('class_session_student')
            ->where('class_session_id', $session->id)
            ->where('student_id', $student->id)
            ->delete();

        $this->actingAs($teacherUser)
            ->post(route('alpha.observations.store'), [
                'class_session_id' => $session->id,
                'student_id' => $student->id,
                'teacher_id' => $occurrence->legacy_teacher_id,
                'observed_on' => $session->session_date->toDateString(),
                'note' => 'Canonical occurrence and booking remain authoritative.',
                'observations' => [
                    $indicator->id => ['status' => 'developing'],
                ],
            ])
            ->assertRedirect()
            ->assertSessionDoesntHaveErrors();

        $this->assertDatabaseHas('observations', [
            'session_occurrence_id' => $occurrence->id,
            'student_id' => $student->id,
            'teacher_id' => $occurrence->legacy_teacher_id,
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

    public function test_target_session_read_count_does_not_depend_on_legacy_observation_fk(): void
    {
        $this->seed();

        $observation = Observation::query()
            ->whereNotNull('session_occurrence_id')
            ->whereNotNull('class_session_id')
            ->firstOrFail();
        $occurrence = SessionOccurrence::query()->findOrFail($observation->session_occurrence_id);

        DB::table('observations')
            ->where('id', $observation->id)
            ->update(['class_session_id' => null]);

        $session = app(SchedulingReadModelService::class)
            ->sessions([(int) $occurrence->legacy_school_class_id], null)
            ->firstWhere('id', $occurrence->legacy_class_session_id);

        $this->assertNotNull($session);
        $this->assertGreaterThanOrEqual(1, (int) $session->observations_count);
    }

    public function test_target_session_delete_is_blocked_by_target_only_observation_evidence(): void
    {
        $this->seed();

        $admin = User::query()->where('email', 'ops@montessori.test')->firstOrFail();
        $schedule = WeeklySchedule::query()
            ->where('day_of_week', 1)
            ->where('room', 'Ruang Sunny')
            ->firstOrFail();

        $this->actingAs($admin)
            ->post(route('alpha.sessions.create-from-schedule'), [
                'weekly_schedule_id' => $schedule->id,
                'session_date' => '2026-06-08',
            ])
            ->assertRedirect();

        $occurrence = SessionOccurrence::query()
            ->where('legacy_weekly_schedule_id', $schedule->id)
            ->whereDate('occurs_on', '2026-06-08')
            ->firstOrFail();
        $legacy = ClassSession::query()->findOrFail($occurrence->legacy_class_session_id);
        $booking = ChildSessionBooking::query()
            ->where('session_occurrence_id', $occurrence->id)
            ->firstOrFail();
        $indicator = Indicator::query()->firstOrFail();

        Observation::query()->create([
            'class_session_id' => null,
            'session_occurrence_id' => $occurrence->id,
            'student_id' => $booking->student_id,
            'indicator_id' => $indicator->id,
            'development_area_id' => $indicator->development_area_id,
            'teacher_id' => $occurrence->legacy_teacher_id,
            'observation_type' => 'scheduled',
            'observed_on' => $occurrence->occurs_on,
            'level' => 'developing',
            'status' => 'saved',
            'score' => Observation::scoreForLevel('developing'),
            'note' => 'Target-only evidence must block destructive deletion.',
            'needs_follow_up' => false,
            'include_in_report' => false,
        ]);

        $this->actingAs($admin)
            ->delete(route('alpha.process.sessions.destroy', $legacy))
            ->assertSessionHasErrors('session');

        $this->assertDatabaseHas('session_occurrences', [
            'id' => $occurrence->id,
            'status' => 'planned',
            'legacy_deleted_at' => null,
        ]);
    }
}
