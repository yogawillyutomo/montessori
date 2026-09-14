<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Validation\ValidationException;
use LogicException;

#[Fillable([
    'student_id',
    'conference_on',
    'lead_teacher_id',
    'report_version_id',
    'summary',
    'strengths',
    'areas_to_support',
    'parent_observation',
    'agreed_follow_up',
    'next_review_on',
    'share_with_parent',
    'created_by',
    'updated_by',
])]
class FamilyConference extends Model
{
    use HasFactory;

    protected static function booted(): void
    {
        static::saving(function (FamilyConference $conference): void {
            if ($conference->next_review_on !== null
                && $conference->conference_on !== null
                && $conference->next_review_on->lt($conference->conference_on)) {
                throw ValidationException::withMessages([
                    'next_review_on' => 'Tanggal review berikutnya tidak boleh sebelum tanggal konferensi.',
                ]);
            }

            if ($conference->share_with_parent && trim((string) $conference->summary) === '') {
                throw ValidationException::withMessages([
                    'summary' => 'Ringkasan konferensi wajib diisi sebelum dibagikan kepada orang tua.',
                ]);
            }

            if ($conference->report_version_id !== null) {
                $version = ReportVersion::query()
                    ->with('report')
                    ->find($conference->report_version_id);

                if (! $version || ! $version->report
                    || (int) $version->report->student_id !== (int) $conference->student_id) {
                    throw ValidationException::withMessages([
                        'report_version_id' => 'Versi rapor harus berasal dari anak yang sama dengan family conference.',
                    ]);
                }
            }
        });

        static::updating(function (FamilyConference $conference): void {
            if ($conference->isDirty('student_id')) {
                throw new LogicException('Family conference tidak boleh dipindahkan ke student lain.');
            }

            if ($conference->isDirty('created_by')) {
                throw new LogicException('Pencatat awal family conference tidak boleh diubah.');
            }
        });
    }

    protected function casts(): array
    {
        return [
            'conference_on' => 'date',
            'next_review_on' => 'date',
            'share_with_parent' => 'boolean',
        ];
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    public function leadTeacher(): BelongsTo
    {
        return $this->belongsTo(Teacher::class, 'lead_teacher_id');
    }

    public function reportVersion(): BelongsTo
    {
        return $this->belongsTo(ReportVersion::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }
}
