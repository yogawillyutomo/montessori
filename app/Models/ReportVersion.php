<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

#[Fillable([
    'report_id',
    'version_number',
    'snapshot',
    'published_by',
    'published_at',
])]
class ReportVersion extends Model
{
    use HasFactory;

    protected static function booted(): void
    {
        static::updating(function (): never {
            throw new LogicException('Published report version adalah snapshot immutable dan tidak boleh diubah.');
        });

        static::deleting(function (): never {
            throw new LogicException('Published report version adalah histori resmi dan tidak boleh dihapus.');
        });
    }

    protected function casts(): array
    {
        return [
            'version_number' => 'integer',
            'snapshot' => 'array',
            'published_at' => 'datetime',
        ];
    }

    public function report(): BelongsTo
    {
        return $this->belongsTo(Report::class);
    }

    public function publishedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'published_by');
    }

    public function displayReport(): Report
    {
        $attributes = (array) ($this->snapshot['report'] ?? []);
        $display = new Report();
        $display->forceFill($attributes);
        $display->exists = true;

        if ($this->relationLoaded('report')) {
            $source = $this->report;
            foreach (['student', 'term', 'reportCycle', 'homeroomTeacher'] as $relation) {
                if ($source->relationLoaded($relation)) {
                    $display->setRelation($relation, $source->getRelation($relation));
                }
            }
        }

        return $display;
    }
}
