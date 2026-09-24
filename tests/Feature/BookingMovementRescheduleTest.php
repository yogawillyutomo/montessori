<?php

namespace Tests\Feature;

use App\Models\Attendance;
use App\Models\BookingMovement;
use App\Models\ChildSessionBooking;
use App\Models\ClassSession;
use App\Models\SessionOccurrence;
use App\Models\Student;
use App\Models\User;
use App\Services\Scheduling\BookingLineageService;
use App\Services\Scheduling\BookingRescheduleService;
use App\Services\Scheduling\ChildBookingCancellationService;
use App\Services\Scheduling\LegacySessionCompatibilityWriter;
use App\Services\Scheduling\SessionOccurrenceCancellationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use LogicException;
use Tests\TestCase;

class BookingMovementRescheduleTest extends TestCase
{
    use RefreshDatabase;

    public function test_reschedule_creates_immutable_movement_without_rewriting_source_booking(): void
    {
        $this->seed();

        $student = Student::query()->firstOrFail();
        $admin = User::query()->where('role', 'admin')->firstOrFail();
        $sourceOccurrence = $this->occurrence('2027-02-01', '08:00', '09:00', 4);
        $destinationOccurrence = $this->occurrence('2027-02-02', '10:00', '11:00', 4);
        $source = $this->booking($student, $sourceOccurrence);

        $movement = app(BookingRescheduleService::class)->reschedule(
            $source,
            $destinationOccurrence,
            $admin,
            'Family requested a different day.',
        );

        $source->refresh();
        $destination = $movement->destinationBooking()->firstOrFail();

        $this->assertSame('rescheduled_out', $source->status);
        $this->assertNull($source->active_on);
        $this->assertSame($sourceOccurrence->id, $source->session_occurrence_id);
        $this->assertSame('scheduled', $destination->status);
        $this->assertSame('rescheduled', $destination->booking_type);
        $this->assertSame('movement', $destination->source_type);
        $this->assertSame('2027-02-02', $destination->active_on->toDateString());
        $this->assertSame($admin->id, $movement->moved_by);
        $this->assertSame($source->id, $movement->source_booking_id);
        $this->assertSame($destination->id, $movement->destination_booking_id);

        $lineage = app(BookingLineageService::class);
        $this->assertSame($source->id, $lineage->originalBooking($destination)->id);
        $this->assertSame($destination->id, $lineage->currentBooking($source)->id);
        $this->assertCount(2, $lineage->chain($source));

        try {
            $movement->update(['reason' => 'Attempted rewrite']);
            $this->fail('Movement update should have been rejected.');
        } catch (LogicException) {
            $this->assertTrue(true);
        }

        try {
            $movement->delete();
            $this->fail('Movement delete should have been rejected.');
        } catch (LogicException) {
            $this->assertTrue(true);
        }
    }

    public function test_full_destination_rejects_reschedule_and_rolls_source_back(): void
    {
        $this->seed();

        $students = Student::query()->limit(2)->get();
        $this->assertCount(2, $students);
        $admin = User::query()->where('role', 'admin')->firstOrFail();
        $sourceOccurrence = $this->occurrence('2027-03-01', '08:00', '09:00', 4);
        $destinationOccurrence = $this->occurrence('2027-03-02', '10:00', '11:00', 1);
        $source = $this->booking($students[0], $sourceOccurrence);
        $this->booking($students[1], $destinationOccurrence);

        try {
            app(BookingRescheduleService::class)->reschedule(
                $source,
                $destinationOccurrence,
                $admin,
                'Try full slot.',
            );
            $this->fail('Full destination should reject reschedule.');
        } catch (ValidationException) {
            $this->assertTrue(true);
        }

        $source->refresh();
        $this->assertSame('scheduled', $source->status);
        $this->assertSame('2027-03-01', $source->active_on->toDateString());
        $this->assertFalse(BookingMovement::query()->where('source_booking_id', $source->id)->exists());
    }

    public function test_capacity_override_requires_admin_and_is_audited_on_booking_and_movement(): void
    {
        $this->seed();

        $students = Student::query()->limit(2)->get();
        $admin = User::query()->where('role', 'admin')->firstOrFail();
        $teacher = User::query()->where('role', 'teacher')->firstOrFail();
        $sourceOccurrence = $this->occurrence('2027-04-01', '08:00', '09:00', 4);
        $destinationOccurrence = $this->occurrence('2027-04-02', '10:00', '11:00', 1);
        $source = $this->booking($students[0], $sourceOccurrence);
        $this->booking($students[1], $destinationOccurrence);

        try {
            app(BookingRescheduleService::class)->reschedule(
                $source,
                $destinationOccurrence,
                $teacher,
                'Teacher tries override.',
                true,
                'Urgent request.',
            );
            $this->fail('Teacher capacity override should be rejected.');
        } catch (ValidationException) {
            $this->assertSame('scheduled', $source->fresh()->status);
        }

        $movement = app(BookingRescheduleService::class)->reschedule(
            $source,
            $destinationOccurrence,
            $admin,
            'Admin approved exception.',
            true,
            'Temporary capacity exception approved by school admin.',
        );
        $destination = $movement->destinationBooking()->firstOrFail();

        $this->assertTrue($destination->capacity_override);
        $this->assertSame($admin->id, $destination->capacity_override_by);
        $this->assertSame('Temporary capacity exception approved by school admin.', $destination->capacity_override_reason);
        $this->assertTrue($movement->capacity_override);
        $this->assertSame('Temporary capacity exception approved by school admin.', $movement->capacity_override_reason);
        $this->assertSame(2, $destinationOccurrence->activeBookingCount());
    }

