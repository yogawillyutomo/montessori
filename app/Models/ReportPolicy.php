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
    'class_level_id',
    'name',
    'reporting_frequency',
    'minimum_observation_days',
    'minimum_attended_sessions',
    'require_guide_confirmation',
    'area_coverage_mode',
    'is_active',
])]
class ReportPolicy extends Model
{
    use HasFactory;

    public const FREQUENCIES = [
        'monthly',
        'bimonthly',
        'quarterly',
        'term',
        'custom',
    ];

    public const AREA_COVERAGE_MODES = [
        'off',
        'advisory',
        'required',
    ];

    private const HISTORICAL_FIELDS = [
        'class_level_id',
        'reporting_frequency',
        'minimum_observation_days',
        'minimum_attended_sessions',
        'require_guide_confirmation',
        'area_coverage_mode',
    ];

    protected static function booted(): void
    {
        static::saving(function (ReportPolicy $policy): void {
            $policy->name = trim((string) $policy->name);

            if ($policy->name === '') {
                throw ValidationException::withMessages([
                    'name' => 'Nama report policy wajib diisi.',
                ]);
            }

            if (! in_array($policy->reporting_frequency, self::FREQUENCIES, true)) {
                throw ValidationException::withMessages([
                    'reporting_frequency' => 'Reporting frequency tidak valid.',
                ]);
            }

            if ($policy->minimum_observation_days !== null && (int) $policy->minimum_observation_days < 0) {
                throw ValidationException::withMessages([
                    'minimum_observation_days' => 'Minimum observation days tidak boleh negatif.',
                ]);
            }

            if ($policy->minimum_attended_sessions !== null && (int) $policy->minimum_attended_sessions < 0) {
                throw ValidationException::withMessages([
                    'minimum_attended_sessions' => 'Minimum attended sessions tidak boleh negatif.',
                ]);
            }

            if ($policy->area_coverage_mode !== null
                && ! in_array($policy->area_coverage_mode, self::AREA_COVERAGE_MODES, true)) {
                throw ValidationException::withMessages([
                    'area_coverage_mode' => 'Area coverage mode tidak valid.',
                ]);
            }

            if ($policy->is_active) {
                $duplicateActivePolicy = self::query()
                    ->where('class_level_id', $policy->class_level_id)
                    ->where('is_active', true)
                    ->when($policy->exists, fn ($query) => $query->whereKeyNot($policy->getKey()))
                    ->exists();

                if ($duplicateActivePolicy) {
                    throw ValidationException::withMessages([
                        'is_active' => 'Program/level sudah memiliki report policy aktif.',
                    ]);
                }
            }

            if ($policy->exists && $policy->cycles()->exists()) {
                foreach (self::HISTORICAL_FIELDS as $field) {
                    if ($policy->isDirty($field)) {
                        throw ValidationException::withMessages([
                            $field => 'Konfigurasi report policy yang sudah memiliki cycle tidak boleh diubah retroaktif. Buat policy baru dan nonaktifkan policy lama.',
                        ]);
                    }
                }
            }
        });

        static::deleting(function (ReportPolicy $policy): void {
            if ($policy->cycles()->exists()) {
                throw new LogicException('Report policy yang sudah memiliki cycle tidak boleh dihapus. Nonaktifkan policy sebagai gantinya.');
            }
        });
    }

    protected function casts(): array
    {
        return [
            'minimum_observation_days' => 'integer',
            'minimum_attended_sessions' => 'integer',
            'require_guide_confirmation' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    public function classLevel(): BelongsTo
    {
        return $this->belongsTo(ClassLevel::class);
    }

    public function cycles(): HasMany
    {
        return $this->hasMany(ReportCycle::class)->orderBy('window_start');
    }
}
