<?php

namespace App\Models;

use App\Support\Alpha\Role;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Validation\ValidationException;
use LogicException;

#[Fillable([
    'student_id',
    'child_enrollment_id',
    'session_credit_id',
    'credit_allocated_by',
    'credit_allocated_at',
    'session_occurrence_id',
    'booking_type',
    'status',
    'active_on',
    'source_type',
    'cancellation_reason',
    'cancelled_by',
    'cancelled_at',
    'capacity_override',
    'capacity_override_by',
    'capacity_override_reason',
    'created_by',
    'legacy_class_session_student_id',
    'legacy_class_session_id',
    'legacy_deleted_at',
])]
class ChildSessionBooking extends Model
{
    use HasFactory;

    public const ACTIVE_STATUSES = [
        'scheduled',
    ];

    public const STATUSES = [
        'scheduled',
        'rescheduled_out',
        'cancelled',
        'session_cancelled',
    ];

    protected static function booted(): void
    {
        static::saving(function (ChildSessionBooking $booking): void {
            if (! in_array($booking->status, self::STATUSES, true)) {
                throw ValidationException::withMessages([
                    'status' => 'Status booking tidak valid.',
                ]);
            }

            $enrollment = null;
            if ($booking->child_enrollment_id !== null) {
                $enrollment = ChildEnrollment::query()->find($booking->child_enrollment_id);

                if (! $enrollment || (int) $enrollment->student_id !== (int) $booking->student_id) {
                    throw ValidationException::withMessages([
                        'child_enrollment_id' => 'Booking harus memakai enrollment milik anak yang sama.',
                    ]);
                }
            }

            if ($booking->session_credit_id !== null) {
                if (! $enrollment) {
                    throw ValidationException::withMessages([
                        'session_credit_id' => 'Booking dengan session credit wajib memiliki child enrollment.',
                    ]);
                }

                if ($booking->credit_allocated_by === null || $booking->credit_allocated_at === null) {
                    throw ValidationException::withMessages([
                        'session_credit_id' => 'Alokasi session credit harus memiliki actor dan timestamp.',
                    ]);
                }

                $credit = SessionCredit::query()->with('entitlementPeriod')->find($booking->session_credit_id);
                $creditAlreadyLinked = $booking->exists
                    && $booking->getOriginal('session_credit_id') !== null
                    && (int) $booking->getOriginal('session_credit_id') === (int) $booking->session_credit_id;
                $creditStatusAllowed = $credit
                    && ($creditAlreadyLinked
                        ? in_array($credit->status, ['booked', 'used', 'forfeited'], true)
                        : $credit->status === 'booked');

                if (! $credit
                    || $credit->voided_at !== null
                    || ! $creditStatusAllowed
                    || ! $credit->entitlementPeriod
                    || (int) $credit->entitlementPeriod->child_enrollment_id !== (int) $booking->child_enrollment_id) {
                    throw ValidationException::withMessages([
                        'session_credit_id' => 'Session credit tidak valid, di-void, berada pada state yang tidak sesuai, atau bukan milik enrollment booking.',
                    ]);
                }

                $duplicateCredit = self::query()
                    ->active()
                    ->where('session_credit_id', $credit->id)
                    ->when($booking->exists, fn (Builder $query) => $query->whereKeyNot($booking->getKey()))
                    ->exists();

                if ($duplicateCredit) {
                    throw ValidationException::withMessages([
                        'session_credit_id' => 'Satu session credit tidak boleh dialokasikan ke dua booking aktif sekaligus.',
                    ]);
                }
            } else {
                $booking->credit_allocated_by = null;
                $booking->credit_allocated_at = null;
            }

            if (! in_array($booking->status, self::ACTIVE_STATUSES, true)) {
                $booking->active_on = null;

                return;
            }

            $booking->cancellation_reason = null;
            $booking->cancelled_by = null;
            $booking->cancelled_at = null;

            $occurrence = SessionOccurrence::query()->find($booking->session_occurrence_id);
            if (! $occurrence) {
                throw ValidationException::withMessages([
                    'session_occurrence_id' => 'Session occurrence untuk booking tidak ditemukan.',
                ]);
            }

            if ($occurrence->status === 'cancelled') {
                throw ValidationException::withMessages([
                    'session_occurrence_id' => 'Anak tidak dapat dibooking ke session occurrence yang dibatalkan.',
                ]);
            }

            $booking->active_on = $occurrence->occurs_on->toDateString();

            if ($enrollment) {
                if (! $enrollment->coversDate($booking->active_on)) {
                    throw ValidationException::withMessages([
                        'child_enrollment_id' => 'Tanggal booking berada di luar periode enrollment anak.',
                    ]);
                }

                if (! $booking->exists && $enrollment->status === 'ended') {
                    throw ValidationException::withMessages([
                        'child_enrollment_id' => 'Booking baru tidak boleh dibuat pada enrollment yang sudah ended.',
                    ]);
                }

                if ($enrollment->isSuspendedOn($booking->active_on)) {
                    throw ValidationException::withMessages([
                        'child_enrollment_id' => 'Booking tidak boleh dibuat pada periode enrollment yang sedang suspended.',
                    ]);
                }
            }

            $duplicate = self::query()
                ->active()
                ->where('student_id', $booking->student_id)
                ->whereDate('active_on', $booking->active_on)
                ->when($booking->exists, fn (Builder $query) => $query->whereKeyNot($booking->getKey()))
                ->exists();

            if ($duplicate) {
                throw ValidationException::withMessages([
                    'student_id' => 'Anak sudah memiliki booking aktif lain pada tanggal yang sama.',
                ]);
            }

            if ($booking->capacity_override) {
                $overrideActor = User::query()->find($booking->capacity_override_by);
                $authorized = $overrideActor
                    && in_array($overrideActor->role, [Role::SUPER_ADMIN, Role::ADMIN], true);

                if (! $authorized) {
                    throw ValidationException::withMessages([
                        'capacity_override' => 'Capacity override hanya boleh dilakukan oleh super admin atau admin.',
                    ]);
                }

                if (trim((string) $booking->capacity_override_reason) === '') {
                    throw ValidationException::withMessages([
                        'capacity_override_reason' => 'Alasan capacity override wajib dicatat.',
                    ]);
                }
            } else {
                $booking->capacity_override_by = null;
                $booking->capacity_override_reason = null;
            }

            if (! $booking->capacity_override && $occurrence->capacity !== null) {
                $activeCount = self::query()
                    ->active()
                    ->where('session_occurrence_id', $occurrence->id)
                    ->when($booking->exists, fn (Builder $query) => $query->whereKeyNot($booking->getKey()))
                    ->count();

                if ($activeCount >= $occurrence->capacity) {
                    throw ValidationException::withMessages([
                        'session_occurrence_id' => "Kapasitas session occurrence sudah penuh ({$occurrence->capacity} anak).",
                    ]);
                }
            }
        });

        static::updating(function (ChildSessionBooking $booking): void {
            if ($booking->getOriginal('session_credit_id') !== null
                && $booking->isDirty('session_credit_id')) {
                throw new LogicException('Session credit yang sudah dialokasikan ke booking tidak boleh diganti atau dilepas secara langsung. Gunakan workflow rekonsiliasi.');
            }
        });

        static::deleting(function (ChildSessionBooking $booking): void {
            if ($booking->incomingMovement()->exists() || $booking->outgoingMovement()->exists()) {
                throw new LogicException('Booking yang menjadi bagian movement chain tidak boleh dihapus.');
            }

            if ($booking->session_credit_id !== null) {
                throw new LogicException('Booking yang sudah terkait session credit tidak boleh dihapus. Pertahankan histori dan gunakan workflow cancellation/reconciliation.');
            }
        });
    }

    protected function casts(): array
    {
        return [
            'active_on' => 'date',
            'credit_allocated_at' => 'datetime',
            'cancelled_at' => 'datetime',
            'capacity_override' => 'boolean',
            'legacy_deleted_at' => 'datetime',
        ];
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query
            ->whereIn('status', self::ACTIVE_STATUSES)
            ->whereNotNull('active_on');
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    public function childEnrollment(): BelongsTo
    {
        return $this->belongsTo(ChildEnrollment::class);
    }

    public function sessionCredit(): BelongsTo
    {
        return $this->belongsTo(SessionCredit::class);
    }

    public function creditAllocatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'credit_allocated_by');
    }

    public function sessionOccurrence(): BelongsTo
    {
        return $this->belongsTo(SessionOccurrence::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function cancelledBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cancelled_by');
    }

    public function capacityOverrideBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'capacity_override_by');
    }

    public function outgoingMovement(): HasOne
    {
        return $this->hasOne(BookingMovement::class, 'source_booking_id');
    }

    public function incomingMovement(): HasOne
    {
        return $this->hasOne(BookingMovement::class, 'destination_booking_id');
    }
}
