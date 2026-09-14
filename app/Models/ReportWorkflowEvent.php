<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

#[Fillable([
    'report_id',
    'from_status',
    'to_status',
    'actor_id',
    'note',
    'occurred_at',
])]
class ReportWorkflowEvent extends Model
{
    use HasFactory;

    protected static function booted(): void
    {
        static::updating(function (): never {
            throw new LogicException('Report workflow event adalah audit append-only dan tidak boleh diubah.');
        });

        static::deleting(function (): never {
            throw new LogicException('Report workflow event adalah audit append-only dan tidak boleh dihapus.');
        });
    }

    protected function casts(): array
    {
        return [
            'occurred_at' => 'datetime',
        ];
    }

    public function report(): BelongsTo
    {
        return $this->belongsTo(Report::class);
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }
}
