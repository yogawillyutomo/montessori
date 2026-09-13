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
    'destination_booking_id',
    'movement_type',
    'reason',
    'moved_by',
    'capacity_override',
    'capacity_override_reason',
])]
class BookingMovement extends Model
{
    use HasFactory;

    public const UPDATED_AT = null;

    protected static function booted(): void
    {
        static::creating(function (BookingMovement $movement): void {
            if ((int) $movement->source_booking_id === (int) $movement->destination_booking_id) {
                throw ValidationException::withMessages([
                    'destination_booking_id' => 'Booking tujuan harus berbeda dari booking sumber.',
                ]);
            }

            $source = ChildSessionBooking::query()->find($movement->source_booking_id);
            $destination = ChildSessionBooking::query()->find($movement->destination_booking_id);

            if (! $source || ! $destination) {
                throw ValidationException::withMessages([
                    'destination_booking_id' => 'Booking sumber atau tujuan movement tidak ditemukan.',
                ]);
            }

            if ((int) $source->student_id !== (int) $destination->student_id) {
                throw ValidationException::withMessages([
                    'destination_booking_id' => 'Movement tidak boleh menghubungkan booking milik anak yang berbeda.',
                ]);
            }

            $cursor = $destination;
            $visited = [];

            while ($cursor) {
                if (isset($visited[$cursor->id])) {
                    throw ValidationException::withMessages([
                        'destination_booking_id' => 'Movement chain sudah mengandung cycle.',
                    ]);
                }

                $visited[$cursor->id] = true;

                if ((int) $cursor->id === (int) $source->id) {
                    throw ValidationException::withMessages([
                        'destination_booking_id' => 'Movement baru akan membentuk cycle.',
                    ]);
                }

                $nextMovement = self::query()
                    ->where('source_booking_id', $cursor->id)
                    ->first();
                $cursor = $nextMovement
                    ? ChildSessionBooking::query()->find($nextMovement->destination_booking_id)
                    : null;
            }
        });

        static::updating(function (): never {
            throw new LogicException('Booking movements are immutable and cannot be updated.');
        });

        static::deleting(function (): never {
            throw new LogicException('Booking movements are immutable and cannot be deleted.');
        });
    }

    protected function casts(): array
    {
        return [
            'capacity_override' => 'boolean',
            'created_at' => 'datetime',
        ];
    }

    public function sourceBooking(): BelongsTo
    {
        return $this->belongsTo(ChildSessionBooking::class, 'source_booking_id');
    }

    public function destinationBooking(): BelongsTo
    {
        return $this->belongsTo(ChildSessionBooking::class, 'destination_booking_id');
    }

    public function movedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'moved_by');
    }
}
