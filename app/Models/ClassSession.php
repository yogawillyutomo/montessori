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

#[Fillable(['weekly_schedule_id', 'school_class_id', 'teacher_id', 'room', 'capacity', 'session_date', 'starts_at', 'ends_at', 'topic', 'status', 'class_note', 'follow_up_recommendation', 'closed_by', 'closed_at'])]
class ClassSession extends Model
{
    use HasFactory;

    protected static function booted(): void
    {
        static::updating(function (ClassSession $session): void {
            if ($session->status === 'cancelled') {
                return;
            }

            $studentIds = $session->students()->pluck('students.id')->map(fn ($id): int => (int) $id)->all();
            if ($studentIds === []) {
                return;
            }

            if ($session->capacity !== null && count($studentIds) > (int) $session->capacity) {
                throw ValidationException::withMessages([
                    'capacity' => "Kapasitas sesi ({$session->capacity}) lebih kecil dari jumlah anak yang sudah terdaftar.",
                ]);
            }

            $conflict = DB::table('class_session_student as css')
                ->join('class_sessions as cs', 'cs.id', '=', 'css.class_session_id')
                ->whereIn('css.student_id', $studentIds)
                ->whereDate('cs.session_date', $session->session_date->toDateString())
                ->where('cs.status', '!=', 'cancelled')
                ->where('cs.id', '!=', $session->id)
                ->exists();

            if ($conflict) {
                throw ValidationException::withMessages([
                    'student_ids' => 'Ada anak yang sudah memiliki sesi aktif lain pada tanggal yang sama.',
                ]);
            }
        });

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
