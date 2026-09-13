<?php

namespace App\Models;

use App\Services\Alpha\LegacyBookingBridgeService;
use Illuminate\Database\Eloquent\Relations\Pivot;

class ClassSessionStudent extends Pivot
{
    protected $table = 'class_session_student';

    public $incrementing = true;

    protected static function booted(): void
    {
        static::saved(function (ClassSessionStudent $pivot): void {
            app(LegacyBookingBridgeService::class)->syncBookingPivot($pivot);
        });

        static::deleted(function (ClassSessionStudent $pivot): void {
            app(LegacyBookingBridgeService::class)->markBookingPivotDeleted($pivot);
        });
    }
}
