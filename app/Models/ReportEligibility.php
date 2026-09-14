<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Validation\ValidationException;
use LogicException;

#[Fillable([
    'report_cycle_id',
    'child_enrollment_id',
    'status',
    'reason_codes',
    'observation_days',
    'attended_sessions',
    'covered_area_count',
    'required_area_count',
    'is_first_report_gate',
    'guide_confirmed_by',
    'guide_confirmed_at',
    'guide_confirmation_note',
    'override_by',
    'override_at',
    'override_reason',
    'evaluated_at',
])]
class ReportEligibility extends Model
{
    use HasFactory;

    public const STATUSES = [
        'not_eligible',
        'eligible',
    ];

    public const REASON_CODES = [
        'minimum_duration_not_met',
        'minimum_attendance_not_met',
        'guide_confirmation_pending',
        'area_coverage_incomplete',
    ];

    private const IDENTITY_FIELDS = [
        'report_cycle_id',
        'child_enrollment_id',
    ];

    protected static function booted(): void
    {
        static::saving(function (ReportEligibility $eligibility): void {
            if (! in_array($eligibility->status, self::STATUSES, true)) {
                throw ValidationException::withMessages([
                    'status' => 'Status report eligibility tidak valid.',
                ]);
            }

            $reasons = array_values(array_unique((array) ($eligibility->reason_codes ?? [])));
            foreach ($reasons as $reason) {
                if (! in_array($reason, self::REASON_CODES, true)) {
                    throw ValidationException::withMessages([
                        'reason_codes' => 'Reason code report eligibility tidak valid.',
                    ]);
                }
            }
            $eligibility->reason_codes = $reasons;

            if ($eligibility->guide_confirmed_at !== null && $eligibility->guide_confirmed_by === null) {
                throw ValidationException::withMessages([
                    'guide_confirmed_by' => 'Guide confirmation harus menyimpan actor.',
                ]);
            }

            if ($eligibility->override_at !== null) {
                if ($eligibility->override_by === null || trim((string) $eligibility->override_reason) === '') {
                    throw ValidationException::withMessages([
                        'override_reason' => 'Eligibility override harus menyimpan actor dan alasan.',
                    ]);
                }

                $eligibility->status = 'eligible';
            } elseif ($eligibility->status === 'eligible' && $reasons !== []) {
                throw ValidationException::withMessages([
                    'status' => 'Eligibility hanya boleh ELIGIBLE tanpa blocking reason atau melalui authorized override.',
                ]);
            }

            if ($eligibility->evaluated_at === null) {
                throw ValidationException::withMessages([
                    'evaluated_at' => 'Report eligibility harus memiliki waktu evaluasi.',
                ]);
            }
        });

        static::updating(function (ReportEligibility $eligibility): void {
            foreach (self::IDENTITY_FIELDS as $field) {
                if ($eligibility->isDirty($field)) {
                    throw new LogicException('Identitas cycle dan enrollment pada report eligibility tidak boleh diubah.');
                }
            }
        });

        static::deleting(function (): never {
            throw new LogicException('Report eligibility adalah histori keputusan dan tidak boleh dihapus. Evaluasi ulang record yang sama.');
        });
    }

    protected function casts(): array
    {
        return [
            'reason_codes' => 'array',
            'observation_days' => 'integer',
            'attended_sessions' => 'integer',
            'covered_area_count' => 'integer',
            'required_area_count' => 'integer',
            'is_first_report_gate' => 'boolean',
            'guide_confirmed_at' => 'datetime',
            'override_at' => 'datetime',
            'evaluated_at' => 'datetime',
        ];
    }

    public function reportCycle(): BelongsTo
    {
        return $this->belongsTo(ReportCycle::class);
    }

    public function childEnrollment(): BelongsTo
    {
        return $this->belongsTo(ChildEnrollment::class);
    }

    public function guideConfirmedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'guide_confirmed_by');
    }

    public function overrideBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'override_by');
    }
}
