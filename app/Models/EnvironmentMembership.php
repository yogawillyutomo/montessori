<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['environment_id', 'student_id', 'valid_from', 'valid_until', 'status'])]
class EnvironmentMembership extends Model
{
    use HasFactory;

    protected function casts(): array
    {
        return [
            'valid_from' => 'date',
            'valid_until' => 'date',
        ];
    }

    public function environment(): BelongsTo
    {
        return $this->belongsTo(Environment::class);
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    public function scopeActiveOn(Builder $query, string $date): Builder
    {
        return $query
            ->where('status', 'active')
            ->whereDate('valid_from', '<=', $date)
            ->where(function (Builder $periodQuery) use ($date): void {
                $periodQuery->whereNull('valid_until')->orWhereDate('valid_until', '>=', $date);
            });
    }
}
