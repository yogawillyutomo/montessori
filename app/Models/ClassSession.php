<?php

namespace App\Models;

use App\Services\Alpha\LegacyBookingBridgeService;
use App\Services\Alpha\LegacySessionBridgeService;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

#[Fillable(['weekly_schedule_id', 'school_class_id', 'teacher_id', 'room', 'capacity', 'session_date', 'starts_at', 'ends_at', 'topic', 'status', 'class_note', 'follow_up_recommendation', 'closed_by', 'closed_at'])]
class ClassSession extends Model
{
    use HasFactory;

    protected static function booted(): void
    {
        static::saved(function (ClassSession $session): void {
            app(LegacySessionBridgeService::class)->syncOccurrence($session);
            app(LegacyBookingBridgeService::class)->syncBookingsForSession($session);
        });

        static::deleted(function (ClassSession $session): void {
            app(LegacySessionBridgeService::class)->markOccurrenceLegacyDeleted($session);
            app(LegacyBookingBridgeService::class)->markBookingsForLegacySessionDeleted($session);
        });
    }

    protected function casts(): array
    {
        return [
            'session_date' => 'date',
            'starts_at' => 'datetime:H:i',
            'ends_at' => 'datetime:H:i',
            'closed_at' => 'datetime',
        ];
    }

    public function weeklySchedule(): BelongsTo
    {
        return $this->belongsTo(WeeklySchedule::class);
    }

    public function schoolClass(): BelongsTo
    {
        return $this->belongsTo(SchoolClass::class);
    }

    public function teacher(): BelongsTo
    {
        return $this->belongsTo(Teacher::class);
    }

    public function students(): BelongsToMany
    {
        return $this->belongsToMany(Student::class, 'class_session_student')
            ->using(ClassSessionStudent::class)
            ->withPivot('id')
            ->withTimestamps();
    }

    public function sessionOccurrence(): HasOne
    {
        return $this->hasOne(SessionOccurrence::class, 'legacy_class_session_id');
    }

    public function attendances(): HasMany
    {
        return $this->hasMany(Attendance::class);
    }

    public function observations(): HasMany
    {
        return $this->hasMany(Observation::class);
    }

    public function closedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'closed_by');
    }

    /**
     * @return array<string, int>
     */
    public function attendanceRecap(): array
    {
        $attendances = $this->attendances;
        $unmarkedCount = $attendances->where('status', 'unmarked')->count();
        $missingRows = max(0, $this->students->count() - $attendances->count());

        return [
            'total' => $this->students->count(),
            'present' => $attendances->where('status', 'present')->count(),
            'excused' => $attendances->where('status', 'excused')->count(),
            'sick' => $attendances->where('status', 'sick')->count(),
            'absent' => $attendances->where('status', 'absent')->count(),
            'late' => $attendances->where('status', 'late')->count(),
            'unmarked' => $unmarkedCount + $missingRows,
        ];
    }
}
