<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['class_session_id', 'student_id', 'indicator_id', 'teacher_id', 'observed_on', 'status', 'score', 'note'])]
class Observation extends Model
{
    use HasFactory;

    public const STATUS_SCORES = [
        'achieved' => 100,
        'emerging' => 65,
        'needs_support' => 30,
        'not_observed' => 0,
    ];

    protected function casts(): array
    {
        return [
            'observed_on' => 'date',
        ];
    }

    public function classSession(): BelongsTo
    {
        return $this->belongsTo(ClassSession::class);
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    public function indicator(): BelongsTo
    {
        return $this->belongsTo(Indicator::class);
    }

    public function teacher(): BelongsTo
    {
        return $this->belongsTo(Teacher::class);
    }

    public function getStatusLabelAttribute(): string
    {
        return [
            'achieved' => 'SM - Sudah maksimal',
            'emerging' => 'SB - Sudah berkembang',
            'needs_support' => 'SD - Sedang berkembang',
            'not_observed' => 'Belum diamati',
        ][$this->status] ?? $this->status;
    }
}
