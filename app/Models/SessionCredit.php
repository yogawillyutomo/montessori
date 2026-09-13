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
    'entitlement_period_id',
    'sequence_no',
    'origin_period_start',
    'status',
    'source_type',
    'source_adjustment_id',
    'voided_at',
    'voided_by_adjustment_id',
])]
class SessionCredit extends Model
{
    use HasFactory;

    public const STATUSES = [
        'available',
        'booked',
        'used',
        'forfeited',
    ];

    public const SOURCE_TYPES = [
        'base',
        'adjustment',
    ];

    protected static function booted(): void
    {
        static::saving(function (SessionCredit $credit): void {
            if ($credit->exists) {
                $dirty = array_keys($credit->getDirty());
                $allowed = ['status', 'voided_at', 'voided_by_adjustment_id'];
                $forbidden = array_diff($dirty, $allowed);

                if ($forbidden !== []) {
                    throw new LogicException('Identitas dan origin session credit bersifat immutable.');
                }
            }

            if (! in_array($credit->status, self::STATUSES, true)) {
                throw ValidationException::withMessages([
                    'status' => 'Status session credit tidak valid.',
                ]);
            }

            if (! in_array($credit->source_type, self::SOURCE_TYPES, true)) {
                throw ValidationException::withMessages([
                    'source_type' => 'Source type session credit tidak valid.',
                ]);
            }

            if ((int) $credit->sequence_no < 1) {
                throw ValidationException::withMessages([
                    'sequence_no' => 'Sequence session credit harus minimal satu.',
                ]);
            }

            $period = EntitlementPeriod::query()->find($credit->entitlement_period_id);
            if (! $period) {
                throw ValidationException::withMessages([
                    'entitlement_period_id' => 'Entitlement period untuk session credit tidak ditemukan.',
                ]);
            }

            if ($credit->origin_period_start?->toDateString() !== $period->period_start->toDateString()) {
                throw ValidationException::withMessages([
                    'origin_period_start' => 'Origin period session credit harus tetap sama dengan period asal entitlement.',
                ]);
            }

            if ($credit->source_type === 'base' && $credit->source_adjustment_id !== null) {
                throw ValidationException::withMessages([
                    'source_adjustment_id' => 'Base credit tidak boleh memiliki source adjustment.',
                ]);
            }

            if ($credit->source_type === 'adjustment') {
                $adjustment = EntitlementAdjustment::query()->find($credit->source_adjustment_id);

                if (! $adjustment
                    || (int) $adjustment->entitlement_period_id !== (int) $credit->entitlement_period_id
                    || (int) $adjustment->quantity_delta <= 0) {
                    throw ValidationException::withMessages([
                        'source_adjustment_id' => 'Adjustment credit harus berasal dari adjustment positif pada entitlement period yang sama.',
                    ]);
                }
            }

            if ($credit->voided_at !== null) {
                $voidingAdjustment = EntitlementAdjustment::query()->find($credit->voided_by_adjustment_id);

                if (! $voidingAdjustment
                    || (int) $voidingAdjustment->entitlement_period_id !== (int) $credit->entitlement_period_id
                    || (int) $voidingAdjustment->quantity_delta >= 0) {
                    throw ValidationException::withMessages([
                        'voided_by_adjustment_id' => 'Credit hanya boleh di-void oleh adjustment negatif pada period yang sama.',
                    ]);
                }

                if ($credit->status !== 'available') {
                    throw ValidationException::withMessages([
                        'status' => 'Hanya credit AVAILABLE yang boleh di-void.',
                    ]);
                }
            } elseif ($credit->voided_by_adjustment_id !== null) {
                throw ValidationException::withMessages([
                    'voided_by_adjustment_id' => 'Credit aktif tidak boleh menyimpan voiding adjustment.',
                ]);
            }
        });

        static::deleting(function (): never {
            throw new LogicException('Session credit adalah ledger service right dan tidak boleh dihapus.');
        });
    }

    protected function casts(): array
    {
        return [
            'sequence_no' => 'integer',
            'origin_period_start' => 'date',
            'voided_at' => 'datetime',
        ];
    }

    public function entitlementPeriod(): BelongsTo
    {
        return $this->belongsTo(EntitlementPeriod::class);
    }

    public function sourceAdjustment(): BelongsTo
    {
        return $this->belongsTo(EntitlementAdjustment::class, 'source_adjustment_id');
    }

    public function voidedByAdjustment(): BelongsTo
    {
        return $this->belongsTo(EntitlementAdjustment::class, 'voided_by_adjustment_id');
    }

    public function bookings(): HasMany
    {
        return $this->hasMany(ChildSessionBooking::class);
    }

    public function isActive(): bool
    {
        return $this->voided_at === null;
    }
}
