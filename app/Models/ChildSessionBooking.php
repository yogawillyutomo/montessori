<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Validation\ValidationException;

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

            $occurrence = SessionOccurrence::query()->find($booking->session_occurrence_id);
            if (! $occurrence) {
                throw ValidationException::withMessages([
                    'session_occurrence_id' => 'Session occurrence untuk booking tidak ditemukan.',
                ]);
            }

            if ($occurrence->status === 'cancelled') {
                throw ValidationException::withMessages([
                    'session_occurrence_id' => 'Anak tidak dapat dibooking ke session occurrence yang dibatalkan.',
                ]);
            }

            $booking->active_on = $occurrence->occurs_on->toDateString();

            $duplicate = self::query()
                ->active()
                ->where('student_id', $booking->student_id)
                ->whereDate('active_on', $booking->active_on)
                ->when($booking->exists, fn (Builder $query) => $query->whereKeyNot($booking->getKey()))
                ->exists();

            if ($duplicate) {
                throw ValidationException::withMessages([
                    'student_id' => 'Anak sudah memiliki booking aktif lain pada tanggal yang sama.',
                ]);
            }

            if ($occurrence->capacity !== null) {
                $activeCount = self::query()
                    ->active()
                    ->where('session_occurrence_id', $occurrence->id)
                    ->when($booking->exists, fn (Builder $query) => $query->whereKeyNot($booking->getKey()))
                    ->count();

                if ($activeCount >= $occurrence->capacity) {
                    throw ValidationException::withMessages([
                        'session_occurrence_id' => "Kapasitas session occurrence sudah penuh ({$occurrence->capacity} anak).",
                    ]);
                }
            }
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
