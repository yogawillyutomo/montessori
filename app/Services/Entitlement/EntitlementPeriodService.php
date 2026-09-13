<?php

namespace App\Services\Entitlement;

use App\Models\ChildEnrollment;
use App\Models\EnrollmentPlanAssignment;
use App\Models\EntitlementPeriod;
use App\Models\SessionCredit;
use App\Models\User;
use App\Support\Alpha\Role;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class EntitlementPeriodService
{
    public function generateMonthly(
        ChildEnrollment $enrollment,
        string $periodDate,
        User $actor,
        ?int $confirmedQuantity = null,
        ?string $confirmationReason = null,
    ): EntitlementPeriod {
        $this->authorize($actor);

        if ($confirmedQuantity !== null && $confirmedQuantity < 0) {
            throw ValidationException::withMessages([
                'confirmed_quantity' => 'Confirmed entitlement quantity tidak boleh negatif.',
            ]);
        }

        $reason = trim((string) $confirmationReason);

        return DB::transaction(function () use (
            $enrollment,
            $periodDate,
            $actor,
            $confirmedQuantity,
            $reason,
        ): EntitlementPeriod {
            $lockedEnrollment = ChildEnrollment::query()
                ->whereKey($enrollment->id)
                ->lockForUpdate()
                ->firstOrFail();

            $periodStart = CarbonImmutable::parse($periodDate)->startOfMonth();
            $periodEnd = $periodStart->endOfMonth();

            if ($lockedEnrollment->starts_on->gt($periodEnd)
                || ($lockedEnrollment->ends_on !== null && $lockedEnrollment->ends_on->lt($periodStart))) {
                throw ValidationException::withMessages([
                    'period' => 'Bulan entitlement tidak beririsan dengan enrollment anak.',
                ]);
            }

            $existing = EntitlementPeriod::query()
                ->where('child_enrollment_id', $lockedEnrollment->id)
                ->whereDate('period_start', $periodStart->toDateString())
                ->lockForUpdate()
                ->first();

            if ($existing) {
                throw ValidationException::withMessages([
                    'period' => 'Entitlement period untuk enrollment dan bulan tersebut sudah dibuat.',
                ]);
            }

            $coverageStart = $lockedEnrollment->starts_on->gt($periodStart)
                ? CarbonImmutable::parse($lockedEnrollment->starts_on->toDateString())
                : $periodStart;
            $coverageEnd = $lockedEnrollment->ends_on !== null && $lockedEnrollment->ends_on->lt($periodEnd)
                ? CarbonImmutable::parse($lockedEnrollment->ends_on->toDateString())
                : $periodEnd;

            $assignments = EnrollmentPlanAssignment::query()
                ->where('child_enrollment_id', $lockedEnrollment->id)
                ->whereNull('cancelled_at')
                ->whereDate('valid_from', '<=', $coverageEnd->toDateString())
                ->where(function ($query) use ($coverageStart): void {
                    $query->whereNull('valid_until')
                        ->orWhereDate('valid_until', '>=', $coverageStart->toDateString());
                })
                ->with('sessionPlan')
                ->orderBy('valid_from')
                ->lockForUpdate()
                ->get();

            if ($assignments->count() !== 1) {
                throw ValidationException::withMessages([
                    'plan_assignment' => 'Entitlement period bulanan harus memiliki tepat satu plan assignment yang berlaku pada coverage bulan tersebut.',
                ]);
            }

            /** @var EnrollmentPlanAssignment $assignment */
            $assignment = $assignments->first();
            $plan = $assignment->sessionPlan;

            if (! $plan || $plan->entitlement_period !== 'monthly') {
                throw ValidationException::withMessages([
                    'session_plan_id' => 'M7 monthly generator hanya menerima session plan dengan entitlement period monthly.',
                ]);
            }

            $partial = $coverageStart->gt($periodStart)
                || $coverageEnd->lt($periodEnd)
                || $assignment->valid_from->gt($coverageStart)
                || ($assignment->valid_until !== null && $assignment->valid_until->lt($coverageEnd))
                || $this->suspensionOverlaps($lockedEnrollment, $periodStart, $periodEnd);

            if ($partial && $confirmedQuantity === null) {
                throw ValidationException::withMessages([
                    'confirmed_quantity' => 'Partial entitlement period wajib memiliki quantity yang dikonfirmasi secara eksplisit. Sistem tidak melakukan auto-prorate.',
                ]);
            }

            $usesConfirmation = $partial
                || ($confirmedQuantity !== null && $confirmedQuantity !== (int) $plan->entitlement_quantity);

            if ($usesConfirmation && $reason === '') {
                throw ValidationException::withMessages([
                    'confirmation_reason' => 'Konfirmasi entitlement quantity wajib memiliki alasan.',
                ]);
            }

            $baseQuantity = $usesConfirmation
                ? (int) $confirmedQuantity
                : (int) $plan->entitlement_quantity;
            $quantitySource = $partial
                ? 'confirmed_partial_period'
                : ($usesConfirmation ? 'confirmed_override' : 'plan');

            $period = EntitlementPeriod::query()->create([
                'child_enrollment_id' => $lockedEnrollment->id,
                'session_plan_id' => $plan->id,
                'enrollment_plan_assignment_id' => $assignment->id,
                'period_start' => $periodStart->toDateString(),
                'period_end' => $periodEnd->toDateString(),
                'base_quantity' => $baseQuantity,
                'quantity_source' => $quantitySource,
                'confirmation_reason' => $usesConfirmation ? $reason : null,
                'confirmed_by' => $usesConfirmation ? $actor->id : null,
                'confirmed_at' => $usesConfirmation ? now() : null,
                'status' => 'open',
                'created_by' => $actor->id,
            ]);

            $this->createBaseCredits($period, $baseQuantity);

            return $period->load(['sessionPlan', 'enrollmentPlanAssignment', 'credits']);
        });
    }

    private function createBaseCredits(EntitlementPeriod $period, int $quantity): void
    {
        for ($sequence = 1; $sequence <= $quantity; $sequence++) {
            SessionCredit::query()->create([
                'entitlement_period_id' => $period->id,
                'sequence_no' => $sequence,
                'origin_period_start' => $period->period_start->toDateString(),
                'status' => 'available',
                'source_type' => 'base',
            ]);
        }
    }

    private function suspensionOverlaps(
        ChildEnrollment $enrollment,
        CarbonImmutable $periodStart,
        CarbonImmutable $periodEnd,
    ): bool {
        if ($enrollment->suspended_from === null) {
            return false;
        }

        $suspensionStart = CarbonImmutable::parse($enrollment->suspended_from->toDateString());
        $suspensionEnd = $enrollment->suspended_until !== null
            ? CarbonImmutable::parse($enrollment->suspended_until->toDateString())
            : null;

        return $suspensionStart->lte($periodEnd)
            && ($suspensionEnd === null || $suspensionEnd->gte($periodStart));
    }

    private function authorize(User $actor): void
    {
        if (! in_array($actor->role, [Role::SUPER_ADMIN, Role::ADMIN], true)) {
            throw ValidationException::withMessages([
                'actor' => 'Entitlement period hanya boleh dibuat oleh super admin atau admin.',
            ]);
        }
    }
}
