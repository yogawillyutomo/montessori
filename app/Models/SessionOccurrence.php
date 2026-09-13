<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'session_template_id',
    'environment_id',
    'occurs_on',
    'starts_at',
    'ends_at',
    'capacity',
    'room',
    'status',
    'opened_at',
    'completed_at',
    'completed_by',
    'cancellation_reason',
    'legacy_class_session_id',
    'legacy_weekly_schedule_id',
    'legacy_school_class_id',
    'legacy_teacher_id',
    'legacy_topic',
    'legacy_deleted_at',
])]
class SessionOccurrence extends Model
{
    use HasFactory;

    public const STATUSES = [
        'planned',
        'open',
        'completed',
        'cancelled',
    ];

    protected function casts(): array
    {
        return [
            'occurs_on' => 'date',
            'starts_at' => 'datetime:H:i',
            'ends_at' => 'datetime:H:i',
            'capacity' => 'integer',
            'opened_at' => 'datetime',
            'completed_at' => 'datetime',
            'legacy_deleted_at' => 'datetime',
        ];
    }

    public function sessionTemplate(): BelongsTo
    {
        return $this->belongsTo(SessionTemplate::class);
    }

    public function environment(): BelongsTo
    {
        return $this->belongsTo(Environment::class);
    }

    public function completedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'completed_by');
    }

    public function legacyClassSession(): BelongsTo
    {
        return $this->belongsTo(ClassSession::class, 'legacy_class_session_id');
    }

    public function bookings(): HasMany
    {
        return $this->hasMany(ChildSessionBooking::class);
    }

    public function activeBookings(): HasMany
    {
        return $this->hasMany(ChildSessionBooking::class)
            ->whereIn('status', ChildSessionBooking::ACTIVE_STATUSES)
            ->whereNotNull('active_on');
    }

    public function activeBookingCount(): int
    {
        return $this->activeBookings()->count();
    }

    public function availableCapacity(): ?int
    {
        if ($this->capacity === null) {
            return null;
        }

        return max(0, $this->capacity - $this->activeBookingCount());
    }
}
