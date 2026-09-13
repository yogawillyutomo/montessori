<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'student_id',
    'session_occurrence_id',
    'booking_type',
    'status',
    'active_on',
    'source_type',
    'created_by',
    'legacy_class_session_student_id',
    'legacy_class_session_id',
    'legacy_deleted_at',
])]
class ChildSessionBooking extends Model
{
    use HasFactory;

    public const ACTIVE_STATUSES = [
        'scheduled',
    ];

    public const STATUSES = [
        'scheduled',
        'rescheduled_out',
        'cancelled',
        'session_cancelled',
    ];

    protected static function booted(): void
    {
        static::saving(function (ChildSessionBooking $booking): void {
            if (! in_array($booking->status, self::ACTIVE_STATUSES, true)) {
                $booking->active_on = null;

                return;
            }

            $occurrence = $booking->sessionOccurrence()->first();
            $booking->active_on = $occurrence?->occurs_on?->toDateString();
        });
    }

    protected function casts(): array
    {
        return [
            'active_on' => 'date',
            'legacy_deleted_at' => 'datetime',
        ];
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query
            ->whereIn('status', self::ACTIVE_STATUSES)
            ->whereNotNull('active_on');
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    public function sessionOccurrence(): BelongsTo
    {
        return $this->belongsTo(SessionOccurrence::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
