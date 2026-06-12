<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Report extends Model
{
    use HasFactory;

    public const STATUSES = [
        'draft' => 'Draft',
        'ready' => 'Siap Direview',
        'published' => 'Dipublish',
        'archived' => 'Diarsipkan',
    ];

    protected $fillable = [
        'student_id',
        'term_id',
        'homeroom_teacher_id',
        'reviewed_by',
        'status',
        'summary',
        'manual_present_total',
        'manual_sick_total',
        'manual_excused_total',
        'manual_absent_total',
        'manual_late_total',
        'manual_attendance_note',
        'teacher_narrative',
        'general_narrative',
        'social_emotional_narrative',
        'independence_narrative',
        'academic_narrative',
        'parent_meeting_note',
        'principal_note',
        'generated_at',
        'reviewed_at',
        'published_at',
    ];

    protected function casts(): array
    {
        return [
            'summary' => 'array',
            'manual_present_total' => 'integer',
            'manual_sick_total' => 'integer',
            'manual_excused_total' => 'integer',
            'manual_absent_total' => 'integer',
            'manual_late_total' => 'integer',
            'generated_at' => 'datetime',
            'reviewed_at' => 'datetime',
            'published_at' => 'datetime',
        ];
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    public function term(): BelongsTo
    {
        return $this->belongsTo(Term::class);
    }

    public function homeroomTeacher(): BelongsTo
    {
        return $this->belongsTo(Teacher::class, 'homeroom_teacher_id');
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function getStatusLabelAttribute(): string
    {
        return self::STATUSES[$this->status] ?? [
            'reviewed' => 'Sudah Direview',
            'approved' => 'Disetujui',
            'empty' => 'Belum Ada Data',
        ][$this->status] ?? $this->status;
    }

    public function getStatusBadgeClassAttribute(): string
    {
        return 'status-'.str_replace('_', '-', $this->status ?? 'draft');
    }

    /**
     * @return array<string, int|float>
     */
    public function manualAttendanceSummary(): array
    {
        $present = (int) $this->manual_present_total;
        $late = (int) $this->manual_late_total;
        $sick = (int) $this->manual_sick_total;
        $excused = (int) $this->manual_excused_total;
        $absent = (int) $this->manual_absent_total;
        $recorded = $present + $late + $sick + $excused + $absent;

        return [
            'recorded' => $recorded,
            'present' => $present,
            'late' => $late,
            'sick' => $sick,
            'excused' => $excused,
            'absent' => $absent,
            'attendance_rate' => $recorded > 0 ? round((($present + $late) / $recorded) * 100, 1) : 0,
        ];
    }
}
