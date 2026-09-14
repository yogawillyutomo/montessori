<?php

namespace App\Services\Reporting;

use App\Models\Attendance;
use App\Models\ChildEnrollment;
use App\Models\DevelopmentArea;
use App\Models\Observation;
use App\Models\ReportCycle;
use App\Models\ReportEligibility;
use App\Models\Student;
use App\Models\User;
use App\Services\Alpha\AccessScopeService;
use App\Support\Alpha\Role;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ReportEligibilityService
{
    public function __construct(
        private readonly AccessScopeService $scope,
    ) {}

    public function evaluateForStudentCycle(Student $student, ReportCycle $cycle): ReportEligibility
    {
        $cycle->loadMissing('reportPolicy');
        $policy = $cycle->reportPolicy;

        if (! $policy) {
            throw ValidationException::withMessages([
                'report_cycle_id' => 'Report cycle tidak memiliki policy.',
            ]);
        }

        $enrollment = ChildEnrollment::query()
            ->where('student_id', $student->id)
            ->where('class_level_id', $policy->class_level_id)
            ->whereDate('starts_on', '<=', $cycle->cutoff_date)
            ->where(function ($query) use ($cycle): void {
                $query->whereNull('ends_on')
                    ->orWhereDate('ends_on', '>=', $cycle->window_start);
            })
            ->latest('starts_on')
            ->first();

        if (! $enrollment) {
            throw ValidationException::withMessages([
                'child_enrollment_id' => 'Tidak ada enrollment anak yang cocok dengan policy dan report cycle.',
            ]);
        }

        return $this->evaluate($enrollment, $cycle);
    }

    public function evaluate(ChildEnrollment $enrollment, ReportCycle $cycle): ReportEligibility
    {
        return DB::transaction(function () use ($enrollment, $cycle): ReportEligibility {
            $lockedEnrollment = ChildEnrollment::query()
                ->whereKey($enrollment->id)
                ->lockForUpdate()
                ->firstOrFail();
            $lockedCycle = ReportCycle::query()
                ->with('reportPolicy')
                ->whereKey($cycle->id)
                ->lockForUpdate()
                ->firstOrFail();
            $policy = $lockedCycle->reportPolicy;

            if (! $policy || (int) $policy->class_level_id !== (int) $lockedEnrollment->class_level_id) {
                throw ValidationException::withMessages([
                    'report_cycle_id' => 'Report cycle harus memakai policy dari level enrollment yang sama.',
                ]);
            }

            $cutoff = $lockedCycle->cutoff_date->toDateString();
            if ($lockedEnrollment->starts_on->toDateString() > $cutoff) {
                throw ValidationException::withMessages([
                    'child_enrollment_id' => 'Enrollment belum dimulai pada cutoff report cycle.',
                ]);
            }

            if ($lockedEnrollment->ends_on !== null
                && $lockedEnrollment->ends_on->toDateString() < $lockedCycle->window_start->toDateString()) {
                throw ValidationException::withMessages([
                    'child_enrollment_id' => 'Enrollment sudah berakhir sebelum evidence window report cycle.',
                ]);
            }

            $eligibility = ReportEligibility::query()
                ->where('report_cycle_id', $lockedCycle->id)
                ->where('child_enrollment_id', $lockedEnrollment->id)
                ->lockForUpdate()
                ->first();

            $hasPriorEligibleCycle = ReportEligibility::query()
                ->where('child_enrollment_id', $lockedEnrollment->id)
                ->where('status', 'eligible')
                ->whereHas('reportCycle', function ($query) use ($lockedCycle): void {
                    $query->whereDate('cutoff_date', '<', $lockedCycle->cutoff_date);
                })
                ->exists();
            $isFirstReportGate = ! $hasPriorEligibleCycle;

            $observationStart = $lockedEnrollment->first_session_on ?? $lockedEnrollment->starts_on;
            $observationDays = $observationStart->greaterThan($lockedCycle->cutoff_date)
                ? 0
                : (int) $observationStart->diffInDays($lockedCycle->cutoff_date);

            $evidenceStart = $isFirstReportGate
                ? $observationStart->toDateString()
                : $lockedCycle->window_start->toDateString();

            $attendedSessions = Attendance::query()
                ->where('student_id', $lockedEnrollment->student_id)
                ->where('status', 'present')
                ->whereNotNull('marked_at')
                ->whereHas('childSessionBooking', function ($bookingQuery) use ($lockedEnrollment, $evidenceStart, $cutoff): void {
                    $bookingQuery
                        ->where('child_enrollment_id', $lockedEnrollment->id)
                        ->whereHas('sessionOccurrence', function ($occurrenceQuery) use ($evidenceStart, $cutoff): void {
                            $occurrenceQuery
                                ->whereDate('occurs_on', '>=', $evidenceStart)
                                ->whereDate('occurs_on', '<=', $cutoff);
                        });
                })
                ->count();

            $coveredAreaCount = Observation::query()
                ->where('student_id', $lockedEnrollment->student_id)
                ->whereDate('observed_on', '>=', $evidenceStart)
                ->whereDate('observed_on', '<=', $cutoff)
                ->whereNotNull('development_area_id')
                ->distinct()
                ->count('development_area_id');
            $requiredAreaCount = DevelopmentArea::query()->count();

            $reasonCodes = [];
            if ($isFirstReportGate) {
                if ($policy->minimum_observation_days !== null
                    && $observationDays < $policy->minimum_observation_days) {
                    $reasonCodes[] = 'minimum_duration_not_met';
                }

                if ($policy->minimum_attended_sessions !== null
                    && $attendedSessions < $policy->minimum_attended_sessions) {
                    $reasonCodes[] = 'minimum_attendance_not_met';
                }

                if ($policy->area_coverage_mode === 'required'
                    && $requiredAreaCount > 0
                    && $coveredAreaCount < $requiredAreaCount) {
                    $reasonCodes[] = 'area_coverage_incomplete';
                }
            }

            $guideAlreadyConfirmed = $eligibility?->guide_confirmed_at !== null;
            if ($policy->require_guide_confirmation && ! $guideAlreadyConfirmed) {
                $reasonCodes[] = 'guide_confirmation_pending';
            }

            $overrideActive = $eligibility?->override_at !== null;
            $status = $reasonCodes === [] || $overrideActive ? 'eligible' : 'not_eligible';

            $payload = [
                'status' => $status,
                'reason_codes' => $reasonCodes,
                'observation_days' => $observationDays,
                'attended_sessions' => $attendedSessions,
                'covered_area_count' => $coveredAreaCount,
                'required_area_count' => $requiredAreaCount,
                'is_first_report_gate' => $isFirstReportGate,
                'evaluated_at' => now(),
            ];

            if ($eligibility) {
                $eligibility->forceFill($payload)->save();

                return $eligibility->fresh();
            }

            return ReportEligibility::query()->create([
                'report_cycle_id' => $lockedCycle->id,
                'child_enrollment_id' => $lockedEnrollment->id,
                ...$payload,
            ]);
        });
    }

    public function confirmGuideReadiness(
        ReportEligibility $eligibility,
        User $actor,
        ?string $note = null,
    ): ReportEligibility {
        return DB::transaction(function () use ($eligibility, $actor, $note): ReportEligibility {
            $locked = ReportEligibility::query()
                ->with(['childEnrollment.student', 'reportCycle.reportPolicy'])
                ->whereKey($eligibility->id)
                ->lockForUpdate()
                ->firstOrFail();
            $student = $locked->childEnrollment->student;

            if (! $this->scope->canGenerateReport($actor) || ! $this->scope->canViewStudent($actor, $student)) {
                throw ValidationException::withMessages([
                    'actor' => 'Actor tidak berwenang mengonfirmasi kesiapan report anak ini.',
                ]);
            }

            $evaluated = $this->evaluate($locked->childEnrollment, $locked->reportCycle);
            $blockingReasons = array_values(array_diff(
                (array) $evaluated->reason_codes,
                ['guide_confirmation_pending'],
            ));

            if ($blockingReasons !== [] && $evaluated->override_at === null) {
                throw ValidationException::withMessages([
                    'status' => 'Guide confirmation belum dapat diberikan karena objective eligibility criteria belum terpenuhi.',
                ]);
            }

            $evaluated->forceFill([
                'guide_confirmed_by' => $actor->id,
                'guide_confirmed_at' => now(),
                'guide_confirmation_note' => trim((string) $note) ?: null,
            ])->save();

            return $this->evaluate($evaluated->childEnrollment, $evaluated->reportCycle);
        });
    }

    public function overrideEligibility(
        ReportEligibility $eligibility,
        User $actor,
        string $reason,
    ): ReportEligibility {
        $reason = trim($reason);

        if (! in_array($actor->role, [Role::SUPER_ADMIN, Role::ADMIN], true)) {
            throw ValidationException::withMessages([
                'actor' => 'Eligibility override hanya boleh dilakukan super admin atau admin.',
            ]);
        }

        if ($reason === '') {
            throw ValidationException::withMessages([
                'override_reason' => 'Alasan eligibility override wajib dicatat.',
            ]);
        }

        return DB::transaction(function () use ($eligibility, $actor, $reason): ReportEligibility {
            $locked = ReportEligibility::query()
                ->with(['childEnrollment', 'reportCycle'])
                ->whereKey($eligibility->id)
                ->lockForUpdate()
                ->firstOrFail();
            $evaluated = $this->evaluate($locked->childEnrollment, $locked->reportCycle);

            $evaluated->forceFill([
                'status' => 'eligible',
                'override_by' => $actor->id,
                'override_at' => now(),
                'override_reason' => $reason,
            ])->save();

            return $evaluated->fresh();
        });
    }
}
