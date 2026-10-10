<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;

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
    'cancelled_by',
    'cancelled_at',
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
            'cancelled_at' => 'datetime',
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

    public function cancelledBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cancelled_by');
    }

    public function schoolClass(): BelongsTo
    {
        return $this->belongsTo(SchoolClass::class, 'legacy_school_class_id');
    }

    public function teacher(): BelongsTo
    {
        return $this->belongsTo(Teacher::class, 'legacy_teacher_id');
    }

    public function students(): BelongsToMany
    {
        return $this->belongsToMany(Student::class, 'child_session_bookings', 'session_occurrence_id', 'student_id')
            ->wherePivotIn('status', ['scheduled', 'session_cancelled'])
            ->wherePivotNull('legacy_deleted_at')
            ->withPivot(['id', 'status', 'active_on']);
    }

    public function attendances(): HasManyThrough
    {
        return $this->hasManyThrough(
            Attendance::class,
            ChildSessionBooking::class,
            'session_occurrence_id',
            'child_session_booking_id',
            'id',
            'id',
        );
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

    public function presentations(): HasMany
    {
        return $this->hasMany(Presentation::class);
    }

    public function observations(): HasMany
    {
        return $this->hasMany(Observation::class);
    }

    /** @return array<string, int> */
    public function attendanceRecap(): array
    {
        $attendances = $this->attendances;
        $studentCount = $this->students->count();
        $unmarkedCount = $attendances->where('status', 'unmarked')->count();
        $missingRows = max(0, $studentCount - $attendances->count());

        return [
            'total' => $studentCount,
            'present' => $attendances->where('status', 'present')->count(),
            'excused' => $attendances->where('status', 'excused')->count(),
            'sick' => $attendances->where('status', 'sick')->count(),
            'absent' => $attendances->where('status', 'absent')->count(),
            'late' => $attendances->where('status', 'late')->count(),
            'unmarked' => $unmarkedCount + $missingRows,
        ];
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
