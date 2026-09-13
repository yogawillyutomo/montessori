<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['student_id', 'teacher_id', 'responsibility_type', 'valid_from', 'valid_until', 'is_active'])]
class ChildGuideResponsibility extends Model
{
    use HasFactory;

    public const TYPES = [
        'primary_guide',
        'report_owner',
    ];

    protected function casts(): array
    {
        return [
            'valid_from' => 'date',
            'valid_until' => 'date',
            'is_active' => 'boolean',
        ];
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
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
