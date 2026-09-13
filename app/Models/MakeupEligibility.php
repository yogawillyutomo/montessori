<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Validation\ValidationException;
use LogicException;

#[Fillable([
    'source_booking_id',
    'session_credit_id',
    'source_attendance_id',
    'reason_category',
    'status',
    'granted_by',
    'granted_at',
    'fulfilled_by_booking_id',
    'fulfilled_at',
    'revoked_by',
    'revoked_at',
    'revocation_reason',
])]
class MakeupEligibility extends Model
{
    use HasFactory;

    public const STATUSES = [
        'pending',
        'fulfilled',
        'revoked',
    ];

    public const REASONS = [
        'sick',
        'excused',
        'no_show',
        'school_cancel',
    ];

    private const IDENTITY_FIELDS = [
        'source_booking_id',
        'session_credit_id',
        'source_attendance_id',
    ];

    protected static function booted(): void
    {
        static::saving(function (MakeupEligibility $eligibility): void {
            if (! in_array($eligibility->status, self::STATUSES, true)) {
                throw ValidationException::withMessages([
                    'status' => 'Status makeup eligibility tidak valid.',
                ]);
            }

            if (! in_array($eligibility->reason_category, self::REASONS, true)) {
                throw ValidationException::withMessages([
                    'reason_category' => 'Reason category makeup eligibility tidak valid.',
                ]);
            }

            $sourceBooking = ChildSessionBooking::query()->find($eligibility->source_booking_id);
            $credit = SessionCredit::query()->find($eligibility->session_credit_id);

            if (! $sourceBooking || ! $credit
                || (int) $sourceBooking->session_credit_id !== (int) $credit->id) {
                throw ValidationException::withMessages([
                    'session_credit_id' => 'Makeup eligibility harus menunjuk source booking dan session credit yang sama.',
                ]);
            }

            if ($eligibility->source_attendance_id !== null) {
                $attendance = Attendance::query()->find($eligibility->source_attendance_id);

                if (! $attendance
                    || (int) $attendance->child_session_booking_id !== (int) $sourceBooking->id) {
                    throw ValidationException::withMessages([
                        'source_attendance_id' => 'Source attendance makeup eligibility harus berasal dari source booking yang sama.',
                    ]);
                }
            }

            if ($eligibility->granted_at === null) {
                throw ValidationException::withMessages([
                    'granted_at' => 'Makeup eligibility harus memiliki waktu grant.',
                ]);
            }

            if ($eligibility->status === 'pending') {
                $eligibility->fulfilled_by_booking_id = null;
                $eligibility->fulfilled_at = null;
                $eligibility->revoked_by = null;
                $eligibility->revoked_at = null;
                $eligibility->revocation_reason = null;
            }

            if ($eligibility->status === 'fulfilled') {
                if ($eligibility->fulfilled_by_booking_id === null || $eligibility->fulfilled_at === null) {
                    throw ValidationException::withMessages([
                        'fulfilled_by_booking_id' => 'Makeup eligibility FULFILLED harus menyimpan booking pemenuh dan waktu pemenuhan.',
                    ]);
                }

                $fulfilledBooking = ChildSessionBooking::query()->find($eligibility->fulfilled_by_booking_id);
                if (! $fulfilledBooking
                    || (int) $fulfilledBooking->session_credit_id !== (int) $credit->id) {
                    throw ValidationException::withMessages([
                        'fulfilled_by_booking_id' => 'Booking pemenuh makeup harus memakai session credit yang sama.',
                    ]);
                }

                $eligibility->revoked_by = null;
                $eligibility->revoked_at = null;
                $eligibility->revocation_reason = null;
            }

            if ($eligibility->status === 'revoked') {
                if ($eligibility->revoked_at === null || trim((string) $eligibility->revocation_reason) === '') {
                    throw ValidationException::withMessages([
                        'revocation_reason' => 'Makeup eligibility REVOKED harus menyimpan waktu dan alasan revocation.',
                    ]);
                }

                $eligibility->fulfilled_by_booking_id = null;
                $eligibility->fulfilled_at = null;
            }
        });

        static::updating(function (MakeupEligibility $eligibility): void {
            foreach (self::IDENTITY_FIELDS as $field) {
                if ($eligibility->isDirty($field)) {
                    throw new LogicException('Identitas source booking, credit, dan source attendance makeup eligibility tidak boleh diubah.');
                }
            }
        });

        static::deleting(function (): never {
            throw new LogicException('Makeup eligibility adalah historical service-right record dan tidak boleh dihapus.');
        });
    }

    protected function casts(): array
    {
        return [
            'granted_at' => 'datetime',
            'fulfilled_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    public function sourceBooking(): BelongsTo
    {
        return $this->belongsTo(ChildSessionBooking::class, 'source_booking_id');
    }

    public function sessionCredit(): BelongsTo
    {
        return $this->belongsTo(SessionCredit::class);
    }

    public function sourceAttendance(): BelongsTo
    {
        return $this->belongsTo(Attendance::class, 'source_attendance_id');
    }

    public function grantedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'granted_by');
    }

    public function fulfilledByBooking(): BelongsTo
    {
        return $this->belongsTo(ChildSessionBooking::class, 'fulfilled_by_booking_id');
    }

    public function revokedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'revoked_by');
    }
}
