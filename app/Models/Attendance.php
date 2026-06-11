<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['class_session_id', 'student_id', 'status', 'note', 'marked_by', 'marked_at'])]
class Attendance extends Model
{
    use HasFactory;

    public const STATUSES = [
        'present' => 'Hadir',
        'excused' => 'Izin',
        'sick' => 'Sakit',
        'absent' => 'Alfa / Tidak Hadir',
        'late' => 'Terlambat',
    ];

    protected function casts(): array
    {
        return [
            'marked_at' => 'datetime',
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

    public function markedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'marked_by');
    }

    public function getStatusLabelAttribute(): string
    {
        if (! $this->marked_at) {
            return 'Belum Ditandai';
        }

        return self::STATUSES[$this->status] ?? $this->status;
    }

    public function getStatusBadgeClassAttribute(): string
    {
        if (! $this->marked_at) {
            return 'status-unmarked';
        }

        return 'status-'.$this->status;
    }

    public function getIsMarkedAttribute(): bool
    {
        return $this->marked_at !== null;
    }
}
