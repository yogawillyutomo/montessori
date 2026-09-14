<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

#[Fillable([
    'student_id',
    'source_observation_id',
    'indicator_id',
    'status',
    'reason_summary',
    'created_by',
    'reviewed_by',
    'reviewed_at',
    'review_note',
])]
class FollowUpCandidate extends Model
{
    use HasFactory;

    public const STATUS_OPEN = 'open';

    public const STATUS_CONFIRMED = 'confirmed';

    public const STATUS_DISMISSED = 'dismissed';

    public const STATUS_CLOSED = 'closed';

    public const STATUSES = [
        self::STATUS_OPEN,
        self::STATUS_CONFIRMED,
        self::STATUS_DISMISSED,
        self::STATUS_CLOSED,
    ];

    protected function casts(): array
    {
        return [
            'reviewed_at' => 'datetime',
        ];
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    public function sourceObservation(): BelongsTo
    {
        return $this->belongsTo(Observation::class, 'source_observation_id');
    }

    public function indicator(): BelongsTo
    {
        return $this->belongsTo(Indicator::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function reviewedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function supportPlan(): HasOne
    {
        return $this->hasOne(IlpPlan::class, 'follow_up_candidate_id');
    }
}
