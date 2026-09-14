<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Validation\ValidationException;
use LogicException;

class Report extends Model
{
    use HasFactory;

    public const STATUSES = [
        'draft' => 'Draft',
        'ready' => 'Siap Direview (Legacy)',
        'submitted_for_review' => 'Diajukan untuk Review',
        'under_review' => 'Sedang Direview',
        'revision_requested' => 'Perlu Revisi',
        'approved' => 'Disetujui',
        'published' => 'Dipublish',
        'archived' => 'Diarsipkan',
    ];

    private const CYCLE_TRANSITIONS = [
        'draft' => ['submitted_for_review'],
        'submitted_for_review' => ['under_review'],
        'under_review' => ['revision_requested', 'approved'],
        'revision_requested' => ['submitted_for_review'],
        'approved' => ['published'],
        'published' => ['draft', 'archived'],
        'archived' => [],
    ];

    private const IDENTITY_FIELDS = [
        'student_id',
        'term_id',
        'report_cycle_id',
        'child_enrollment_id',
    ];

    private const CONTENT_FIELDS = [
        'homeroom_teacher_id',
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
    ];

    protected $fillable = [
        'student_id',
        'term_id',
        'report_cycle_id',
        'child_enrollment_id',
        'homeroom_teacher_id',
        'reviewed_by',
        'status',
        'working_revision',
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
        'submitted_by',
        'submitted_at',
        'review_started_by',
        'review_started_at',
        'revision_requested_by',
        'revision_requested_at',
        'revision_request_note',
        'approved_by',
        'approved_at',
        'revision_opened_by',
        'revision_opened_at',
        'revision_open_reason',
        'archived_by',
        'archived_at',
        'published_at',
        'published_version_id',
    ];

    protected static function booted(): void
    {
        static::saving(function (Report $report): void {
            if (! array_key_exists((string) $report->status, self::STATUSES)) {
                throw ValidationException::withMessages([
                    'status' => 'Status rapor tidak valid.',
                ]);
            }

            if (($report->report_cycle_id === null) !== ($report->child_enrollment_id === null)) {
                throw ValidationException::withMessages([
                    'report_cycle_id' => 'Cycle report harus menyimpan report cycle dan child enrollment secara berpasangan.',
                ]);
            }

            if ($report->report_cycle_id === null) {
                $duplicateLegacy = self::query()
                    ->where('student_id', $report->student_id)
                    ->where('term_id', $report->term_id)
                    ->whereNull('report_cycle_id')
                    ->when($report->exists, fn ($query) => $query->whereKeyNot($report->getKey()))
                    ->exists();

                if ($duplicateLegacy) {
                    throw ValidationException::withMessages([
                        'term_id' => 'Legacy report untuk student dan term yang sama sudah ada.',
                    ]);
                }

                return;
            }

            $enrollment = ChildEnrollment::query()->find($report->child_enrollment_id);
            $cycle = ReportCycle::query()->with('reportPolicy')->find($report->report_cycle_id);

            if (! $enrollment || (int) $enrollment->student_id !== (int) $report->student_id) {
                throw ValidationException::withMessages([
                    'child_enrollment_id' => 'Cycle report harus menggunakan enrollment milik student yang sama.',
                ]);
            }

            if (! $cycle || ! $cycle->reportPolicy
                || (int) $cycle->reportPolicy->class_level_id !== (int) $enrollment->class_level_id) {
                throw ValidationException::withMessages([
                    'report_cycle_id' => 'Report cycle harus berasal dari policy level enrollment yang sama.',
                ]);
            }
        });

        static::updating(function (Report $report): void {
            foreach (self::IDENTITY_FIELDS as $field) {
                if ($report->isDirty($field)) {
                    throw new LogicException('Identitas student, term, cycle, dan enrollment pada report tidak boleh diubah.');
                }
            }

            $originalStatus = (string) $report->getOriginal('status');
            $contentDirty = collect(self::CONTENT_FIELDS)->contains(fn (string $field): bool => $report->isDirty($field));

            if ($report->report_cycle_id !== null) {
                if ($contentDirty && ! in_array($originalStatus, ['draft', 'revision_requested'], true)) {
                    throw ValidationException::withMessages([
                        'status' => 'Isi cycle report hanya boleh diedit saat DRAFT atau REVISION_REQUESTED.',
                    ]);
                }

                if ($report->isDirty('status')) {
                    $targetStatus = (string) $report->status;
                    $allowed = self::CYCLE_TRANSITIONS[$originalStatus] ?? [];

                    if (! in_array($targetStatus, $allowed, true)) {
                        throw ValidationException::withMessages([
                            'status' => "Transisi report {$originalStatus} -> {$targetStatus} tidak diizinkan.",
                        ]);
                    }

                    self::validateTransitionMetadata($report, $originalStatus, $targetStatus);
                }

                return;
            }

            if ($originalStatus === 'published' && $contentDirty) {
                throw ValidationException::withMessages([
                    'status' => 'Rapor legacy yang sudah dipublish tidak dapat diedit tanpa workflow revisi.',
                ]);
            }
        });

        static::deleting(function (Report $report): void {
            if ($report->report_cycle_id !== null || $report->versions()->exists() || $report->workflowEvents()->exists()) {
                throw new LogicException('Cycle report dan histori publication/workflow tidak boleh dihapus. Gunakan archive.');
            }
        });
    }