    public function test_present_legacy_attendance_blocks_reschedule(): void
    {
        $this->seed();

        $student = Student::query()->firstOrFail();
        $admin = User::query()->where('role', 'admin')->firstOrFail();
        $legacySession = $this->legacySession($student, '2027-05-03');
        $source = ChildSessionBooking::query()
            ->where('legacy_class_session_id', $legacySession->id)
            ->where('student_id', $student->id)
            ->firstOrFail();
        $attendance = Attendance::query()
            ->where('class_session_id', $legacySession->id)
            ->where('student_id', $student->id)
            ->firstOrFail();
        $attendance->update([
            'status' => 'present',
            'marked_by' => $admin->id,
            'marked_at' => now(),
        ]);
        $destinationOccurrence = $this->occurrence('2027-05-04', '10:00', '11:00', 4);

        try {
            app(BookingRescheduleService::class)->reschedule(
                $source,
                $destinationOccurrence,
                $admin,
                'Should be rejected.',
            );
            $this->fail('Fulfilled booking should not be rescheduled.');
        } catch (ValidationException) {
            $this->assertTrue(true);
        }

        $this->assertSame('scheduled', $source->fresh()->status);
        $this->assertTrue($legacySession->students()->whereKey($student->id)->exists());
    }

    public function test_legacy_reschedule_moves_participant_without_resurrecting_source_and_resolves_final_attendance_slot(): void
    {
        $this->seed();

        $student = Student::query()->firstOrFail();
        $admin = User::query()->where('role', 'admin')->firstOrFail();
        $sourceSession = $this->legacySession($student, '2027-06-07');
        $destinationSession = $this->emptyLegacySession('2027-06-08');
        $source = ChildSessionBooking::query()
            ->where('legacy_class_session_id', $sourceSession->id)
            ->where('student_id', $student->id)
            ->firstOrFail();
        $destinationOccurrence = SessionOccurrence::query()
            ->where('legacy_class_session_id', $destinationSession->id)
            ->firstOrFail();

        $movement = app(BookingRescheduleService::class)->reschedule(
            $source,
            $destinationOccurrence,
            $admin,
            'Move to Tuesday.',
        );
        $destination = ChildSessionBooking::query()->findOrFail($movement->destination_booking_id);

        $this->assertFalse($sourceSession->students()->whereKey($student->id)->exists());
        $this->assertTrue($destinationSession->students()->whereKey($student->id)->exists());
        $this->assertSame('rescheduled_out', $source->fresh()->status);
        $this->assertSame($destinationSession->id, $destination->legacy_class_session_id);
        $this->assertNotNull($destination->legacy_class_session_student_id);
        $this->assertSame('movement', $destination->source_type);
        $this->assertFalse(Attendance::query()
            ->where('class_session_id', $sourceSession->id)
            ->where('student_id', $student->id)
            ->exists());

        $destinationAttendance = Attendance::query()
            ->where('class_session_id', $destinationSession->id)
            ->where('student_id', $student->id)
            ->firstOrFail();
        $this->assertSame('unmarked', $destinationAttendance->status);

        $sourceSession->update(['topic' => 'Edited after reschedule']);
        $this->assertSame('rescheduled_out', $source->fresh()->status);

        $destinationAttendance->update([
            'status' => 'present',
            'marked_by' => $admin->id,
            'marked_at' => now(),
        ]);

        $lineage = app(BookingLineageService::class);
        $this->assertSame($source->id, $lineage->originalBooking($destination)->id);
        $this->assertSame($destination->id, $lineage->currentBooking($source)->id);
        $this->assertSame($destination->id, $lineage->finalAttendanceBooking($source)?->id);
    }

