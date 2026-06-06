<?php

namespace App\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['school_class_id', 'guardian_id', 'code', 'name', 'gender', 'birth_place', 'birth_date', 'status', 'medical_notes'])]
class Student extends Model
{
    use HasFactory;

    protected function casts(): array
    {
        return [
            'birth_date' => 'date',
            'medical_notes' => 'array',
        ];
    }

    public function schoolClass(): BelongsTo
    {
        return $this->belongsTo(SchoolClass::class);
    }

    public function guardian(): BelongsTo
    {
        return $this->belongsTo(Guardian::class);
    }

    public function weeklySchedules(): BelongsToMany
    {
        return $this->belongsToMany(WeeklySchedule::class, 'student_weekly_schedule')->withTimestamps();
    }

    public function classSessions(): BelongsToMany
    {
        return $this->belongsToMany(ClassSession::class, 'class_session_student')->withTimestamps();
    }

    public function attendances(): HasMany
    {
        return $this->hasMany(Attendance::class);
    }

    public function observations(): HasMany
    {
        return $this->hasMany(Observation::class);
    }

    public function ilpPlans(): HasMany
    {
        return $this->hasMany(IlpPlan::class);
    }

    public function reports(): HasMany
    {
        return $this->hasMany(Report::class);
    }

    public function getAgeLabelAttribute(): string
    {
        if (! $this->birth_date instanceof CarbonInterface) {
            return '-';
        }

        $diff = $this->birth_date->diff(now());

        return "{$diff->y} tahun {$diff->m} bulan";
    }
}
