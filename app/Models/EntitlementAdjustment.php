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
    'quantity_delta',
    'reason_code',
    'note',
    'created_by',
    'reversal_of_adjustment_id',
])]
class EntitlementAdjustment extends Model
{
    use HasFactory;

    public const UPDATED_AT = null;

    protected static function booted(): void
    {
        static::creating(function (EntitlementAdjustment $adjustment): void {
            if ((int) $adjustment->quantity_delta === 0) {
                throw ValidationException::withMessages([
                    'quantity_delta' => 'Entitlement adjustment tidak boleh bernilai nol.',
                ]);
            }

            if (trim((string) $adjustment->reason_code) === '') {
                throw ValidationException::withMessages([
                    'reason_code' => 'Reason code entitlement adjustment wajib diisi.',
                ]);
            }

            if ($adjustment->reversal_of_adjustment_id !== null) {
                $reversed = self::query()->find($adjustment->reversal_of_adjustment_id);

                if (! $reversed
                    || (int) $reversed->entitlement_period_id !== (int) $adjustment->entitlement_period_id
                    || (int) $reversed->quantity_delta !== -1 * (int) $adjustment->quantity_delta) {
                    throw ValidationException::withMessages([
                        'reversal_of_adjustment_id' => 'Reversal harus membalik adjustment pada entitlement period yang sama dengan delta kebalikan.',
                    ]);
                }

                if ($reversed->reversal_of_adjustment_id !== null || $reversed->reversals()->exists()) {
                    throw ValidationException::withMessages([
                        'reversal_of_adjustment_id' => 'Adjustment hanya boleh direversal satu kali dan reversal tidak dapat direversal kembali.',
                    ]);
                }
            }
        });

        static::updating(function (): never {
            throw new LogicException('Entitlement adjustment adalah ledger immutable dan tidak boleh diubah.');
        });

        static::deleting(function (): never {
            throw new LogicException('Entitlement adjustment adalah ledger immutable dan tidak boleh dihapus. Buat reversal adjustment.');
        });
    }

    protected function casts(): array
    {
        return [
            'quantity_delta' => 'integer',
            'created_at' => 'datetime',
        ];
    }

    public function entitlementPeriod(): BelongsTo
    {
        return $this->belongsTo(EntitlementPeriod::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function reversalOf(): BelongsTo
    {
        return $this->belongsTo(self::class, 'reversal_of_adjustment_id');
    }

    public function reversals(): HasMany
    {
        return $this->hasMany(self::class, 'reversal_of_adjustment_id');
    }

    public function createdCredits(): HasMany
    {
        return $this->hasMany(SessionCredit::class, 'source_adjustment_id');
    }

    public function voidedCredits(): HasMany
    {
        return $this->hasMany(SessionCredit::class, 'voided_by_adjustment_id');
    }
}
