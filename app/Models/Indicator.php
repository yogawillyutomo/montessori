<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['development_area_id', 'code', 'sub_area', 'description', 'level', 'is_active'])]
class Indicator extends Model
{
    use HasFactory;

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    public function developmentArea(): BelongsTo
    {
        return $this->belongsTo(DevelopmentArea::class);
    }

    public function observations(): HasMany
    {
        return $this->hasMany(Observation::class);
    }

    public function ilpPlans(): HasMany
    {
        return $this->hasMany(IlpPlan::class);
    }
}
