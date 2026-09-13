<?php

namespace App\Models;

use App\Services\Alpha\LegacyBookingBridgeService;
use Illuminate\Database\Eloquent\Relations\Pivot;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class StudentWeeklySchedule extends Pivot
{
    protected $table = 'student_weekly_schedule';

    public $incrementing = true;

    protected static function booted(): void
    {
        static::creating(function (StudentWeeklySchedule $pivot): void {
            $schedule = WeeklySchedule::query()->find($pivot->weekly_schedule_id);
            if (! $schedule || ! $schedule->is_active) {
                return;
            }

            $conflict = DB::table('student_weekly_schedule as sws')
                ->join('weekly_schedules as ws', 'ws.id', '=', 'sws.weekly_schedule_id')
                ->where('sws.student_id', $pivot->student_id)
                ->where('ws.is_active', true)
                ->where('ws.day_of_week', $schedule->day_of_week)
                ->where('ws.id', '!=', $schedule->id)
                ->exists();

            if ($conflict) {
                throw ValidationException::withMessages([
                    'student_ids' => 'Anak sudah memiliki slot mingguan aktif lain pada hari yang sama.',
                ]);
            }
        });

        static::saved(function (StudentWeeklySchedule $pivot): void {
            app(LegacyBookingBridgeService::class)->syncRecurringPivot($pivot);
        });

        static::deleted(function (StudentWeeklySchedule $pivot): void {
            app(LegacyBookingBridgeService::class)->markRecurringPivotDeleted($pivot);
        });
    }
}
