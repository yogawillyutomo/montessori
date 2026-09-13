<?php

namespace App\Services\Entitlement;

use App\Models\EntitlementAdjustment;
use App\Models\EntitlementPeriod;
use App\Models\SessionCredit;
use App\Models\User;
use App\Support\Alpha\Role;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class EntitlementAdjustmentService
{
    public function adjust(
        EntitlementPeriod $period,
        int $quantityDelta,
        string $reasonCode,
        User $actor,
        ?string $note = null,
    ): EntitlementAdjustment {
        $this->authorize($actor);
        $reasonCode = trim($reasonCode);

        if ($quantityDelta === 0) {
            throw ValidationException::withMessages([
                'quantity_delta' => 'Entitlement adjustment tidak boleh bernilai nol.',
            ]);
        }

        if ($reasonCode === '') {
            throw ValidationException::withMessages([
                'reason_code' => 'Reason code entitlement adjustment wajib diisi.',
            ]);
        }

        return DB::transaction(function () use ($period, $quantityDelta, $reasonCode, $actor, $note): EntitlementAdjustment {
            $lockedPeriod = EntitlementPeriod::query()
                ->whereKey($period->id)
                ->lockForUpdate()
                ->firstOrFail();

            $this->assertOpen($lockedPeriod);

            if ($quantityDelta < 0) {
                $required = abs($quantityDelta);
                $available = SessionCredit::query()
                    ->where('entitlement_period_id', $lockedPeriod->id)
                    ->whereNull('voided_at')
                    ->where('status', 'available')
                    ->lockForUpdate()
                    ->get();

                if ($available->count() < $required) {
                    throw ValidationException::withMessages([
                        'quantity_delta' => 'Adjustment negatif tidak boleh mengurangi credit yang sudah BOOKED/USED/FORFEITED. Credit AVAILABLE tidak mencukupi.',
                    ]);
                }
            }

            $adjustment = EntitlementAdjustment::query()->create([
                'entitlement_period_id' => $lockedPeriod->id,
                'quantity_delta' => $quantityDelta,
                'reason_code' => $reasonCode,
                'note' => $note,
                'created_by' => $actor->id,
            ]);

            if ($quantityDelta > 0) {
                $this->createAdjustmentCredits($lockedPeriod, $adjustment, $quantityDelta);
            } else {
                $this->voidAvailableCredits($lockedPeriod, $adjustment, abs($quantityDelta));
            }

            $this->assertLedgerReconciled($lockedPeriod);

            return $adjustment->load(['createdCredits', 'voidedCredits']);
        });
    }

    public function reverse(
        EntitlementAdjustment $adjustment,
        User $actor,
        ?string $note = null,
    ): EntitlementAdjustment {
        $this->authorize($actor);

        return DB::transaction(function () use ($adjustment, $actor, $note): EntitlementAdjustment {
            $lockedAdjustment = EntitlementAdjustment::query()
                ->whereKey($adjustment->id)
                ->lockForUpdate()
                ->firstOrFail();
            $period = EntitlementPeriod::query()
                ->whereKey($lockedAdjustment->entitlement_period_id)
                ->lockForUpdate()
                ->firstOrFail();

            $this->assertOpen($period);

            if ($lockedAdjustment->reversal_of_adjustment_id !== null
                || $lockedAdjustment->reversals()->exists()) {
                throw ValidationException::withMessages([
                    'adjustment' => 'Adjustment hanya boleh direversal satu kali dan reversal tidak dapat direversal kembali.',
                ]);
            }

            $delta = -1 * (int) $lockedAdjustment->quantity_delta;

            if ($lockedAdjustment->quantity_delta > 0) {
                $credits = SessionCredit::query()
                    ->where('source_adjustment_id', $lockedAdjustment->id)
                    ->whereNull('voided_at')
                    ->lockForUpdate()
                    ->get();

                if ($credits->count() !== (int) $lockedAdjustment->quantity_delta
                    || $credits->contains(fn (SessionCredit $credit): bool => $credit->status !== 'available')) {
                    throw ValidationException::withMessages([
                        'adjustment' => 'Adjustment positif tidak dapat direversal karena sebagian credit hasil adjustment sudah dipakai, dibooking, forfeited, atau telah direkonsiliasi lain.',
                    ]);
                }
            } else {
                $credits = SessionCredit::query()
                    ->where('voided_by_adjustment_id', $lockedAdjustment->id)
                    ->lockForUpdate()
                    ->get();

                if ($credits->count() !== abs((int) $lockedAdjustment->quantity_delta)
                    || $credits->contains(fn (SessionCredit $credit): bool => $credit->status !== 'available' || $credit->voided_at === null)) {
                    throw ValidationException::withMessages([
                        'adjustment' => 'Adjustment negatif tidak dapat direversal karena credit yang sebelumnya di-void sudah tidak konsisten.',
                    ]);
                }
            }

            $reversal = EntitlementAdjustment::query()->create([
                'entitlement_period_id' => $period->id,
                'quantity_delta' => $delta,
                'reason_code' => 'reversal',
                'note' => $note ?: 'Reversal adjustment #'.$lockedAdjustment->id,
                'created_by' => $actor->id,
                'reversal_of_adjustment_id' => $lockedAdjustment->id,
            ]);

            if ($lockedAdjustment->quantity_delta > 0) {
                foreach ($credits as $credit) {
                    $credit->forceFill([
                        'voided_at' => now(),
                        'voided_by_adjustment_id' => $reversal->id,
                    ])->save();
                }
            } else {
                foreach ($credits as $credit) {
                    $credit->forceFill([
                        'voided_at' => null,
                        'voided_by_adjustment_id' => null,
                    ])->save();
                }
            }

            $this->assertLedgerReconciled($period);

            return $reversal->load(['reversalOf', 'voidedCredits']);
        });
    }

    private function createAdjustmentCredits(
        EntitlementPeriod $period,
        EntitlementAdjustment $adjustment,
        int $quantity,
    ): void {
        $nextSequence = (int) SessionCredit::query()
            ->where('entitlement_period_id', $period->id)
            ->max('sequence_no') + 1;

        for ($offset = 0; $offset < $quantity; $offset++) {
            SessionCredit::query()->create([
                'entitlement_period_id' => $period->id,
                'sequence_no' => $nextSequence + $offset,
                'origin_period_start' => $period->period_start->toDateString(),
                'status' => 'available',
                'source_type' => 'adjustment',
                'source_adjustment_id' => $adjustment->id,
            ]);
        }
    }

    private function voidAvailableCredits(
        EntitlementPeriod $period,
        EntitlementAdjustment $adjustment,
        int $quantity,
    ): void {
        $credits = SessionCredit::query()
            ->where('entitlement_period_id', $period->id)
            ->whereNull('voided_at')
            ->where('status', 'available')
            ->orderByDesc('sequence_no')
            ->limit($quantity)
            ->lockForUpdate()
            ->get();

        if ($credits->count() !== $quantity) {
            throw ValidationException::withMessages([
                'quantity_delta' => 'Credit AVAILABLE tidak mencukupi untuk adjustment negatif.',
            ]);
        }

        foreach ($credits as $credit) {
            $credit->forceFill([
                'voided_at' => now(),
                'voided_by_adjustment_id' => $adjustment->id,
            ])->save();
        }
    }

    private function assertLedgerReconciled(EntitlementPeriod $period): void
    {
        $period->refresh();

        if ($period->effectiveQuantity() !== $period->activeCreditCount()) {
            throw ValidationException::withMessages([
                'ledger' => 'Entitlement ledger tidak reconcile: effective quantity berbeda dari jumlah active credits.',
            ]);
        }
    }

    private function assertOpen(EntitlementPeriod $period): void
    {
        if ($period->status !== 'open') {
            throw ValidationException::withMessages([
                'period' => 'Hanya entitlement period OPEN yang dapat disesuaikan.',
            ]);
        }
    }

    private function authorize(User $actor): void
    {
        if (! in_array($actor->role, [Role::SUPER_ADMIN, Role::ADMIN], true)) {
            throw ValidationException::withMessages([
                'actor' => 'Entitlement adjustment hanya boleh dilakukan oleh super admin atau admin.',
            ]);
        }
    }
}
