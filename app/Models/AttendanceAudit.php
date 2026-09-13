<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['attendance_id', 'old_status', 'new_status', 'old_note', 'new_note', 'changed_by', 'changed_at'])]
class AttendanceAudit extends Model
{
    use HasFactory;

    public $timestamps = false;

    protected function casts(): array
    {
        return [
            'changed_at' => 'datetime',
        ];
    }

    public function attendance(): BelongsTo
    {
        return $this->belongsTo(Attendance::class);
    }

    public function changedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'changed_by');
    }
}
