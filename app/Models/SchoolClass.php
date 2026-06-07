<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection;

#[Fillable(['class_level_id', 'name', 'slug', 'level', 'age_range', 'capacity', 'color', 'is_active'])]
class SchoolClass extends Model
{
    use HasFactory;

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    public function students(): HasMany
    {
        return $this->hasMany(Student::class);
    }

    public function classLevel(): BelongsTo
    {
        return $this->belongsTo(ClassLevel::class);
    }

    public function weeklySchedules(): HasMany
    {
        return $this->hasMany(WeeklySchedule::class);
    }

    public function classSessions(): HasMany
    {
        return $this->hasMany(ClassSession::class);
    }

    /**
     * @param  \Illuminate\Support\Collection<int, self>  $classes
     * @return \Illuminate\Support\Collection<int, self>
     */
    public static function naturalSort(Collection $classes): Collection
    {
        return $classes
            ->sortBy(fn (self $class): array => $class->naturalSortKey())
            ->values();
    }

    /**
     * @return array<int, int|string>
     */
    public function naturalSortKey(): array
    {
        preg_match('/^(.*?)(\d+)?$/', trim($this->name), $matches);

        return [
            $this->classLevel?->sequence ?? 999,
            str($this->classLevel?->name ?? $this->level ?? '')->lower()->toString(),
            str(trim($matches[1] ?? $this->name))->lower()->toString(),
            isset($matches[2]) && $matches[2] !== '' ? (int) $matches[2] : 0,
            str($this->name)->lower()->toString(),
        ];
    }
}
