<?php

namespace App\Models;

use App\Services\Alpha\LegacyBookingBridgeService;
use Illuminate\Database\Eloquent\Relations\Pivot;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ClassSessionStudent extends Pivot
{
    protected $table = 'class_session_student';

    public $incrementing = true;

    protected static function booted(): void
    {
        static::creating(function (ClassSessionStudent $pivot): void {
            $session = ClassSession::query()->find($pivot->class_session_id);
            if (! $session || $session->status === 'cancelled') {
                return;
            }

            $conflict = DB::table('class_session_student as css')
                ->join('class_sessions as cs', 'cs.id', '=', 'css.class_session_id')
                ->where('css.student_id', $pivot->student_id)
                ->whereDate('cs.session_date', $session->session_date->toDateString())
                ->where('cs.status', '!=', 'cancelled')
                ->where('cs.id', '!=', $session->id)
                ->exists();

            if ($conflict) {
                throw ValidationException::withMessages([
                    'student_ids' => 'Anak sudah memiliki sesi aktif lain pada tanggal yang sama.',
                ]);
            }
        });

        static::saved(function (ClassSessionStudent $pivot): void {
            if (config('montessori.session.write_source', 'target') !== 'legacy') {
                return;
            }

            app(LegacyBookingBridgeService::class)->syncBookingPivot($pivot);
        });

        static::deleted(function (ClassSessionStudent $pivot): void {
            if (config('montessori.session.write_source', 'target') !== 'legacy') {
                return;
            }

            app(LegacyBookingBridgeService::class)->markBookingPivotDeleted($pivot);
        });
    }
}
