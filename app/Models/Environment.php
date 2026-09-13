<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['class_level_id', 'name', 'code', 'age_range', 'capacity', 'is_active'])]
class Environment extends Model
{
    use HasFactory;

    protected function casts(): array
    {
        return [
            'capacity' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    public function classLevel(): BelongsTo
    {
        return $this->belongsTo(ClassLevel::class);
    }

    public function memberships(): HasMany
    {
        return $this->hasMany(EnvironmentMembership::class);
    }

    public function guideAssignments(): HasMany
    {
        return $this->hasMany(EnvironmentGuideAssignment::class);
    }
}
