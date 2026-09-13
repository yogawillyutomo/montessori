<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Validation\ValidationException;

#[Fillable([
    'student_id',
    'indicator_id',
    'montessori_activity_id',
    'progress_state',
    'judged_by',
    'judged_at',
    'note',
    'recorded_by',
])]
class DevelopmentProgress extends Model
{
    use HasFactory;

    protected $table = 'development_progress';

    /**
     * Initial vocabulary only. Keep the database column open for a future
     * school-configurable state catalog instead of using a database enum.
     *
     * @var array<int, string>
     */
    public const STATES = [
        'not_introduced',
        'presented',
        'practicing',
        'developing',
        'independent',
        'mastered',
    ];

    protected static function booted(): void
    {
        static::updating(function (): void {
            throw ValidationException::withMessages([
                'development_progress' => 'Histori development progress tidak boleh diubah. Catat judgement baru sebagai histori berikutnya.',
            ]);
        });

        static::deleting(function (): void {
            throw ValidationException::withMessages([
                'development_progress' => 'Histori development progress tidak boleh dihapus. Catat judgement koreksi sebagai histori baru.',
            ]);
        });
    }

    protected function casts(): array
    {
        return [
            'judged_at' => 'datetime',
        ];
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    public function indicator(): BelongsTo
    {
        return $this->belongsTo(Indicator::class);
    }

    public function montessoriActivity(): BelongsTo
    {
        return $this->belongsTo(MontessoriActivity::class);
    }

    public function judgedBy(): BelongsTo
    {
        return $this->belongsTo(Teacher::class, 'judged_by');
    }

    public function recordedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }
}
