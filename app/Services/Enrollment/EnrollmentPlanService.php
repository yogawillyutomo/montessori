<?php

namespace App\Services\Enrollment;

use App\Models\ChildEnrollment;
use App\Models\EnrollmentPlanAssignment;
use App\Models\SessionPlan;
use App\Models\User;
use App\Support\Alpha\Role;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class EnrollmentPlanService
{
    public function assignInitialPlan(
        ChildEnrollment $enrollment,
        SessionPlan $plan,
        User $actor,
        ?string $validFrom = null,
        ?string $overrideReason = null,
    ): EnrollmentPlanAssignment {
        $this->authorize($actor);

        if ($enrollment->planAssignments()->whereNull('cancelled_at')->exists()) {
            throw ValidationException::withMessages([
                'child_enrollment_id' => 'Enrollment sudah memiliki plan assignment. Gunakan plan change untuk perubahan berikutnya.',
            ]);
        }

        $effectiveDate = CarbonImmutable::parse($validFrom ?: $enrollment->starts_on->toDateString());
        $isDefault = $plan->is_default
            && (int) $plan->class_level_id === (int) $enrollment->class_level_id;
        $assignmentType = $isDefault ? 'default' : 'override';
        $reason = trim((string) $overrideReason);

        if ($assignmentType === 'override' && $reason === '') {
            throw ValidationException::withMessages([
                'reason' => 'Plan non-default untuk anak harus memiliki alasan override.',
            ]);
        }

        return EnrollmentPlanAssignment::query()->create([
            'child_enrollment_id' => $enrollment->id,
            'session_plan_id' => $plan->id,
            'valid_from' => $effectiveDate->toDateString(),
            'assignment_type' => $assignmentType,
            'reason' => $assignmentType === 'override' ? $reason : null,
            'created_by' => $actor->id,
        ]);
    }

    public function schedulePlanChange(
        ChildEnrollment $enrollment,
        SessionPlan $newPlan,
        User $actor,
        string $reason,
        ?string $effectiveFrom = null,
    ): EnrollmentPlanAssignment {
        $this->authorize($actor);
        $reason = trim($reason);

        if ($reason === '') {
            throw ValidationException::withMessages([
                'reason' => 'Alasan plan change wajib dicatat.',
            ]);
        }

        return DB::transaction(function () use (
            $enrollment,
            $newPlan,
            $actor,
            $reason,
            $effectiveFrom,
        ): EnrollmentPlanAssignment {
            $lockedEnrollment = ChildEnrollment::query()
                ->whereKey($enrollment->id)
                ->lockForUpdate()
                ->firstOrFail();
            $tail = EnrollmentPlanAssignment::query()
                ->where('child_enrollment_id', $lockedEnrollment->id)
                ->whereNull('cancelled_at')
                ->orderByDesc('valid_from')
                ->lockForUpdate()
                ->first();

            if (! $tail) {
                throw ValidationException::withMessages([
                    'child_enrollment_id' => 'Enrollment belum memiliki plan awal.',
                ]);
            }

            if ((int) $tail->session_plan_id === (int) $newPlan->id) {
                throw ValidationException::withMessages([
                    'session_plan_id' => 'Plan baru harus berbeda dari plan assignment terakhir.',
                ]);
            }

            $currentPlan = SessionPlan::query()->findOrFail($tail->session_plan_id);

            if ($effectiveFrom === null
                && ($currentPlan->entitlement_period !== 'monthly' || $newPlan->entitlement_period !== 'monthly')) {
                throw ValidationException::withMessages([
                    'valid_from' => 'Plan non-monthly memerlukan tanggal efektif eksplisit.',
                ]);
            }

            if ($effectiveFrom) {
                $effectiveDate = CarbonImmutable::parse($effectiveFrom)->startOfDay();
            } else {
                $now = CarbonImmutable::now()->startOfDay();
                $tailStart = CarbonImmutable::parse($tail->valid_from)->startOfDay();
                $base = $now->greaterThan($tailStart) ? $now : $tailStart;
                $effectiveDate = $base->startOfMonth()->addMonth();
            }

            if ($effectiveDate->lte($tail->valid_from)) {
                throw ValidationException::withMessages([
                    'valid_from' => 'Plan change harus efektif setelah assignment terakhir dimulai.',
                ]);
            }

            if (! $lockedEnrollment->coversDate($effectiveDate->toDateString())) {
                throw ValidationException::withMessages([
                    'valid_from' => 'Tanggal efektif plan change harus berada di dalam periode enrollment.',
                ]);
            }

            if ($newPlan->entitlement_period === 'monthly' && $effectiveDate->day !== 1) {
                throw ValidationException::withMessages([
                    'valid_from' => 'Plan bulanan permanen berubah pada awal periode. Perubahan kuota tengah bulan harus memakai entitlement adjustment di M7.',
                ]);
            }

            $tail->update([
                'valid_until' => $effectiveDate->subDay()->toDateString(),
            ]);

            return EnrollmentPlanAssignment::query()->create([
                'child_enrollment_id' => $lockedEnrollment->id,
                'session_plan_id' => $newPlan->id,
                'valid_from' => $effectiveDate->toDateString(),
                'assignment_type' => 'change',
                'reason' => $reason,
                'created_by' => $actor->id,
            ]);
        });
    }

    public function cancelFutureAssignment(
        EnrollmentPlanAssignment $assignment,
        User $actor,
        string $reason,
        ?string $asOf = null,
    ): EnrollmentPlanAssignment {
        $this->authorize($actor);
        $reason = trim($reason);
        $asOfDate = CarbonImmutable::parse($asOf ?: CarbonImmutable::now()->toDateString())->startOfDay();

        if ($reason === '') {
            throw ValidationException::withMessages([
                'reason' => 'Alasan pembatalan future plan assignment wajib dicatat.',
            ]);
        }

        return DB::transaction(function () use ($assignment, $actor, $reason, $asOfDate): EnrollmentPlanAssignment {
            $locked = EnrollmentPlanAssignment::query()
                ->whereKey($assignment->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($locked->cancelled_at !== null) {
                return $locked;
            }

            if (! $locked->valid_from->gt($asOfDate)) {
                throw ValidationException::withMessages([
                    'assignment' => 'Hanya plan assignment yang belum mulai yang dapat dibatalkan.',
                ]);
            }

            $locked->forceFill([
                'cancelled_at' => now(),
                'cancelled_by' => $actor->id,
                'cancellation_reason' => $reason,
            ])->save();

            return $locked->fresh();
        });
    }

    public function assignmentOn(ChildEnrollment $enrollment, string $date): ?EnrollmentPlanAssignment
    {
        $on = CarbonImmutable::parse($date)->toDateString();

        return EnrollmentPlanAssignment::query()
            ->where('child_enrollment_id', $enrollment->id)
            ->whereNull('cancelled_at')
            ->whereDate('valid_from', '<=', $on)
            ->where(function ($query) use ($on): void {
                $query->whereNull('valid_until')
                    ->orWhereDate('valid_until', '>=', $on);
            })
            ->orderByDesc('valid_from')
            ->first();
    }

    private function authorize(User $actor): void
    {
        if (! in_array($actor->role, [Role::SUPER_ADMIN, Role::ADMIN], true)) {
            throw ValidationException::withMessages([
                'actor' => 'Plan assignment hanya boleh dilakukan oleh super admin atau admin.',
            ]);
        }
    }
}
