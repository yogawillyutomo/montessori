<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['class_session_id', 'student_id', 'indicator_id', 'development_area_id', 'teacher_id', 'observation_type', 'observed_on', 'level', 'status', 'score', 'note', 'needs_follow_up', 'include_in_report'])]
class Observation extends Model
{
    use HasFactory;

    public const STATUS_SCORES = [
        'exceeding' => 100,
        'independent' => 85,
        'developing' => 65,
        'emerging' => 35,
        'achieved' => 100,
        'needs_support' => 30,
        'not_observed' => 0,
    ];

    public const LEVELS = [
        'emerging' => 'Mulai Berkembang',
        'developing' => 'Berkembang',
        'independent' => 'Mandiri',
        'exceeding' => 'Melebihi Harapan',
    ];

    public const LEVEL_SCORES = [
        'emerging' => 35,
        'developing' => 65,
        'independent' => 85,
        'exceeding' => 100,
    ];

    public const WORKFLOW_STATUSES = [
        'draft' => 'Draft',
        'saved' => 'Tersimpan',
        'included_in_report' => 'Masuk Bahan Rapor',
        'archived' => 'Diarsipkan',
    ];

    protected function casts(): array
    {
        return [
            'observed_on' => 'date',
            'needs_follow_up' => 'boolean',
            'include_in_report' => 'boolean',
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

    public function developmentArea(): BelongsTo
    {
        return $this->belongsTo(DevelopmentArea::class);
    }

    public function teacher(): BelongsTo
    {
        return $this->belongsTo(Teacher::class);
    }

    public function getStatusLabelAttribute(): string
    {
        return self::WORKFLOW_STATUSES[$this->status] ?? $this->status;
    }

    public function getLevelLabelAttribute(): string
    {
        return self::LEVELS[$this->level ?? $this->status] ?? $this->level ?? $this->status ?? '-';
    }

    public function getLevelBadgeClassAttribute(): string
    {
        return 'status-'.str_replace('_', '-', $this->level ?? $this->status ?? 'empty');
    }

    public function getStatusBadgeClassAttribute(): string
    {
        return 'status-'.str_replace('_', '-', $this->status ?? 'saved');
    }

    public static function scoreForLevel(?string $level): int
    {
        return self::LEVEL_SCORES[$level] ?? self::STATUS_SCORES[$level ?? 'not_observed'] ?? 0;
    }
}
