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

            if ($period->period_start === null || $period->period_end === null || $period->period_end->lt($period->period_start)) {
                throw ValidationException::withMessages([
                    'period_end' => 'Rentang entitlement period tidak valid.',
                ]);
            }

            if ($period->quantity_source !== 'plan'
                && ($period->confirmed_by === null || $period->confirmed_at === null || trim((string) $period->confirmation_reason) === '')) {
                throw ValidationException::withMessages([
                    'confirmation_reason' => 'Entitlement quantity override harus memiliki actor, waktu, dan alasan konfirmasi.',
                ]);
            }
        });

        static::updating(function (EntitlementPeriod $period): void {
            $dirty = array_keys($period->getDirty());
            $forbidden = array_diff($dirty, ['status']);

            if ($forbidden !== []) {
                throw new LogicException('Entitlement period adalah snapshot historis; quantity, plan, dan periodenya tidak boleh ditulis ulang. Gunakan adjustment ledger.');
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
