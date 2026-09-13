<?php

namespace App\Services\Scheduling;

use App\Models\Attendance;
use App\Models\ChildSessionBooking;
use App\Models\ClassSession;
use App\Models\Observation;
use App\Models\SessionOccurrence;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class SessionOccurrenceCancellationService
{
    public function cancel(
        SessionOccurrence $occurrence,
        User $actor,
        string $reason,
    ): SessionOccurrence {
        $reason = trim($reason);

        if ($reason === '') {
            throw ValidationException::withMessages([
                'reason' => 'Alasan pembatalan session occurrence wajib dicatat.',
            ]);
        }

        return DB::transaction(function () use ($occurrence, $actor, $reason): SessionOccurrence {
            $locked = SessionOccurrence::query()
                ->whereKey($occurrence->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($locked->status === 'cancelled') {
                return $locked;
            }

            if ($locked->status === 'completed') {
                throw ValidationException::withMessages([
                    'occurrence_id' => 'Session occurrence yang sudah completed tidak boleh dibatalkan.',
                ]);
            }

            $this->assertNoRecordedSessionEvidence($locked);

            if ($locked->legacy_class_session_id) {
                $legacySession = ClassSession::query()->find($locked->legacy_class_session_id);

                if (! $legacySession) {
                    throw ValidationException::withMessages([
                        'occurrence_id' => 'Legacy session untuk occurrence tidak ditemukan.',
                    ]);
                }

                $legacySession->update(['status' => 'cancelled']);
                $locked = SessionOccurrence::query()
                    ->whereKey($locked->id)
                    ->lockForUpdate()
                    ->firstOrFail();
            } else {
                ChildSessionBooking::query()
                    ->where('session_occurrence_id', $locked->id)
                    ->whereIn('status', ChildSessionBooking::ACTIVE_STATUSES)
                    ->update([
                        'status' => 'session_cancelled',
                        'active_on' => null,
                    ]);
            }

            $locked->forceFill([
                'status' => 'cancelled',
                'cancellation_reason' => $reason,
                'cancelled_by' => $actor->id,
                'cancelled_at' => now(),
            ])->save();

            return $locked->fresh();
        });
    }

    private function assertNoRecordedSessionEvidence(SessionOccurrence $occurrence): void
    {
        if (! $occurrence->legacy_class_session_id) {
            return;
        }

        $hasMarkedAttendance = Attendance::query()
            ->where('class_session_id', $occurrence->legacy_class_session_id)
            ->whereNotNull('marked_at')
            ->exists();
        $hasObservation = Observation::query()
            ->where('class_session_id', $occurrence->legacy_class_session_id)
            ->exists();

        if ($hasMarkedAttendance || $hasObservation) {
            throw ValidationException::withMessages([
                'occurrence_id' => 'Session yang sudah memiliki presensi atau observasi tidak boleh dibatalkan.',
            ]);
        }
    }
}
