<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $this->assertLegacyDailyInvariants();

        Schema::create('recurring_schedules', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('student_id')->constrained()->cascadeOnDelete();
            $table->foreignId('session_template_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedTinyInteger('day_of_week');
            $table->date('valid_from')->nullable();
            $table->date('valid_until')->nullable();
            $table->boolean('is_active')->default(true);
            $table->unsignedBigInteger('legacy_student_weekly_schedule_id')->nullable()->unique();
            $table->unsignedBigInteger('legacy_weekly_schedule_id')->nullable();
            $table->timestamp('legacy_deleted_at')->nullable();
            $table->timestamps();

            $table->index(['student_id', 'day_of_week', 'is_active']);
            $table->index(['session_template_id', 'is_active']);
        });

        Schema::create('child_session_bookings', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('student_id')->constrained()->cascadeOnDelete();
            $table->foreignId('session_occurrence_id')->constrained()->cascadeOnDelete();
            $table->string('booking_type')->default('regular');
            $table->string('status')->default('scheduled');
            $table->date('active_on')->nullable();
            $table->string('source_type')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedBigInteger('legacy_class_session_student_id')->nullable()->unique();
            $table->unsignedBigInteger('legacy_class_session_id')->nullable();
            $table->timestamp('legacy_deleted_at')->nullable();
            $table->timestamps();

            // NULL permits any amount of history; a dated active key permits only one
            // active booking for one child on one calendar date.
            $table->unique(['student_id', 'active_on'], 'child_booking_one_active_per_day');
            $table->index(['session_occurrence_id', 'status']);
            $table->index(['student_id', 'status']);
        });

        $this->backfillRecurringSchedules();
        $this->backfillBookings();
    }

    public function down(): void
    {
        Schema::dropIfExists('child_session_bookings');
        Schema::dropIfExists('recurring_schedules');
    }

    private function assertLegacyDailyInvariants(): void
    {
        $duplicateRecurring = DB::table('student_weekly_schedule as sws')
            ->join('weekly_schedules as ws', 'ws.id', '=', 'sws.weekly_schedule_id')
            ->where('ws.is_active', true)
            ->select('sws.student_id', 'ws.day_of_week', DB::raw('COUNT(*) as aggregate'))
            ->groupBy('sws.student_id', 'ws.day_of_week')
            ->havingRaw('COUNT(*) > 1')
            ->first();

        if ($duplicateRecurring) {
            throw new RuntimeException(
                "Cannot migrate recurring schedules: student {$duplicateRecurring->student_id} has multiple active legacy slots on weekday {$duplicateRecurring->day_of_week}."
            );
        }

        $scheduleOverflow = DB::table('student_weekly_schedule as sws')
            ->join('weekly_schedules as ws', 'ws.id', '=', 'sws.weekly_schedule_id')
            ->whereNotNull('ws.capacity')
            ->select('ws.id', 'ws.capacity', DB::raw('COUNT(*) as aggregate'))
            ->groupBy('ws.id', 'ws.capacity')
            ->havingRaw('COUNT(*) > ws.capacity')
            ->first();

        if ($scheduleOverflow) {
            throw new RuntimeException(
                "Cannot migrate recurring schedules: legacy weekly schedule {$scheduleOverflow->id} exceeds capacity {$scheduleOverflow->capacity}."
            );
        }

        $duplicateBooking = DB::table('class_session_student as css')
            ->join('class_sessions as cs', 'cs.id', '=', 'css.class_session_id')
            ->where('cs.status', '!=', 'cancelled')
            ->select('css.student_id', 'cs.session_date', DB::raw('COUNT(*) as aggregate'))
            ->groupBy('css.student_id', 'cs.session_date')
            ->havingRaw('COUNT(*) > 1')
            ->first();

        if ($duplicateBooking) {
            throw new RuntimeException(
                "Cannot migrate bookings: student {$duplicateBooking->student_id} has multiple active legacy sessions on {$duplicateBooking->session_date}."
            );
        }

        $sessionOverflow = DB::table('class_session_student as css')
            ->join('class_sessions as cs', 'cs.id', '=', 'css.class_session_id')
            ->where('cs.status', '!=', 'cancelled')
            ->whereNotNull('cs.capacity')
            ->select('cs.id', 'cs.capacity', DB::raw('COUNT(*) as aggregate'))
            ->groupBy('cs.id', 'cs.capacity')
            ->havingRaw('COUNT(*) > cs.capacity')
            ->first();

        if ($sessionOverflow) {
            throw new RuntimeException(
                "Cannot migrate bookings: legacy class session {$sessionOverflow->id} exceeds capacity {$sessionOverflow->capacity}."
            );
        }
    }

    private function backfillRecurringSchedules(): void
    {
        DB::table('student_weekly_schedule as sws')
            ->join('weekly_schedules as ws', 'ws.id', '=', 'sws.weekly_schedule_id')
            ->select([
                'sws.id as pivot_id',
                'sws.student_id',
                'sws.weekly_schedule_id',
                'sws.created_at',
                'sws.updated_at',
                'ws.day_of_week',
                'ws.is_active',
            ])
            ->orderBy('sws.id')
            ->each(function (object $row): void {
                $templateId = DB::table('session_templates')
                    ->where('legacy_weekly_schedule_id', $row->weekly_schedule_id)
                    ->value('id');

                DB::table('recurring_schedules')->insert([
                    'student_id' => $row->student_id,
                    'session_template_id' => $templateId,
                    'day_of_week' => $row->day_of_week,
                    'valid_from' => null,
                    'valid_until' => null,
                    'is_active' => (bool) $row->is_active,
                    'legacy_student_weekly_schedule_id' => $row->pivot_id,
                    'legacy_weekly_schedule_id' => $row->weekly_schedule_id,
                    'legacy_deleted_at' => null,
                    'created_at' => $row->created_at,
                    'updated_at' => $row->updated_at,
                ]);
            });
    }

    private function backfillBookings(): void
    {
        DB::table('class_session_student as css')
            ->join('class_sessions as cs', 'cs.id', '=', 'css.class_session_id')
            ->select([
                'css.id as pivot_id',
                'css.student_id',
                'css.class_session_id',
                'css.created_at',
                'css.updated_at',
                'cs.session_date',
                'cs.status as session_status',
            ])
            ->orderBy('css.id')
            ->each(function (object $row): void {
                $occurrenceId = DB::table('session_occurrences')
                    ->where('legacy_class_session_id', $row->class_session_id)
                    ->value('id');

                if (! $occurrenceId) {
                    throw new RuntimeException(
                        "Cannot migrate booking pivot {$row->pivot_id}: target session occurrence is missing."
                    );
                }

                $cancelled = $row->session_status === 'cancelled';

                DB::table('child_session_bookings')->insert([
                    'student_id' => $row->student_id,
                    'session_occurrence_id' => $occurrenceId,
                    'booking_type' => 'regular',
                    'status' => $cancelled ? 'session_cancelled' : 'scheduled',
                    'active_on' => $cancelled ? null : $row->session_date,
                    'source_type' => 'legacy',
                    'created_by' => null,
                    'legacy_class_session_student_id' => $row->pivot_id,
                    'legacy_class_session_id' => $row->class_session_id,
                    'legacy_deleted_at' => null,
                    'created_at' => $row->created_at,
                    'updated_at' => $row->updated_at,
                ]);
            });
    }
};
