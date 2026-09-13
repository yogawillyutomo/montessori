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
    'name',
    'code',
    'class_level_id',
    'entitlement_quantity',
    'entitlement_period',
    'preferred_weekly_frequency',
    'makeup_policy',
    'rollover_policy',
    'is_default',
    'is_active',
])]
class SessionPlan extends Model
{
    use HasFactory;

    public const PERIODS = [
        'monthly',
        'term',
        'custom',
    ];

    private const HISTORICAL_FIELDS = [
        'class_level_id',
        'entitlement_quantity',
        'entitlement_period',
        'preferred_weekly_frequency',
        'makeup_policy',
        'rollover_policy',
    ];

    protected static function booted(): void
    {
        static::saving(function (SessionPlan $plan): void {
            if ((int) $plan->entitlement_quantity < 1) {
                throw ValidationException::withMessages([
                    'entitlement_quantity' => 'Jumlah entitlement harus minimal satu sesi.',
                ]);
            }

            if (! in_array($plan->entitlement_period, self::PERIODS, true)) {
                throw ValidationException::withMessages([
                    'entitlement_period' => 'Periode entitlement session plan tidak valid.',
                ]);
            }

            if ($plan->preferred_weekly_frequency !== null && (int) $plan->preferred_weekly_frequency < 1) {
                throw ValidationException::withMessages([
                    'preferred_weekly_frequency' => 'Preferred weekly frequency harus minimal satu bila diisi.',
                ]);
            }

            if ($plan->is_default && $plan->class_level_id === null) {
                throw ValidationException::withMessages([
                    'is_default' => 'Default session plan harus terkait dengan satu program/level.',
                ]);
            }

            if ($plan->is_default && $plan->is_active) {
                $duplicateDefault = self::query()
                    ->where('class_level_id', $plan->class_level_id)
                    ->where('is_default', true)
                    ->where('is_active', true)
                    ->when($plan->exists, fn ($query) => $query->whereKeyNot($plan->getKey()))
                    ->exists();

                if ($duplicateDefault) {
                    throw ValidationException::withMessages([
                        'is_default' => 'Program/level sudah memiliki default session plan aktif.',
                    ]);
                }
            }

            if ($plan->exists && $plan->assignments()->exists()) {
                foreach (self::HISTORICAL_FIELDS as $field) {
                    if ($plan->isDirty($field)) {
                        throw ValidationException::withMessages([
                            $field => 'Konfigurasi entitlement plan yang sudah pernah dipakai tidak boleh diubah. Buat plan baru dan effective-dated assignment baru.',
                        ]);
                    }
                }
            }
        });

        static::deleting(function (SessionPlan $plan): void {
            if ($plan->assignments()->exists() || $plan->entitlementPeriods()->exists()) {
                throw new LogicException('Session plan yang sudah memiliki assignment atau entitlement period tidak boleh dihapus. Nonaktifkan plan sebagai gantinya.');
            }
        });
    }

    protected function casts(): array
    {
        return [
            'entitlement_quantity' => 'integer',
            'preferred_weekly_frequency' => 'integer',
            'makeup_policy' => 'array',
            'rollover_policy' => 'array',
            'is_default' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    public function classLevel(): BelongsTo
    {
        return $this->belongsTo(ClassLevel::class);
    }

    public function assignments(): HasMany
    {
        return $this->hasMany(EnrollmentPlanAssignment::class);
    }

    public function entitlementPeriods(): HasMany
    {
        return $this->hasMany(EntitlementPeriod::class);
    }
}
