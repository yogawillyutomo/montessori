<?php

namespace App\Services\Scheduling;

use App\Models\BookingMovement;
use App\Models\ChildSessionBooking;
use LogicException;

class BookingLineageService
{
    public function __construct(
        private readonly BookingEvidenceService $evidence,
    ) {}

    public function originalBooking(ChildSessionBooking $booking): ChildSessionBooking
    {
        $cursor = ChildSessionBooking::query()->findOrFail($booking->id);
        $visited = [];

        while (true) {
            if (isset($visited[$cursor->id])) {
                throw new LogicException('Booking movement chain contains a cycle.');
            }

            $visited[$cursor->id] = true;
            $incoming = BookingMovement::query()
                ->where('destination_booking_id', $cursor->id)
                ->first();

            if (! $incoming) {
                return $cursor;
            }

            $cursor = ChildSessionBooking::query()->findOrFail($incoming->source_booking_id);
        }
    }

    /**
     * @return array<int, ChildSessionBooking>
     */
    public function chain(ChildSessionBooking $booking): array
    {
        $cursor = $this->originalBooking($booking);
        $chain = [];
        $visited = [];

        while ($cursor) {
            if (isset($visited[$cursor->id])) {
                throw new LogicException('Booking movement chain contains a cycle.');
            }

            $visited[$cursor->id] = true;
            $chain[] = $cursor;

            $outgoing = BookingMovement::query()
                ->where('source_booking_id', $cursor->id)
                ->first();
            $cursor = $outgoing
                ? ChildSessionBooking::query()->findOrFail($outgoing->destination_booking_id)
                : null;
        }

        return $chain;
    }

    public function currentBooking(ChildSessionBooking $booking): ChildSessionBooking
    {
        $chain = $this->chain($booking);

        return $chain[array_key_last($chain)];
    }

    public function finalAttendanceBooking(ChildSessionBooking $booking): ?ChildSessionBooking
    {
        foreach (array_reverse($this->chain($booking)) as $candidate) {
            if ($this->evidence->hasFulfilledAttendance($candidate)) {
                return $candidate;
            }
        }

        return null;
    }
}
