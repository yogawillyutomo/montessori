<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Validation\ValidationException;
use LogicException;

#[Fillable(['class_session_id', 'student_id', 'child_session_booking_id', 'status', 'note', 'marked_by', 'marked_at'])]
class Attendance extends Model
{
    use HasFactory;

    public const STATUSES = [
        'unmarked' => 'Belum Ditandai',
        'present' => 'Hadir',
        'excused' => 'Izin',
        'sick' => 'Sakit',
        'absent' => 'Alfa / Tidak Hadir',
        'late' => 'Terlambat',
    ];

    /**
     * @var array{status: mixed, note: mixed}|null
     */
    protected ?array $auditBefore = null;

    protected static function booted(): void
    {
        static::saving(function (Attendance $attendance): void {
            if ($attendance->child_session_booking_id !== null) {
                $booking = ChildSessionBooking::query()->find($attendance->child_session_booking_id);

                if (! $booking || (int) $booking->student_id !== (int) $attendance->student_id) {
                    throw ValidationException::withMessages([
                        'child_session_booking_id' => 'Attendance harus menunjuk booking milik anak yang sama.',
                    ]);
                }

                if ($booking->legacy_class_session_id !== null
                    && $attendance->class_session_id !== null
                    && (int) $booking->legacy_class_session_id !== (int) $attendance->class_session_id) {
                    throw ValidationException::withMessages([
                        'child_session_booking_id' => 'Attendance legacy dan booking harus menunjuk class session yang sama.',
                    ]);
                }
            }

            if ($attendance->marked_at === null) {
                $attendance->status = 'unmarked';
                $attendance->marked_by = null;
            }
        });

        static::updating(function (Attendance $attendance): void {
            if ($attendance->getOriginal('child_session_booking_id') !== null
                && $attendance->isDirty('child_session_booking_id')) {
                throw new LogicException('Attendance yang sudah terhubung ke booking tidak boleh dipindahkan ke booking lain.');
            }

            $attendance->auditBefore = [
                'status' => $attendance->getRawOriginal('status'),
                'note' => $attendance->getRawOriginal('note'),
            ];
        });

        static::created(function (Attendance $attendance): void {
            if ($attendance->status === 'unmarked') {
                return;
            }

            $attendance->writeAudit(null, null);
        });

        static::updated(function (Attendance $attendance): void {
            $before = $attendance->auditBefore;
            $attendance->auditBefore = null;

            if (! $before) {
                return;
            }

            $statusChanged = (string) $before['status'] !== (string) $attendance->status;
            $noteChanged = (string) ($before['note'] ?? '') !== (string) ($attendance->note ?? '');

            if (! $statusChanged && ! $noteChanged) {
                return;
            }

            $attendance->writeAudit(
                is_string($before['status']) ? $before['status'] : null,
                is_string($before['note']) ? $before['note'] : null,
            );
        });
    }

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

    public function childSessionBooking(): BelongsTo
    {
        return $this->belongsTo(ChildSessionBooking::class);
    }

    public function markedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'marked_by');
    }

    public function audits(): HasMany
    {
        return $this->hasMany(AttendanceAudit::class)->orderBy('changed_at');
    }

    public function getStatusLabelAttribute(): string
    {
        return self::STATUSES[$this->status] ?? $this->status;
    }

    public function getStatusBadgeClassAttribute(): string
    {
        return 'status-'.$this->status;
    }

    public function getIsMarkedAttribute(): bool
    {
        return $this->status !== 'unmarked';
    }

    private function writeAudit(?string $oldStatus, ?string $oldNote): void
    {
        AttendanceAudit::query()->create([
            'attendance_id' => $this->id,
            'old_status' => $oldStatus,
            'new_status' => $this->status,
            'old_note' => $oldNote,
            'new_note' => $this->note,
            'changed_by' => $this->marked_by ?: auth()->id(),
            'changed_at' => now(),
        ]);
    }
}