    public function test_child_cancellation_preserves_audit_and_detaches_unfulfilled_legacy_participant(): void
    {
        $this->seed();

        $student = Student::query()->firstOrFail();
        $admin = User::query()->where('role', 'admin')->firstOrFail();
        $legacySession = $this->legacySession($student, '2027-07-05');
        $booking = ChildSessionBooking::query()
            ->where('legacy_class_session_id', $legacySession->id)
            ->where('student_id', $student->id)
            ->firstOrFail();

        $cancelled = app(ChildBookingCancellationService::class)->cancel(
            $booking,
            $admin,
            'Family notified absence in advance.',
        );

        $this->assertSame('cancelled', $cancelled->status);
        $this->assertNull($cancelled->active_on);
        $this->assertSame('Family notified absence in advance.', $cancelled->cancellation_reason);
        $this->assertSame($admin->id, $cancelled->cancelled_by);
        $this->assertNotNull($cancelled->cancelled_at);
        $this->assertNotNull($cancelled->legacy_deleted_at);
        $this->assertFalse($legacySession->students()->whereKey($student->id)->exists());
        $this->assertFalse(Attendance::query()
            ->where('class_session_id', $legacySession->id)
            ->where('student_id', $student->id)
            ->exists());
    }

    public function test_session_cancellation_marks_active_bookings_but_preserves_existing_historical_states(): void
    {
        $this->seed();

        $students = Student::query()->limit(2)->get();
        $admin = User::query()->where('role', 'admin')->firstOrFail();
        $occurrence = $this->occurrence('2027-08-09', '08:00', '09:00', 4);
        $active = $this->booking($students[0], $occurrence);
        $historical = ChildSessionBooking::query()->create([
            'student_id' => $students[1]->id,
            'session_occurrence_id' => $occurrence->id,
            'booking_type' => 'rescheduled',
            'status' => 'rescheduled_out',
            'source_type' => 'movement',
            'created_by' => $admin->id,
        ]);

        $cancelledOccurrence = app(SessionOccurrenceCancellationService::class)->cancel(
            $occurrence,
            $admin,
            'School closure.',
        );

        $this->assertSame('cancelled', $cancelledOccurrence->status);
        $this->assertSame('School closure.', $cancelledOccurrence->cancellation_reason);
        $this->assertSame($admin->id, $cancelledOccurrence->cancelled_by);
        $this->assertNotNull($cancelledOccurrence->cancelled_at);
        $this->assertSame('session_cancelled', $active->fresh()->status);
        $this->assertNull($active->fresh()->active_on);
        $this->assertSame('rescheduled_out', $historical->fresh()->status);
    }

    private function occurrence(string $date, string $startsAt, string $endsAt, int $capacity): SessionOccurrence
    {
        $base = ClassSession::query()->firstOrFail();
        $occurrence = SessionOccurrence::query()->create([
            'session_template_id' => null,
            'environment_id' => null,
            'occurs_on' => $date,
            'starts_at' => $startsAt,
            'ends_at' => $endsAt,
            'capacity' => $capacity,
            'room' => 'M5 Target Room',
            'status' => 'planned',
            'legacy_school_class_id' => $base->school_class_id,
            'legacy_teacher_id' => $base->teacher_id,
        ]);

        app(LegacySessionCompatibilityWriter::class)->syncOccurrence($occurrence);

        return $occurrence->fresh();
    }

    private function booking(Student $student, SessionOccurrence $occurrence): ChildSessionBooking
    {
        $booking = ChildSessionBooking::query()->create([
            'student_id' => $student->id,
            'session_occurrence_id' => $occurrence->id,
            'booking_type' => 'regular',
            'status' => 'scheduled',
            'source_type' => 'test',
        ]);

        $compatibility = app(LegacySessionCompatibilityWriter::class);
        $compatibility->syncBooking($booking);
        $compatibility->ensureUnmarkedAttendance($booking);

        return $booking->fresh();
    }

    private function legacySession(Student $student, string $date): ClassSession
    {
        $previousSource = config('montessori.session.write_source', 'target');
        config()->set('montessori.session.write_source', 'legacy');

        try {
            $session = $this->emptyLegacySession($date);
            $session->students()->attach($student->id);
            $session->attendances()->firstOrCreate(
                ['student_id' => $student->id],
                [
                    'status' => 'unmarked',
                    'note' => null,
                    'marked_by' => null,
                    'marked_at' => null,
                ]
            );

            return $session;
        } finally {
            config()->set('montessori.session.write_source', $previousSource);
        }
    }

    private function emptyLegacySession(string $date): ClassSession
    {
        $previousSource = config('montessori.session.write_source', 'target');
        config()->set('montessori.session.write_source', 'legacy');

        try {
            $base = ClassSession::query()->firstOrFail();

            return ClassSession::query()->create([
                'weekly_schedule_id' => null,
                'school_class_id' => $base->school_class_id,
                'teacher_id' => $base->teacher_id,
                'room' => 'M5 Legacy Room '.$date,
                'capacity' => 8,
                'session_date' => $date,
                'starts_at' => '14:00',
                'ends_at' => '15:00',
                'topic' => 'M5 compatibility session',
                'status' => 'planned',
            ]);
        } finally {
            config()->set('montessori.session.write_source', $previousSource);
        }
    }
}
