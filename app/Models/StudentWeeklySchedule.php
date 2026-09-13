<?php

namespace App\Models;

use App\Services\Alpha\LegacyBookingBridgeService;
use Illuminate\Database\Eloquent\Relations\Pivot;

class StudentWeeklySchedule extends Pivot
{
    protected $table = 'student_weekly_schedule';

    public $incrementing = true;

    protected static function booted(): void
    {
        static::saved(function (StudentWeeklySchedule $pivot): void {
            app(LegacyBookingBridgeService::class)->syncRecurringPivot($pivot);
        });

        static::deleted(function (StudentWeeklySchedule $pivot): void {
            app(LegacyBookingBridgeService::class)->markRecurringPivotDeleted($pivot);
        });
    }
}
