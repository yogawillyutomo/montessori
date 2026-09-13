<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'environment_id',
    'name',
    'code',
    'day_of_week',
    'starts_at',
    'ends_at',
    'capacity',
    'room',
    'valid_from',
    'valid_until',
    'is_active',
    'legacy_weekly_schedule_id',
    'legacy_school_class_id',
    'legacy_teacher_id',
    'legacy_topic',
    'legacy_deleted_at',
])]
class SessionTemplate extends Model
{
    use HasFactory;

    protected function casts(): array
    {
        return [
            'day_of_week' => 'integer',
            'starts_at' => 'datetime:H:i',
            'ends_at' => 'datetime:H:i',
            'capacity' => 'integer',
            'valid_from' => 'date',
            'valid_until' => 'date',
            'is_active' => 'boolean',
            'legacy_deleted_at' => 'datetime',
        ];
    }

    public function environment(): BelongsTo
    {
        return $this->belongsTo(Environment::class);
    }

    public function legacyWeeklySchedule(): BelongsTo
    {
        return $this->belongsTo(WeeklySchedule::class, 'legacy_weekly_schedule_id');
    }

    public function occurrences(): HasMany
    {
        return $this->hasMany(SessionOccurrence::class);
    }
}
