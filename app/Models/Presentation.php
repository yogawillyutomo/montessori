<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'student_id',
    'teacher_id',
    'montessori_activity_id',
    'session_occurrence_id',
    'presented_on',
    'presentation_type',
    'note',
    'recorded_by',
])]
class Presentation extends Model
{
    use HasFactory;

    protected function casts(): array
    {
        return [
            'presented_on' => 'date',
        ];
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    public function teacher(): BelongsTo
    {
        return $this->belongsTo(Teacher::class);
    }

    public function montessoriActivity(): BelongsTo
    {
        return $this->belongsTo(MontessoriActivity::class);
    }

    public function sessionOccurrence(): BelongsTo
    {
        return $this->belongsTo(SessionOccurrence::class);
    }

    public function recordedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }
}
