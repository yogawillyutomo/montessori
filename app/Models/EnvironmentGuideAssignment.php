<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['environment_id', 'teacher_id', 'assignment_role', 'valid_from', 'valid_until', 'is_active'])]
class EnvironmentGuideAssignment extends Model
{
    use HasFactory;

    public const ROLES = [
        'lead_guide',
        'guide',
        'assistant',
        'specialist',
    ];

    protected function casts(): array
    {
        return [
            'valid_from' => 'date',
            'valid_until' => 'date',
            'is_active' => 'boolean',
        ];
    }

    public function environment(): BelongsTo
    {
        return $this->belongsTo(Environment::class);
    }

    public function teacher(): BelongsTo
    {
        return $this->belongsTo(Teacher::class);
    }

    public function scopeActiveOn(Builder $query, string $date): Builder
    {
        return $query
            ->where('is_active', true)
            ->whereDate('valid_from', '<=', $date)
            ->where(function (Builder $periodQuery) use ($date): void {
                $periodQuery->whereNull('valid_until')->orWhereDate('valid_until', '>=', $date);
            });
    }
}
