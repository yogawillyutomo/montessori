<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['name', 'slug', 'sequence', 'min_age_months', 'max_age_months', 'color', 'is_active'])]
class ClassLevel extends Model
{
    use HasFactory;

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'min_age_months' => 'decimal:2',
            'max_age_months' => 'decimal:2',
        ];
    }

    public function schoolClasses(): HasMany
    {
        return $this->hasMany(SchoolClass::class);
    }

    public function getAgeRangeLabelAttribute(): string
    {
        if ($this->min_age_months === null && $this->max_age_months === null) {
            return 'Rentang usia belum diisi';
        }

        $start = $this->min_age_months !== null ? $this->formatAgeMonth($this->min_age_months).' bulan' : '0 bulan';
        $end = $this->max_age_months !== null ? $this->formatAgeMonth($this->max_age_months).' bulan' : 'ke atas';

        return "{$start} - {$end}";
    }

    public function getMinAgeYearsAttribute(): ?string
    {
        return $this->monthsToYears($this->min_age_months);
    }

    public function getMaxAgeYearsAttribute(): ?string
    {
        return $this->monthsToYears($this->max_age_months);
    }

    public function getAgeRangeYearsLabelAttribute(): string
    {
        if ($this->min_age_months === null && $this->max_age_months === null) {
            return 'Rentang usia belum diisi';
        }

        $start = $this->min_age_years !== null ? "{$this->min_age_years} tahun" : '0 tahun';
        $end = $this->max_age_years !== null ? "{$this->max_age_years} tahun" : 'ke atas';

        return "{$start} - {$end}";
    }

    private function formatAgeMonth(string|float|int $value): string
    {
        return rtrim(rtrim(number_format((float) $value, 2, '.', ''), '0'), '.');
    }

    private function monthsToYears(null|string|float|int $value): ?string
    {
        if ($value === null) {
            return null;
        }

        return $this->formatAgeMonth((float) $value / 12);
    }
}