    protected function casts(): array
    {
        return [
            'summary' => 'array',
            'working_revision' => 'integer',
            'manual_present_total' => 'integer',
            'manual_sick_total' => 'integer',
            'manual_excused_total' => 'integer',
            'manual_absent_total' => 'integer',
            'manual_late_total' => 'integer',
            'generated_at' => 'datetime',
            'reviewed_at' => 'datetime',
            'submitted_at' => 'datetime',
            'review_started_at' => 'datetime',
            'revision_requested_at' => 'datetime',
            'approved_at' => 'datetime',
            'revision_opened_at' => 'datetime',
            'archived_at' => 'datetime',
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

    public function reportCycle(): BelongsTo
    {
        return $this->belongsTo(ReportCycle::class);
    }

    public function childEnrollment(): BelongsTo
    {
        return $this->belongsTo(ChildEnrollment::class);
    }

    public function homeroomTeacher(): BelongsTo
    {
        return $this->belongsTo(Teacher::class, 'homeroom_teacher_id');
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function versions(): HasMany
    {
        return $this->hasMany(ReportVersion::class)->orderBy('version_number');
    }

    public function publishedVersion(): BelongsTo
    {
        return $this->belongsTo(ReportVersion::class, 'published_version_id');
    }

    public function workflowEvents(): HasMany
    {
        return $this->hasMany(ReportWorkflowEvent::class)->orderBy('occurred_at');
    }

    public function getStatusLabelAttribute(): string
    {
        return self::STATUSES[$this->status] ?? [
            'reviewed' => 'Sudah Direview',
            'empty' => 'Belum Ada Data',
        ][$this->status] ?? $this->status;
    }

    public function getStatusBadgeClassAttribute(): string
    {
        return 'status-'.str_replace('_', '-', $this->status ?? 'draft');
    }

    public function isCycleReport(): bool
    {
        return $this->report_cycle_id !== null;
    }

    public function publishedDisplayReport(): ?Report
    {
        if ($this->published_version_id !== null) {
            $this->loadMissing(['student', 'term', 'reportCycle', 'homeroomTeacher', 'publishedVersion']);
            $this->publishedVersion?->setRelation('report', $this);

            return $this->publishedVersion?->displayReport();
        }

        return $this->status === 'published' ? $this : null;
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

    private static function validateTransitionMetadata(Report $report, string $from, string $to): void
    {
        $requirements = match ([$from, $to]) {
            ['draft', 'submitted_for_review'], ['revision_requested', 'submitted_for_review'] => ['submitted_by', 'submitted_at'],
            ['submitted_for_review', 'under_review'] => ['review_started_by', 'review_started_at'],
            ['under_review', 'revision_requested'] => ['revision_requested_by', 'revision_requested_at', 'revision_request_note'],
            ['under_review', 'approved'] => ['approved_by', 'approved_at'],
            ['approved', 'published'] => ['published_version_id', 'published_at'],
            ['published', 'draft'] => ['revision_opened_by', 'revision_opened_at', 'revision_open_reason'],
            ['published', 'archived'] => ['archived_by', 'archived_at'],
            default => [],
        };

        foreach ($requirements as $field) {
            $value = $report->{$field};
            if ($value === null || (is_string($value) && trim($value) === '')) {
                throw ValidationException::withMessages([
                    $field => "Transisi {$from} -> {$to} harus menyimpan {$field}.",
                ]);
            }
        }
    }
}
