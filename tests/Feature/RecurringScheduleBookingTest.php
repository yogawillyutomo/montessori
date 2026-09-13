<?php

namespace Tests\Feature;

use App\Models\ChildSessionBooking;
use App\Models\ClassSession;
use App\Models\RecurringSchedule;
use App\Models\SessionOccurrence;
use App\Models\Student;
use App\Models\Teacher;
use App\Models\WeeklySchedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class RecurringScheduleBookingTest extends TestCase
{
    use RefreshDatabase;

    public function test_seeded_legacy_participant_pivots_have_target_mappings(): void
    {
        $this->seed();

        $legacyRecurringCount = (int) DB::table('student_weekly_schedule')->count();
        $legacyBookingCount = (int) DB::table('class_session_student')->count();

        $this->assertSame(
            $legacyRecurringCount,
            RecurringSchedule::query()->whereNotNull('legacy_student_weekly_schedule_id')->count()
        );
        $this->assertSame(
            $legacyBookingCount,
            ChildSessionBooking::query()->whereNotNull('legacy_class_session_student_id')->count()
        );

        $this->assertSame(
            0,
            ChildSessionBooking::query()
                ->where('status', 'scheduled')
                ->whereNull('active_on')
                ->count()
        );
    }

    public function test_child_cannot_have_two_active_recurring_slots_on_same_weekday_even_when_times_do_not_overlap(): void
    {
        $this->seed();

        $source = WeeklySchedule::query()->with('students')->where('is_active', true)->firstOrFail();
        $student = $source->students->firstOrFail();

        $second = WeeklySchedule::query()->create([
            'school_class_id' => $source->school_class_id,
            'teacher_id' => Teacher::query()->whereKeyNot($source->teacher_id)->value('id') ?: $source->teacher_id,
            'room' => 'Different Room',
            'capacity' => 8,
            'day_of_week' => $source->day_of_week,
            'starts_at' => '17:00',
            'ends_at' => '18:00',
            'topic' => 'Non-overlapping but same day',
            'is_active' => true,
        ]);

        $this->expectException(ValidationException::class);
        $second->students()->attach($student->id);
    }

    public function test_child_cannot_have_two_active_bookings_on_same_date_even_when_times_do_not_overlap(): void
    {
        $this->seed();

        $source = ClassSession::query()->with('students')->firstOrFail();
        $student = $source->students->firstOrFail();

        $second = ClassSession::query()->create([
            'weekly_schedule_id' => null,
            'school_class_id' => $source->school_class_id,
            'teacher_id' => $source->teacher_id,
            'room' => 'Different Room',
            'capacity' => 8,
            'session_date' => $source->session_date->toDateString(),
            'starts_at' => '17:00',
            'ends_at' => '18:00',
            'topic' => 'Second same-day session',
            'status' => 'planned',
        ]);

        $this->expectException(ValidationException::class);
        $second->students()->attach($student->id);
    }

    public function test_historical_inactive_booking_can_coexist_with_new_active_booking_on_same_date(): void
    {
        $this->seed();

        $student = Student::query()->firstOrFail();
        $date = '2026-12-20';
        $firstOccurrence = $this->occurrence($date, '08:00', '09:00', 8);
        $secondOccurrence = $this->occurrence($date, '10:00', '11:00', 8);

        $first = ChildSessionBooking::query()->create([
            'student_id' => $student->id,
            'session_occurrence_id' => $firstOccurrence->id,
            'booking_type' => 'regular',
            'status' => 'scheduled',
            'source_type' => 'test',
        ]);

        $this->assertSame($date, $first->active_on->toDateString());

        $first->update(['status' => 'cancelled']);
        $this->assertNull($first->fresh()->active_on);

        $second = ChildSessionBooking::query()->create([
            'student_id' => $student->id,
            'session_occurrence_id' => $secondOccurrence->id,
            'booking_type' => 'regular',
            'status' => 'scheduled',
            'source_type' => 'test',
        ]);

        $this->assertSame($date, $second->active_on->toDateString());
        $this->assertSame(2, ChildSessionBooking::query()->where('student_id', $student->id)->whereIn('id', [$first->id, $second->id])->count());
        $this->assertSame(1, ChildSessionBooking::query()->active()->where('student_id', $student->id)->whereDate('active_on', $date)->count());
    }

    public function test_occurrence_capacity_counts_only_active_bookings(): void
    {
        $this->seed();

        $students = Student::query()->limit(2)->get();
        $this->assertCount(2, $students);
        $occurrence = $this->occurrence('2026-12-21', '08:00', '09:00', 1);

        $first = ChildSessionBooking::query()->create([
            'student_id' => $students[0]->id,
            'session_occurrence_id' => $occurrence->id,
            'booking_type' => 'regular',
            'status' => 'scheduled',
            'source_type' => 'test',
        ]);

        $this->assertSame(1, $occurrence->activeBookingCount());
        $this->assertSame(0, $occurrence->availableCapacity());

        try {
            ChildSessionBooking::query()->create([
                'student_id' => $students[1]->id,
                'session_occurrence_id' => $occurrence->id,
                'booking_type' => 'regular',
                'status' => 'scheduled',
                'source_type' => 'test',
            ]);
            $this->fail('Booking beyond occurrence capacity should be rejected.');
        } catch (ValidationException) {
            $this->assertTrue(true);
        }

        $first->update(['status' => 'cancelled']);

        $replacement = ChildSessionBooking::query()->create([
            'student_id' => $students[1]->id,
            'session_occurrence_id' => $occurrence->id,
            'booking_type' => 'regular',
            'status' => 'scheduled',
            'source_type' => 'test',
        ]);

        $this->assertSame('scheduled', $replacement->status);
        $this->assertSame(1, $occurrence->activeBookingCount());
    }

    public function test_booking_can_exist_before_attendance_is_recorded(): void
    {
        $this->seed();

        $source = ClassSession::query()->firstOrFail();
        $student = Student::query()->whereDoesntHave('classSessions', function ($query): void {
            $query->whereDate('session_date', '2026-12-22');
        })->firstOrFail();

        $session = ClassSession::query()->create([
            'weekly_schedule_id' => null,
            'school_class_id' => $source->school_class_id,
            'teacher_id' => $source->teacher_id,
            'room' => 'Booking Test Room',
            'capacity' => 8,
            'session_date' => '2026-12-22',
            'starts_at' => '08:00',
            'ends_at' => '09:00',
            'topic' => 'Booking before attendance',
            'status' => 'planned',
        ]);
        $session->students()->attach($student->id);

        $booking = ChildSessionBooking::query()
            ->where('legacy_class_session_id', $session->id)
            ->where('student_id', $student->id)
            ->firstOrFail();

        $this->assertSame('scheduled', $booking->status);
        $this->assertSame('2026-12-22', $booking->active_on->toDateString());
        $this->assertFalse($session->attendances()->where('student_id', $student->id)->exists());
    }

    public function test_legacy_cancellation_and_detach_preserve_booking_history(): void
    {
        $this->seed();

        $session = ClassSession::query()->with('students')->firstOrFail();
        $student = $session->students->firstOrFail();
        $pivotId = (int) $student->pivot->id;

        $session->update(['status' => 'cancelled']);

        $booking = ChildSessionBooking::query()
            ->where('legacy_class_session_student_id', $pivotId)
            ->firstOrFail();
        $this->assertSame('session_cancelled', $booking->status);
        $this->assertNull($booking->active_on);

        $session->students()->detach($student->id);

        $booking->refresh();
        $this->assertSame('cancelled', $booking->status);
        $this->assertNull($booking->active_on);
        $this->assertNotNull($booking->legacy_deleted_at);
    }

    private function occurrence(string $date, string $startsAt, string $endsAt, int $capacity): SessionOccurrence
    {
        return SessionOccurrence::query()->create([
            'session_template_id' => null,
            'environment_id' => null,
            'occurs_on' => $date,
            'starts_at' => $startsAt,
            'ends_at' => $endsAt,
            'capacity' => $capacity,
            'room' => 'Target Test Room',
            'status' => 'planned',
        ]);
    }
}
