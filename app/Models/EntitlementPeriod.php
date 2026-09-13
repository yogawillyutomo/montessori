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
    'enrollment_plan_assignment_id',
    'period_start',
    'period_end',
    'base_quantity',
    'quantity_source',
    'confirmation_reason',
    'confirmed_by',
    'confirmed_at',
    'status',
    'cancellation_reason',
    'cancelled_by',
    'cancelled_at',
    'created_by',
])]
class EntitlementPeriod extends Model
{
    use HasFactory;

    public const STATUSES = [
        'open',
        'closed',
        'cancelled',
    ];

    public const QUANTITY_SOURCES = [
        'plan',
        'confirmed_partial_period',
        'confirmed_override',
    ];

    protected static function booted(): void
    {
        static::saving(function (EntitlementPeriod $period): void {
            if ($period->exists) {
                $originalStatus = (string) $period->getOriginal('status');
                if (in_array($originalStatus, ['closed', 'cancelled'], true)
                    && $period->isDirty('status')) {
                    throw new LogicException('Entitlement period CLOSED/CANCELLED adalah state final dan tidak boleh dibuka kembali.');
                }

                $dirty = array_keys($period->getDirty());
                $allowed = ['status', 'cancellation_reason', 'cancelled_by', 'cancelled_at'];
                $forbidden = array_diff($dirty, $allowed);

                if ($forbidden !== []) {
                    throw new LogicException('Entitlement period adalah snapshot historis; quantity, plan, dan periodenya tidak boleh ditulis ulang. Gunakan adjustment ledger.');
                }
            }

            if (! in_array($period->status, self::STATUSES, true)) {
                throw ValidationException::withMessages([
                    'status' => 'Status entitlement period tidak valid.',
                ]);
            }

            if (! in_array($period->quantity_source, self::QUANTITY_SOURCES, true)) {
                throw ValidationException::withMessages([
                    'quantity_source' => 'Sumber quantity entitlement tidak valid.',
                ]);
            }

            if ((int) $period->base_quantity < 0) {
                throw ValidationException::withMessages([
                    'base_quantity' => 'Base entitlement quantity tidak boleh negatif.',
                ]);
            }

            if ($period->period_start === null || $period->period_end === null || $period->period_end->lt($period->period_start)) {
                throw ValidationException::withMessages([
                    'period_end' => 'Rentang entitlement period tidak valid.',
                ]);
            }

            $enrollment = ChildEnrollment::query()->find($period->child_enrollment_id);
            $assignment = EnrollmentPlanAssignment::query()->find($period->enrollment_plan_assignment_id);
            $plan = SessionPlan::query()->find($period->session_plan_id);

            if (! $enrollment || ! $assignment || ! $plan) {
                throw ValidationException::withMessages([
                    'child_enrollment_id' => 'Enrollment, plan assignment, atau session plan entitlement period tidak ditemukan.',
                ]);
            }

            if ((int) $assignment->child_enrollment_id !== (int) $enrollment->id
                || (int) $assignment->session_plan_id !== (int) $plan->id) {
                throw ValidationException::withMessages([
                    'enrollment_plan_assignment_id' => 'Plan assignment entitlement period tidak konsisten.',
                ]);
            }

            if ($period->status !== 'cancelled' && $assignment->cancelled_at !== null) {
                throw ValidationException::withMessages([
                    'enrollment_plan_assignment_id' => 'Entitlement period aktif tidak boleh menunjuk plan assignment yang sudah dibatalkan.',
                ]);
            }

            if ($enrollment->starts_on->gt($period->period_end)
                || ($enrollment->ends_on !== null && $enrollment->ends_on->lt($period->period_start))) {
                throw ValidationException::withMessages([
                    'period_start' => 'Entitlement period harus beririsan dengan periode enrollment anak.',
                ]);
            }

            if ($assignment->valid_from->gt($period->period_end)
                || ($assignment->valid_until !== null && $assignment->valid_until->lt($period->period_start))) {
                throw ValidationException::withMessages([
                    'enrollment_plan_assignment_id' => 'Plan assignment harus beririsan dengan entitlement period.',
                ]);
            }

            if ($period->quantity_source === 'plan') {
                if ((int) $period->base_quantity !== (int) $plan->entitlement_quantity) {
                    throw ValidationException::withMessages([
                        'base_quantity' => 'Quantity bersumber dari plan harus sama dengan entitlement quantity plan.',
                    ]);
                }

                $period->confirmation_reason = null;
                $period->confirmed_by = null;
                $period->confirmed_at = null;
            } elseif ($period->confirmed_by === null
                || $period->confirmed_at === null
                || trim((string) $period->confirmation_reason) === '') {
                throw ValidationException::withMessages([
                    'confirmation_reason' => 'Entitlement quantity override harus memiliki actor, waktu, dan alasan konfirmasi.',
                ]);
            }

            if ($period->status === 'cancelled') {
                if ($period->cancelled_by === null
                    || $period->cancelled_at === null
                    || trim((string) $period->cancellation_reason) === '') {
                    throw ValidationException::withMessages([
                        'cancellation_reason' => 'Entitlement period cancelled harus memiliki actor, waktu, dan alasan.',
                    ]);
                }

                if ($period->exists && $period->committedCreditCount() > 0) {
                    throw ValidationException::withMessages([
                        'status' => 'Entitlement period dengan credit BOOKED/USED/FORFEITED tidak boleh dibatalkan sebelum rekonsiliasi.',
                    ]);
                }
            } else {
                $period->cancellation_reason = null;
                $period->cancelled_by = null;
                $period->cancelled_at = null;
            }
        });

        static::deleting(function (): never {
            throw new LogicException('Entitlement period tidak boleh dihapus. Gunakan status dan adjustment ledger.');
        });
    }

    protected function casts(): array
    {
        return [
            'period_start' => 'date',
            'period_end' => 'date',
            'base_quantity' => 'integer',
            'confirmed_at' => 'datetime',
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

    public function enrollmentPlanAssignment(): BelongsTo
    {
        return $this->belongsTo(EnrollmentPlanAssignment::class);
    }

    public function confirmedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'confirmed_by');
    }

    public function cancelledBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cancelled_by');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function credits(): HasMany
    {
        return $this->hasMany(SessionCredit::class)->orderBy('sequence_no');
    }

    public function adjustments(): HasMany
    {
        return $this->hasMany(EntitlementAdjustment::class)->orderBy('created_at');
    }

    public function effectiveQuantity(): int
    {
        return (int) $this->base_quantity + (int) $this->adjustments()->sum('quantity_delta');
    }

    public function activeCreditCount(): int
    {
        return $this->credits()->whereNull('voided_at')->count();
    }

    public function availableCreditCount(): int
    {
        return $this->credits()
            ->whereNull('voided_at')
            ->where('status', 'available')
            ->count();
    }

    public function committedCreditCount(): int
    {
        return $this->credits()
            ->whereNull('voided_at')
            ->whereIn('status', ['booked', 'used', 'forfeited'])
            ->count();
    }
}
