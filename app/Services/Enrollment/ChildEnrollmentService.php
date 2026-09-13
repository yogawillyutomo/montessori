<?php

namespace App\Services\Enrollment;

use App\Models\ChildEnrollment;
use App\Models\ChildSessionBooking;
use App\Models\ClassLevel;
use App\Models\Student;
use App\Models\User;
use App\Support\Alpha\Role;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ChildEnrollmentService
{
    public function enroll(
        Student $student,
        ClassLevel $classLevel,
        string $startsOn,
        User $actor,
        ?string $firstSessionOn = null,
    ): ChildEnrollment {
        $this->authorize($actor);

        return ChildEnrollment::query()->create([
            'student_id' => $student->id,
            'class_level_id' => $classLevel->id,
            'starts_on' => CarbonImmutable::parse($startsOn)->toDateString(),
            'status' => 'active',
            'first_session_on' => $firstSessionOn
                ? CarbonImmutable::parse($firstSessionOn)->toDateString()
                : null,
            'created_by' => $actor->id,
        ]);
    }

    public function end(
        ChildEnrollment $enrollment,
        string $endsOn,
        User $actor,
        string $reason,
    ): ChildEnrollment {
        $this->authorize($actor);
        $reason = trim($reason);
        $endDate = CarbonImmutable::parse($endsOn)->startOfDay();

        if ($reason === '') {
            throw ValidationException::withMessages([
                'reason' => 'Alasan mengakhiri enrollment wajib dicatat.',
            ]);
        }

        return DB::transaction(function () use ($enrollment, $actor, $reason, $endDate): ChildEnrollment {
            $locked = ChildEnrollment::query()->whereKey($enrollment->id)->lockForUpdate()->firstOrFail();

            if ($locked->status === 'ended') {
                throw ValidationException::withMessages([
                    'enrollment' => 'Enrollment sudah berstatus ended.',
                ]);
            }

            if ($endDate->lt($locked->starts_on)) {
                throw ValidationException::withMessages([
                    'ends_on' => 'Tanggal akhir tidak boleh sebelum enrollment dimulai.',
                ]);
            }

            $this->assertNoActiveBookingsAfter($locked, $endDate);

            $locked->forceFill([
                'ends_on' => $endDate->toDateString(),
                'status' => 'ended',
                'ended_reason' => $reason,
            ])->save();

            return $locked->fresh();
        });
    }

    public function transfer(
        ChildEnrollment $enrollment,
        ClassLevel $destinationLevel,
        string $transferOn,
        User $actor,
        string $reason,
    ): ChildEnrollment {
        $this->authorize($actor);
        $reason = trim($reason);
        $transferDate = CarbonImmutable::parse($transferOn)->startOfDay();

        if ($reason === '') {
            throw ValidationException::withMessages([
                'reason' => 'Alasan program transfer wajib dicatat.',
            ]);
        }

        return DB::transaction(function () use (
            $enrollment,
            $destinationLevel,
            $transferDate,
            $actor,
            $reason,
        ): ChildEnrollment {
            $source = ChildEnrollment::query()->whereKey($enrollment->id)->lockForUpdate()->firstOrFail();

            if ($source->status === 'ended') {
                throw ValidationException::withMessages([
                    'enrollment' => 'Enrollment yang sudah ended tidak dapat ditransfer.',
                ]);
            }

            if ($transferDate->lte($source->starts_on)) {
                throw ValidationException::withMessages([
                    'transfer_on' => 'Program transfer harus efektif setelah tanggal mulai enrollment sumber.',
                ]);
            }

            if ((int) $source->class_level_id === (int) $destinationLevel->id) {
                throw ValidationException::withMessages([
                    'class_level_id' => 'Program tujuan harus berbeda dari program enrollment sumber.',
                ]);
            }

            $this->assertNoActiveBookingsOnOrAfter($source, $transferDate);

            $source->forceFill([
                'ends_on' => $transferDate->subDay()->toDateString(),
                'status' => 'ended',
                'ended_reason' => 'Program transfer: '.$reason,
            ])->save();

            return ChildEnrollment::query()->create([
                'student_id' => $source->student_id,
                'class_level_id' => $destinationLevel->id,
                'previous_enrollment_id' => $source->id,
                'starts_on' => $transferDate->toDateString(),
                'status' => 'active',
                'created_by' => $actor->id,
            ]);
        });
    }

    public function suspend(
        ChildEnrollment $enrollment,
        string $suspendedFrom,
        ?string $suspendedUntil,
        User $actor,
        string $reason,
    ): ChildEnrollment {
        $this->authorize($actor);
        $reason = trim($reason);
        $from = CarbonImmutable::parse($suspendedFrom)->startOfDay();
        $until = $suspendedUntil ? CarbonImmutable::parse($suspendedUntil)->startOfDay() : null;

        if ($reason === '') {
            throw ValidationException::withMessages([
                'reason' => 'Alasan suspension wajib dicatat.',
            ]);
        }

        return DB::transaction(function () use ($enrollment, $from, $until, $actor, $reason): ChildEnrollment {
            $locked = ChildEnrollment::query()->whereKey($enrollment->id)->lockForUpdate()->firstOrFail();

            if ($locked->status === 'ended') {
                throw ValidationException::withMessages([
                    'enrollment' => 'Enrollment yang sudah ended tidak dapat disuspend.',
                ]);
            }

            if (! $locked->coversDate($from->toDateString())) {
                throw ValidationException::withMessages([
                    'suspended_from' => 'Suspension harus mulai di dalam periode enrollment.',
                ]);
            }

            if ($until && $until->lt($from)) {
                throw ValidationException::withMessages([
                    'suspended_until' => 'Tanggal akhir suspension tidak boleh sebelum tanggal mulai.',
                ]);
            }

            if ($until && ! $locked->coversDate($until->toDateString())) {
                throw ValidationException::withMessages([
                    'suspended_until' => 'Suspension harus berakhir di dalam periode enrollment.',
                ]);
            }

            $bookingConflict = ChildSessionBooking::query()
                ->active()
                ->where('student_id', $locked->student_id)
                ->whereDate('active_on', '>=', $from->toDateString())
                ->when($until, fn ($query) => $query->whereDate('active_on', '<=', $until->toDateString()))
                ->when(! $until && $locked->ends_on, fn ($query) => $query->whereDate('active_on', '<=', $locked->ends_on->toDateString()))
                ->exists();

            if ($bookingConflict) {
                throw ValidationException::withMessages([
                    'suspended_from' => 'Masih ada booking aktif di periode suspension. Rekonsiliasi booking tersebut terlebih dahulu.',
                ]);
            }

            $locked->forceFill([
                'status' => 'suspended',
                'suspended_from' => $from->toDateString(),
                'suspended_until' => $until?->toDateString(),
                'suspension_reason' => $reason,
            ])->save();

            return $locked->fresh();
        });
    }

    public function resume(
        ChildEnrollment $enrollment,
        string $resumeOn,
        User $actor,
    ): ChildEnrollment {
        $this->authorize($actor);
        $resumeDate = CarbonImmutable::parse($resumeOn)->startOfDay();

        return DB::transaction(function () use ($enrollment, $resumeDate): ChildEnrollment {
            $locked = ChildEnrollment::query()->whereKey($enrollment->id)->lockForUpdate()->firstOrFail();

            if ($locked->status !== 'suspended' || $locked->suspended_from === null) {
                throw ValidationException::withMessages([
                    'enrollment' => 'Hanya enrollment suspended yang dapat di-resume.',
                ]);
            }

            if ($resumeDate->lte($locked->suspended_from)) {
                throw ValidationException::withMessages([
                    'resume_on' => 'Tanggal resume harus setelah tanggal mulai suspension.',
                ]);
            }

            if (! $locked->coversDate($resumeDate->toDateString())) {
                throw ValidationException::withMessages([
                    'resume_on' => 'Tanggal resume harus berada di dalam periode enrollment.',
                ]);
            }

            $locked->forceFill([
                'status' => 'active',
                'suspended_until' => $resumeDate->subDay()->toDateString(),
            ])->save();

            return $locked->fresh();
        });
    }

    private function assertNoActiveBookingsAfter(ChildEnrollment $enrollment, CarbonImmutable $endDate): void
    {
        $exists = ChildSessionBooking::query()
            ->active()
            ->where('student_id', $enrollment->student_id)
            ->whereDate('active_on', '>', $endDate->toDateString())
            ->exists();

        if ($exists) {
            throw ValidationException::withMessages([
                'ends_on' => 'Masih ada booking aktif setelah tanggal akhir enrollment. Rekonsiliasi booking terlebih dahulu.',
            ]);
        }
    }

    private function assertNoActiveBookingsOnOrAfter(ChildEnrollment $enrollment, CarbonImmutable $date): void
    {
        $exists = ChildSessionBooking::query()
            ->active()
            ->where('student_id', $enrollment->student_id)
            ->whereDate('active_on', '>=', $date->toDateString())
            ->exists();

        if ($exists) {
            throw ValidationException::withMessages([
                'transfer_on' => 'Masih ada booking aktif pada/ setelah tanggal transfer. Rekonsiliasi booking terlebih dahulu.',
            ]);
        }
    }

    private function authorize(User $actor): void
    {
        if (! in_array($actor->role, [Role::SUPER_ADMIN, Role::ADMIN], true)) {
            throw ValidationException::withMessages([
                'actor' => 'Perubahan enrollment hanya boleh dilakukan oleh super admin atau admin.',
            ]);
        }
    }
}
