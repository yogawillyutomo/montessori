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
    'child_enrollment_id',
    'session_template_id',
    'day_of_week',
    'valid_from',
    'valid_until',
    'is_active',
    'legacy_student_weekly_schedule_id',
    'legacy_weekly_schedule_id',
    'legacy_deleted_at',
])]
class RecurringSchedule extends Model
{
    use HasFactory;

    protected static function booted(): void
    {
        static::saving(function (RecurringSchedule $schedule): void {
            if ($schedule->valid_from && $schedule->valid_until && $schedule->valid_until->lt($schedule->valid_from)) {
                throw ValidationException::withMessages([
                    'valid_until' => 'Tanggal akhir recurring schedule tidak boleh sebelum tanggal mulai.',
                ]);
            }

            if ($schedule->child_enrollment_id !== null) {
                $enrollment = ChildEnrollment::query()->find($schedule->child_enrollment_id);

                if (! $enrollment || (int) $enrollment->student_id !== (int) $schedule->student_id) {
                    throw ValidationException::withMessages([
                        'child_enrollment_id' => 'Recurring schedule harus memakai enrollment milik anak yang sama.',
                    ]);
                }

                if ($schedule->valid_from === null) {
                    throw ValidationException::withMessages([
                        'valid_from' => 'Recurring schedule yang terkait enrollment harus memiliki tanggal mulai.',
                    ]);
                }

                if (! $enrollment->coversDate($schedule->valid_from->toDateString())) {
                    throw ValidationException::withMessages([
                        'valid_from' => 'Recurring schedule harus mulai di dalam periode enrollment.',
                    ]);
                }

                if ($schedule->valid_until !== null
                    && ! $enrollment->coversDate($schedule->valid_until->toDateString())) {
                    throw ValidationException::withMessages([
                        'valid_until' => 'Recurring schedule harus berakhir di dalam periode enrollment.',
                    ]);
                }
            }

            if (! $schedule->is_active) {
                return;
            }

            $conflict = self::query()
                ->where('student_id', $schedule->student_id)
                ->where('day_of_week', $schedule->day_of_week)
                ->where('is_active', true)
                ->when($schedule->exists, fn (Builder $query) => $query->whereKeyNot($schedule->getKey()))
                ->when($schedule->valid_until, function (Builder $query) use ($schedule): void {
                    $query->where(function (Builder $range) use ($schedule): void {
                        $range->whereNull('valid_from')
                            ->orWhereDate('valid_from', '<=', $schedule->valid_until->toDateString());
                    });
                })
                ->when($schedule->valid_from, function (Builder $query) use ($schedule): void {
                    $query->where(function (Builder $range) use ($schedule): void {
                        $range->whereNull('valid_until')
                            ->orWhereDate('valid_until', '>=', $schedule->valid_from->toDateString());
                    });
                })
                ->exists();

            if ($conflict) {
                throw ValidationException::withMessages([
                    'student_id' => 'Anak sudah memiliki recurring slot aktif lain pada hari yang sama dalam periode yang beririsan.',
                ]);
            }
        });
    }

    protected function casts(): array
    {
        return [
            'day_of_week' => 'integer',
            'valid_from' => 'date',
            'valid_until' => 'date',
            'is_active' => 'boolean',
            'legacy_deleted_at' => 'datetime',
        ];
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    public function childEnrollment(): BelongsTo
    {
        return $this->belongsTo(ChildEnrollment::class);
    }

    public function sessionTemplate(): BelongsTo
    {
        return $this->belongsTo(SessionTemplate::class);
    }
}
