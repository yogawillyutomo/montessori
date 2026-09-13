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
    'child_enrollment_id',
    'session_plan_id',
    'valid_from',
    'valid_until',
    'assignment_type',
    'reason',
    'created_by',
    'cancelled_at',
    'cancelled_by',
    'cancellation_reason',
])]
class EnrollmentPlanAssignment extends Model
{
    use HasFactory;

    public const TYPES = [
        'default',
        'override',
        'change',
    ];

    protected static function booted(): void
    {
        static::saving(function (EnrollmentPlanAssignment $assignment): void {
            if (! in_array($assignment->assignment_type, self::TYPES, true)) {
                throw ValidationException::withMessages([
                    'assignment_type' => 'Tipe plan assignment tidak valid.',
                ]);
            }

            if (in_array($assignment->assignment_type, ['override', 'change'], true)
                && trim((string) $assignment->reason) === '') {
                throw ValidationException::withMessages([
                    'reason' => 'Alasan wajib dicatat untuk override atau plan change.',
                ]);
            }

            if ($assignment->cancelled_at !== null
                && ($assignment->cancelled_by === null || trim((string) $assignment->cancellation_reason) === '')) {
                throw ValidationException::withMessages([
                    'cancellation_reason' => 'Pembatalan future plan assignment harus memiliki actor dan alasan.',
                ]);
            }

            $enrollment = ChildEnrollment::query()->find($assignment->child_enrollment_id);
            $plan = SessionPlan::query()->find($assignment->session_plan_id);

            if (! $enrollment || ! $plan) {
                throw ValidationException::withMessages([
                    'session_plan_id' => 'Enrollment atau session plan tidak ditemukan.',
                ]);
            }

            if (! $assignment->exists && ! $plan->is_active) {
                throw ValidationException::withMessages([
                    'session_plan_id' => 'Session plan nonaktif tidak dapat dipakai untuk assignment baru.',
                ]);
            }

            if ($plan->class_level_id !== null
                && (int) $plan->class_level_id !== (int) $enrollment->class_level_id) {
                throw ValidationException::withMessages([
                    'session_plan_id' => 'Session plan tidak sesuai dengan program/level enrollment anak.',
                ]);
            }

            $validFrom = $assignment->valid_from?->toDateString();
            $validUntil = $assignment->valid_until?->toDateString();

            if (! $validFrom) {
                throw ValidationException::withMessages([
                    'valid_from' => 'Tanggal mulai plan assignment wajib diisi.',
                ]);
            }

            if (! $enrollment->coversDate($validFrom)) {
                throw ValidationException::withMessages([
                    'valid_from' => 'Plan assignment harus mulai di dalam periode enrollment.',
                ]);
            }

            if ($validUntil !== null) {
                if ($validUntil < $validFrom) {
                    throw ValidationException::withMessages([
                        'valid_until' => 'Tanggal akhir plan assignment tidak boleh sebelum tanggal mulai.',
                    ]);
                }

                if (! $enrollment->coversDate($validUntil)) {
                    throw ValidationException::withMessages([
                        'valid_until' => 'Plan assignment harus berakhir di dalam periode enrollment.',
                    ]);
                }
            }

            if ($assignment->cancelled_at !== null) {
                return;
            }

            $overlap = self::query()
                ->where('child_enrollment_id', $assignment->child_enrollment_id)
                ->whereNull('cancelled_at')
                ->whereDate('valid_from', '<=', $validUntil ?? '9999-12-31')
                ->where(function ($query) use ($validFrom): void {
                    $query->whereNull('valid_until')
                        ->orWhereDate('valid_until', '>=', $validFrom);
                })
                ->when($assignment->exists, fn ($query) => $query->whereKeyNot($assignment->getKey()))
                ->exists();

            if ($overlap) {
                throw ValidationException::withMessages([
                    'valid_from' => 'Periode plan assignment tidak boleh overlap dengan assignment lain pada enrollment yang sama.',
                ]);
            }
        });

        static::updating(function (EnrollmentPlanAssignment $assignment): void {
            $dirty = array_keys($assignment->getDirty());
            $allowed = ['valid_until', 'cancelled_at', 'cancelled_by', 'cancellation_reason'];
            $forbidden = array_diff($dirty, $allowed);

            if ($forbidden !== []) {
                throw new LogicException('Plan assignment bersifat historis; plan/period tidak boleh ditulis ulang. Tutup atau batalkan future assignment secara eksplisit.');
            }
        });

        static::deleting(function (): never {
            throw new LogicException('Plan assignment adalah histori effective-dated dan tidak boleh dihapus.');
        });
    }

    protected function casts(): array
    {
        return [
            'valid_from' => 'date',
            'valid_until' => 'date',
            'cancelled_at' => 'datetime',
        ];
    }

    public function childEnrollment(): BelongsTo
    {
        return $this->belongsTo(ChildEnrollment::class);
    }

    public function sessionPlan(): BelongsTo
    {
        return $this->belongsTo(SessionPlan::class);
    }

    public function entitlementPeriods(): HasMany
    {
        return $this->hasMany(EntitlementPeriod::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function cancelledBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cancelled_by');
    }
}
