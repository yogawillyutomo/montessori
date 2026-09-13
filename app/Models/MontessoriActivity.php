<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'development_area_id',
    'code',
    'name',
    'description',
    'direct_aim',
    'indirect_aim',
    'sequence_order',
    'is_active',
])]
class MontessoriActivity extends Model
{
    use HasFactory;

    protected function casts(): array
    {
        return [
            'sequence_order' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    public function developmentArea(): BelongsTo
    {
        return $this->belongsTo(DevelopmentArea::class);
    }

    public function presentations(): HasMany
    {
        return $this->hasMany(Presentation::class);
    }

    public function developmentProgress(): HasMany
    {
        return $this->hasMany(DevelopmentProgress::class);
    }
}
