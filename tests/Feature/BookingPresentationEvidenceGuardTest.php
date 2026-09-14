<?php

namespace Tests\Feature;

use App\Models\ChildSessionBooking;
use App\Models\ClassSession;
use App\Models\DevelopmentArea;
use App\Models\SessionOccurrence;
use App\Models\SessionTemplate;
use App\Models\User;
use App\Models\WeeklySchedule;
use App\Services\Scheduling\BookingRescheduleService;
use App\Services\Scheduling\ChildBookingCancellationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class BookingPresentationEvidenceGuardTest extends TestCase
{
    use RefreshDatabase;

    public function test_roster_update_cannot_remove_child_with_presentation_evidence(): void
    {
        [$occurrence, $legacy, $booking, $admin] = $this->sourceSessionWithPresentation();
        $remainingStudentIds = ChildSessionBooking::query()
            ->where('session_occurrence_id', $occurrence->id)
            ->where('status', 'scheduled')
            ->where('student_id', '!=', $booking->student_id)
            ->pluck('student_id')
            ->map(fn ($id): int => (int) $id)
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
                'student_ids' => $remainingStudentIds,
            ])
            ->assertSessionHasErrors('student_ids');

        $booking->refresh();
        $this->assertSame('scheduled', $booking->status);
        $this->assertDatabaseHas('class_session_student', [
            'id' => $booking->legacy_class_session_student_id,
            'student_id' => $booking->student_id,
        ]);
    }

    public function test_child_cancellation_cannot_remove_booking_with_presentation_evidence(): void
    {
        [, , $booking, $admin] = $this->sourceSessionWithPresentation();

        try {
            app(ChildBookingCancellationService::class)->cancel(
                $booking,
                $admin,
                'Should be rejected because presentation is historical evidence.',
            );
            $this->fail('Child cancellation should reject presentation evidence.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('booking_id', $exception->errors());
        }

        $booking->refresh();
        $this->assertSame('scheduled', $booking->status);
    }

    public function test_reschedule_cannot_move_booking_with_presentation_evidence(): void
    {
        [$occurrence, , $booking, $admin] = $this->sourceSessionWithPresentation();
        $destination = SessionOccurrence::query()->create([
            'session_template_id' => null,
            'environment_id' => $occurrence->environment_id,
            'occurs_on' => '2026-06-09',
            'starts_at' => '13:00',
            'ends_at' => '14:00',
            'capacity' => 10,
            'room' => 'Target Only Destination',
            'status' => 'planned',
            'legacy_weekly_schedule_id' => null,
            'legacy_school_class_id' => $occurrence->legacy_school_class_id,
            'legacy_teacher_id' => $occurrence->legacy_teacher_id,
            'legacy_topic' => 'Presentation guard destination',
        ]);

        try {
            app(BookingRescheduleService::class)->reschedule(
                $booking,
                $destination,
                $admin,
                'Should be rejected because presentation is historical evidence.',
            );
            $this->fail('Reschedule should reject presentation evidence.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('source_booking_id', $exception->errors());
        }

        $booking->refresh();
        $this->assertSame('scheduled', $booking->status);
        $this->assertDatabaseMissing('booking_movements', [
            'source_booking_id' => $booking->id,
        ]);
    }

    /** @return array{SessionOccurrence, ClassSession, ChildSessionBooking, User} */
    private function sourceSessionWithPresentation(): array
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
        $booking = ChildSessionBooking::query()
            ->where('session_occurrence_id', $occurrence->id)
            ->where('status', 'scheduled')
            ->firstOrFail();
        $area = DevelopmentArea::query()->firstOrFail();
        $activityId = DB::table('montessori_activities')->insertGetId([
            'development_area_id' => $area->id,
            'code' => 'TEST-PRESENTATION-GUARD',
            'name' => 'Presentation Guard Material',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('presentations')->insert([
            'student_id' => $booking->student_id,
            'teacher_id' => $occurrence->legacy_teacher_id,
            'montessori_activity_id' => $activityId,
            'session_occurrence_id' => $occurrence->id,
            'presented_on' => $occurrence->occurs_on->toDateString(),
            'presentation_type' => 'initial',
            'note' => 'Historical presentation evidence.',
            'recorded_by' => $admin->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return [$occurrence, $legacy, $booking, $admin];
    }
}
