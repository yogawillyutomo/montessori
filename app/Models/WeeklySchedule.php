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
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

#[Fillable(['school_class_id', 'teacher_id', 'room', 'capacity', 'day_of_week', 'starts_at', 'ends_at', 'topic', 'is_active'])]
class WeeklySchedule extends Model
{
    use HasFactory;

    protected static function booted(): void
    {
        static::updating(function (WeeklySchedule $schedule): void {
            if (! $schedule->is_active) {
                return;
            }

            $studentIds = $schedule->students()->pluck('students.id')->map(fn ($id): int => (int) $id)->all();
            if ($studentIds === []) {
                return;
            }

            if ($schedule->capacity !== null && count($studentIds) > (int) $schedule->capacity) {
                throw ValidationException::withMessages([
                    'capacity' => "Kapasitas slot ({$schedule->capacity}) lebih kecil dari jumlah anak yang sudah terdaftar.",
                ]);
            }

            $conflict = DB::table('student_weekly_schedule as sws')
                ->join('weekly_schedules as ws', 'ws.id', '=', 'sws.weekly_schedule_id')
                ->whereIn('sws.student_id', $studentIds)
                ->where('ws.is_active', true)
                ->where('ws.day_of_week', $schedule->day_of_week)
                ->where('ws.id', '!=', $schedule->id)
                ->exists();

            if ($conflict) {
                throw ValidationException::withMessages([
                    'student_ids' => 'Ada anak yang sudah memiliki slot mingguan aktif lain pada hari yang sama.',
                ]);
            }
        });

        static::saved(function (WeeklySchedule $schedule): void {
            app(LegacySessionBridgeService::class)->syncTemplate($schedule);
            app(LegacyBookingBridgeService::class)->syncRecurringSchedulesForSchedule($schedule);
        });

        static::deleted(function (WeeklySchedule $schedule): void {
            app(LegacySessionBridgeService::class)->markTemplateLegacyDeleted($schedule);
            app(LegacyBookingBridgeService::class)->markRecurringSchedulesForLegacyScheduleDeleted($schedule);
        });
    }

    protected function casts(): array
    {
        return [
            'starts_at' => 'datetime:H:i',
            'ends_at' => 'datetime:H:i',
            'is_active' => 'boolean',
        ];
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
        return $this->belongsToMany(Student::class, 'student_weekly_schedule')
            ->using(StudentWeeklySchedule::class)
            ->withPivot('id')
            ->withTimestamps();
    }

    public function classSessions(): HasMany
    {
        return $this->hasMany(ClassSession::class);
    }

    public function sessionTemplate(): HasOne
    {
        return $this->hasOne(SessionTemplate::class, 'legacy_weekly_schedule_id');
    }
}
