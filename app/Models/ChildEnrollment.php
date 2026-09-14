<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Validation\ValidationException;
use LogicException;

#[Fillable([
    'student_id',
    'class_level_id',
    'previous_enrollment_id',
    'starts_on',
    'ends_on',
    'status',
    'first_session_on',
    'suspended_from',
    'suspended_until',
    'suspension_reason',
    'ended_reason',
    'created_by',
])]
class ChildEnrollment extends Model
{
    use HasFactory;

    public const STATUSES = [
        'active',
        'suspended',
        'ended',
    ];

    protected static function booted(): void
    {
        static::saving(function (ChildEnrollment $enrollment): void {
            if (! in_array($enrollment->status, self::STATUSES, true)) {
                throw ValidationException::withMessages([
                    'status' => 'Status enrollment tidak valid.',
                ]);
            }

            $start = $enrollment->starts_on?->toDateString();
            $end = $enrollment->ends_on?->toDateString();

            if (! $start) {
                throw ValidationException::withMessages([
                    'starts_on' => 'Tanggal mulai enrollment wajib diisi.',
                ]);
            }

            if ($end !== null && $end < $start) {
                throw ValidationException::withMessages([
                    'ends_on' => 'Tanggal akhir enrollment tidak boleh sebelum tanggal mulai.',
                ]);
            }

            if ($enrollment->status === 'ended' && $end === null) {
                throw ValidationException::withMessages([
                    'ends_on' => 'Enrollment berstatus ended harus memiliki tanggal akhir.',
                ]);
            }

            if ($enrollment->first_session_on !== null) {
                $firstSession = $enrollment->first_session_on->toDateString();

                if ($firstSession < $start || ($end !== null && $firstSession > $end)) {
                    throw ValidationException::withMessages([
                        'first_session_on' => 'First session harus berada di dalam periode enrollment.',
                    ]);
                }
            }

            if ($enrollment->status === 'suspended' && $enrollment->suspended_from === null) {
                throw ValidationException::withMessages([
                    'suspended_from' => 'Enrollment suspended harus memiliki tanggal mulai suspension.',
                ]);
            }

            if ($enrollment->suspended_from !== null) {
                $suspendedFrom = $enrollment->suspended_from->toDateString();
                $suspendedUntil = $enrollment->suspended_until?->toDateString();

                if ($suspendedFrom < $start || ($end !== null && $suspendedFrom > $end)) {
                    throw ValidationException::withMessages([
                        'suspended_from' => 'Tanggal suspension harus berada di dalam periode enrollment.',
                    ]);
                }

                if ($suspendedUntil !== null && $suspendedUntil < $suspendedFrom) {
                    throw ValidationException::withMessages([
                        'suspended_until' => 'Tanggal akhir suspension tidak boleh sebelum tanggal mulai.',
                    ]);
                }

                if ($end !== null && $suspendedUntil !== null && $suspendedUntil > $end) {
                    throw ValidationException::withMessages([
                        'suspended_until' => 'Tanggal akhir suspension tidak boleh melewati akhir enrollment.',
                    ]);
                }
            }

            if ($enrollment->previous_enrollment_id !== null
                && (int) $enrollment->previous_enrollment_id === (int) $enrollment->id) {
                throw ValidationException::withMessages([
                    'previous_enrollment_id' => 'Enrollment tidak dapat menunjuk dirinya sendiri sebagai enrollment sebelumnya.',
                ]);
            }

            $overlap = self::query()
                ->where('student_id', $enrollment->student_id)
                ->whereDate('starts_on', '<=', $end ?? '9999-12-31')
                ->where(function ($query) use ($start): void {
                    $query->whereNull('ends_on')
                        ->orWhereDate('ends_on', '>=', $start);
                })
                ->when($enrollment->exists, fn ($query) => $query->whereKeyNot($enrollment->getKey()))
                ->exists();

            if ($overlap) {
                throw ValidationException::withMessages([
                    'starts_on' => 'Periode enrollment anak tidak boleh overlap dengan enrollment lain.',
                ]);
            }
        });

        static::deleting(function (): never {
            throw new LogicException('Child enrollment adalah histori layanan dan tidak boleh dihapus. Gunakan status ended.');
        });
    }

    protected function casts(): array
    {
        return [
            'starts_on' => 'date',
            'ends_on' => 'date',
            'first_session_on' => 'date',
            'suspended_from' => 'date',
            'suspended_until' => 'date',
        ];
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    public function classLevel(): BelongsTo
    {
        return $this->belongsTo(ClassLevel::class);
    }

    public function previousEnrollment(): BelongsTo
    {
        return $this->belongsTo(self::class, 'previous_enrollment_id');
    }

    public function nextEnrollments(): HasMany
    {
        return $this->hasMany(self::class, 'previous_enrollment_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function planAssignments(): HasMany
    {
        return $this->hasMany(EnrollmentPlanAssignment::class)->orderBy('valid_from');
    }

    public function entitlementPeriods(): HasMany
    {
        return $this->hasMany(EntitlementPeriod::class)->orderBy('period_start');
    }

    public function recurringSchedules(): HasMany
    {
        return $this->hasMany(RecurringSchedule::class);
    }

    public function sessionBookings(): HasMany
    {
        return $this->hasMany(ChildSessionBooking::class);
    }

    public function reportEligibilities(): HasMany
    {
        return $this->hasMany(ReportEligibility::class)->orderBy('evaluated_at');
    }

    public function coversDate(string $date): bool
    {
        return $this->starts_on->toDateString() <= $date
            && ($this->ends_on === null || $this->ends_on->toDateString() >= $date);
    }

    public function isSuspendedOn(string $date): bool
    {
        if ($this->suspended_from === null) {
            return false;
        }

        return $this->suspended_from->toDateString() <= $date
            && ($this->suspended_until === null || $this->suspended_until->toDateString() >= $date);
    }
}
