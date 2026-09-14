<?php

namespace App\Services\Reporting;

use App\Models\Report;
use App\Models\ReportCycle;
use App\Models\ReportEligibility;
use App\Models\ReportVersion;
use App\Models\ReportWorkflowEvent;
use App\Models\Student;
use App\Models\Term;
use App\Models\User;
use App\Services\Alpha\AccessScopeService;
use App\Services\Alpha\ObservationSummaryService;
use App\Support\Alpha\Role;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ReportWorkflowService
{
    public function __construct(
        private readonly AccessScopeService $scope,
        private readonly ReportEligibilityService $eligibilities,
        private readonly ObservationSummaryService $observationSummary,
    ) {}

    public function createOrRefreshDraft(Student $student, ReportCycle $cycle, User $actor): Report
    {
        $this->assertCanEditStudent($actor, $student);

        $eligibility = $this->eligibilities->evaluateForStudentCycle($student, $cycle);
        if ($eligibility->status !== 'eligible') {
            throw ValidationException::withMessages([
                'eligibility' => 'Cycle report tidak dapat dibuat sebelum report eligibility berstatus ELIGIBLE.',
            ]);
        }

        $term = $this->termForCycle($cycle);

        return DB::transaction(function () use ($student, $cycle, $actor, $eligibility, $term): Report {
            $report = Report::query()
                ->where('student_id', $student->id)
                ->where('report_cycle_id', $cycle->id)
                ->lockForUpdate()
                ->first();

            if ($report && ! in_array($report->status, ['draft', 'revision_requested'], true)) {
                throw ValidationException::withMessages([
                    'status' => 'Draft hanya dapat diperbarui saat cycle report berstatus DRAFT atau REVISION_REQUESTED.',
                ]);
            }

            $summary = $this->summaryForCycle($student, $cycle, $term, $report);
            $teacher = $this->scope->teacherFor($actor);

            if ($report) {
                $report->forceFill([
                    'summary' => $summary,
                    'homeroom_teacher_id' => $report->homeroom_teacher_id ?: $teacher?->id,
                    'teacher_narrative' => $report->teacher_narrative ?: $this->draftNarrative($student, $summary),
                    'generated_at' => now(),
                ])->save();

                return $report->fresh();
            }

            $report = Report::query()->create([
                'student_id' => $student->id,
                'term_id' => $term->id,
                'report_cycle_id' => $cycle->id,
                'child_enrollment_id' => $eligibility->child_enrollment_id,
                'homeroom_teacher_id' => $teacher?->id,
                'status' => 'draft',
                'working_revision' => 1,
                'summary' => $summary,
                'teacher_narrative' => $this->draftNarrative($student, $summary),
                'generated_at' => now(),
            ]);

            $this->recordEvent($report, null, 'draft', $actor, 'Cycle report draft created after eligibility gate.');

            return $report->fresh();
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function saveContent(Report $report, User $actor, array $data): Report
    {
        $this->assertCycleReport($report);
        $this->assertCanEditStudent($actor, $report->student()->firstOrFail());

        return DB::transaction(function () use ($report, $data): Report {
            $locked = Report::query()
                ->with(['student', 'term', 'reportCycle'])
                ->whereKey($report->id)
                ->lockForUpdate()
                ->firstOrFail();

            if (! in_array($locked->status, ['draft', 'revision_requested'], true)) {
                throw ValidationException::withMessages([
                    'status' => 'Isi cycle report hanya dapat diedit saat DRAFT atau REVISION_REQUESTED.',
                ]);
            }

            $contentFields = [
                'manual_present_total',
                'manual_sick_total',
                'manual_excused_total',
                'manual_absent_total',
                'manual_late_total',
                'manual_attendance_note',
                'teacher_narrative',
                'general_narrative',
                'social_emotional_narrative',
                'independence_narrative',
                'academic_narrative',
                'parent_meeting_note',
                'principal_note',
            ];

            foreach ($contentFields as $field) {
                if (array_key_exists($field, $data)) {
                    $locked->{$field} = $data[$field];
                }
            }

            $locked->summary = $this->summaryForCycle(
                $locked->student,
                $locked->reportCycle,
                $locked->term,
                $locked,
            );
            $locked->save();

            return $locked->fresh();
        });
    }

    public function submitForReview(Report $report, User $actor): Report
    {
        $this->assertCycleReport($report);
        $this->assertCanEditStudent($actor, $report->student()->firstOrFail());
        $this->assertEligible($report);

        return $this->transition($report, $actor, 'submitted_for_review', function (Report $locked) use ($actor): void {
            $locked->submitted_by = $actor->id;
            $locked->submitted_at = now();
            $locked->review_started_by = null;
            $locked->review_started_at = null;
            $locked->approved_by = null;
            $locked->approved_at = null;
        });
    }

    public function startReview(Report $report, User $actor): Report
    {
        $this->assertCanReview($actor, $report);

        return $this->transition($report, $actor, 'under_review', function (Report $locked) use ($actor): void {
            $locked->review_started_by = $actor->id;
            $locked->review_started_at = now();
            $locked->reviewed_by = $actor->id;
            $locked->reviewed_at = now();
        });
    }

    public function requestRevision(Report $report, User $actor, string $note): Report
    {
        $this->assertCanReview($actor, $report);
        $note = trim($note);

        if ($note === '') {
            throw ValidationException::withMessages([
                'note' => 'Alasan permintaan revisi wajib dicatat.',
            ]);
        }

        return $this->transition($report, $actor, 'revision_requested', function (Report $locked) use ($actor, $note): void {
            $locked->revision_requested_by = $actor->id;
            $locked->revision_requested_at = now();
            $locked->revision_request_note = $note;
        }, $note);
    }

    public function approve(Report $report, User $actor): Report
    {
        $this->assertCanReview($actor, $report);
        $this->assertEligible($report);

        return $this->transition($report, $actor, 'approved', function (Report $locked) use ($actor): void {
            $locked->approved_by = $actor->id;
            $locked->approved_at = now();
        });
    }

    public function publish(Report $report, User $actor): Report
    {
        $this->assertCycleReport($report);
        $this->assertPublisher($actor);
        $this->assertEligible($report);

        return DB::transaction(function () use ($report, $actor): Report {
            $locked = Report::query()
                ->with([
                    'student.guardian',
                    'student.schoolClass.classLevel',
                    'term.academicYear',
                    'reportCycle.reportPolicy',
                    'homeroomTeacher',
                ])
                ->whereKey($report->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($locked->status !== 'approved') {
                throw ValidationException::withMessages([
                    'status' => 'Cycle report hanya dapat dipublish dari status APPROVED.',
                ]);
            }

            $versionNumber = ((int) ReportVersion::query()
                ->where('report_id', $locked->id)
                ->max('version_number')) + 1;
            $publishedAt = now();

            $version = ReportVersion::query()->create([
                'report_id' => $locked->id,
                'version_number' => $versionNumber,
                'snapshot' => $this->publicationSnapshot($locked, $versionNumber, $publishedAt->toISOString()),
                'published_by' => $actor->id,
                'published_at' => $publishedAt,
            ]);

            $from = $locked->status;
            $locked->forceFill([
                'status' => 'published',
                'published_version_id' => $version->id,
                'published_at' => $publishedAt,
            ])->save();
            $this->recordEvent($locked, $from, 'published', $actor, "Published immutable version {$versionNumber}.");

            return $locked->fresh(['publishedVersion']);
        });
    }

    public function beginRevision(Report $report, User $actor, string $reason): Report
    {
        $this->assertCycleReport($report);
        $this->assertPublisher($actor);
        $reason = trim($reason);

        if ($reason === '') {
            throw ValidationException::withMessages([
                'reason' => 'Alasan membuka revisi published report wajib dicatat.',
            ]);
        }

        return $this->transition($report, $actor, 'draft', function (Report $locked) use ($actor, $reason): void {
            $locked->working_revision = ((int) $locked->working_revision) + 1;
            $locked->revision_opened_by = $actor->id;
            $locked->revision_opened_at = now();
            $locked->revision_open_reason = $reason;
            $locked->submitted_by = null;
            $locked->submitted_at = null;
            $locked->review_started_by = null;
            $locked->review_started_at = null;
            $locked->revision_requested_by = null;
            $locked->revision_requested_at = null;
            $locked->revision_request_note = null;
            $locked->approved_by = null;
            $locked->approved_at = null;
        }, $reason);
    }

    public function archive(Report $report, User $actor): Report
    {
        $this->assertCycleReport($report);
        $this->assertPublisher($actor);

        return $this->transition($report, $actor, 'archived', function (Report $locked) use ($actor): void {
            $locked->archived_by = $actor->id;
            $locked->archived_at = now();
        });
    }

    private function transition(
        Report $report,
        User $actor,
        string $toStatus,
        callable $mutate,
        ?string $note = null,
    ): Report {
        return DB::transaction(function () use ($report, $actor, $toStatus, $mutate, $note): Report {
            $locked = Report::query()->whereKey($report->id)->lockForUpdate()->firstOrFail();
            $from = (string) $locked->status;
            $mutate($locked);
            $locked->status = $toStatus;
            $locked->save();
            $this->recordEvent($locked, $from, $toStatus, $actor, $note);

            return $locked->fresh();
        });
    }

    private function recordEvent(Report $report, ?string $from, string $to, User $actor, ?string $note = null): void
    {
        ReportWorkflowEvent::query()->create([
            'report_id' => $report->id,
            'from_status' => $from,
            'to_status' => $to,
            'actor_id' => $actor->id,
            'note' => $note,
            'occurred_at' => now(),
        ]);
    }

    private function assertCanEditStudent(User $actor, Student $student): void
    {
        if (! $this->scope->canGenerateReport($actor) || ! $this->scope->canViewStudent($actor, $student)) {
            throw ValidationException::withMessages([
                'actor' => 'Actor tidak berwenang mengedit report anak ini.',
            ]);
        }
    }

    private function assertCanReview(User $actor, Report $report): void
    {
        $this->assertCycleReport($report);
        $student = $report->student()->firstOrFail();

        if (! $this->scope->canApproveReport($actor) || ! $this->scope->canViewStudent($actor, $student)) {
            throw ValidationException::withMessages([
                'actor' => 'Review/approval cycle report hanya boleh dilakukan actor yang berwenang.',
            ]);
        }
    }

    private function assertPublisher(User $actor): void
    {
        if (! in_array($actor->role, [Role::SUPER_ADMIN, Role::ADMIN], true)) {
            throw ValidationException::withMessages([
                'actor' => 'Publish/reopen/archive cycle report hanya boleh dilakukan super admin atau admin.',
            ]);
        }
    }

    private function assertCycleReport(Report $report): void
    {
        if (! $report->isCycleReport()) {
            throw ValidationException::withMessages([
                'report_cycle_id' => 'Aksi M14 hanya berlaku untuk cycle report.',
            ]);
        }
    }

    private function assertEligible(Report $report): ReportEligibility
    {
        $eligibility = ReportEligibility::query()
            ->where('report_cycle_id', $report->report_cycle_id)
            ->where('child_enrollment_id', $report->child_enrollment_id)
            ->first();

        if (! $eligibility || $eligibility->status !== 'eligible') {
            throw ValidationException::withMessages([
                'eligibility' => 'Report workflow tidak dapat dilanjutkan tanpa eligibility berstatus ELIGIBLE.',
            ]);
        }

        return $eligibility;
    }

    private function termForCycle(ReportCycle $cycle): Term
    {
        $cutoff = $cycle->cutoff_date->toDateString();
        $terms = Term::query()
            ->with('academicYear')
            ->whereDate('starts_on', '<=', $cutoff)
            ->whereDate('ends_on', '>=', $cutoff)
            ->get();

        if ($terms->count() !== 1) {
            throw ValidationException::withMessages([
                'term_id' => 'Cycle report membutuhkan tepat satu academic term yang mencakup cutoff date. Perbaiki kalender akademik sebelum membuat draft.',
            ]);
        }

        return $terms->first();
    }

    /**
     * @return array<string, mixed>
     */
    private function summaryForCycle(Student $student, ReportCycle $cycle, Term $term, ?Report $report = null): array
    {
        $student->loadMissing(['guardian', 'schoolClass.classLevel']);
        $cycle->loadMissing('reportPolicy');
        $term->loadMissing('academicYear');
        $observation = $this->observationSummary->summarizeForCycle($student, $cycle);

        return [
            'biodata' => [
                'name' => $student->name,
                'code' => $student->code,
                'gender' => $student->gender,
                'birth_place' => $student->birth_place,
                'birth_date' => $student->birth_date?->toDateString(),
                'age' => $student->age_label,
                'class' => $student->schoolClass?->name,
                'level' => $student->schoolClass?->classLevel?->name ?? $student->schoolClass?->level,
                'guardian_name' => $student->guardian?->name,
                'guardian_relationship' => $student->guardian?->relationship,
                'guardian_phone' => $student->guardian?->phone,
                'address' => $student->guardian?->address,
            ],
            'term' => [
                'name' => $term->name,
                'academic_year' => $term->academicYear?->name,
                'starts_on' => $term->starts_on?->toDateString(),
                'ends_on' => $term->ends_on?->toDateString(),
            ],
            'report_cycle' => [
                'id' => $cycle->id,
                'name' => $cycle->name,
                'policy' => $cycle->reportPolicy?->name,
                'window_start' => $cycle->window_start->toDateString(),
                'window_end' => $cycle->window_end->toDateString(),
                'cutoff_date' => $cycle->cutoff_date->toDateString(),
            ],
            'observation' => $observation,
            'observation_count' => $observation['total'],
            'needs_support_count' => $observation['needs_follow_up'],
            'attendance' => $report?->manualAttendanceSummary() ?? [
                'recorded' => 0,
                'present' => 0,
                'late' => 0,
                'sick' => 0,
                'excused' => 0,
                'absent' => 0,
                'attendance_rate' => 0,
            ],
            'generated_from' => 'report_cycle_observations_and_manual_attendance',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function publicationSnapshot(Report $report, int $versionNumber, string $publishedAt): array
    {
        $student = $report->student;
        $term = $report->term;
        $cycle = $report->reportCycle;

        return [
            'schema_version' => 1,
            'report' => [
                'id' => $report->id,
                'student_id' => $report->student_id,
                'term_id' => $report->term_id,
                'report_cycle_id' => $report->report_cycle_id,
                'child_enrollment_id' => $report->child_enrollment_id,
                'homeroom_teacher_id' => $report->homeroom_teacher_id,
                'status' => 'published',
                'working_revision' => $report->working_revision,
                'summary' => $report->summary,
                'manual_present_total' => $report->manual_present_total,
                'manual_sick_total' => $report->manual_sick_total,
                'manual_excused_total' => $report->manual_excused_total,
                'manual_absent_total' => $report->manual_absent_total,
                'manual_late_total' => $report->manual_late_total,
                'manual_attendance_note' => $report->manual_attendance_note,
                'teacher_narrative' => $report->teacher_narrative,
                'general_narrative' => $report->general_narrative,
                'social_emotional_narrative' => $report->social_emotional_narrative,
                'independence_narrative' => $report->independence_narrative,
                'academic_narrative' => $report->academic_narrative,
                'parent_meeting_note' => $report->parent_meeting_note,
                'principal_note' => $report->principal_note,
                'published_at' => $publishedAt,
            ],
            'student' => [
                'name' => $student->name,
                'code' => $student->code,
                'gender' => $student->gender,
                'birth_place' => $student->birth_place,
                'birth_date' => $student->birth_date?->toDateString(),
                'class' => $student->schoolClass?->name,
                'level' => $student->schoolClass?->classLevel?->name ?? $student->schoolClass?->level,
                'guardian_name' => $student->guardian?->name,
                'guardian_relationship' => $student->guardian?->relationship,
            ],
            'period' => [
                'term' => $term ? [
                    'id' => $term->id,
                    'name' => $term->name,
                    'academic_year' => $term->academicYear?->name,
                ] : null,
                'cycle' => $cycle ? [
                    'id' => $cycle->id,
                    'name' => $cycle->name,
                    'window_start' => $cycle->window_start->toDateString(),
                    'window_end' => $cycle->window_end->toDateString(),
                    'cutoff_date' => $cycle->cutoff_date->toDateString(),
                    'policy' => $cycle->reportPolicy?->name,
                ] : null,
            ],
            'publication' => [
                'version_number' => $versionNumber,
                'published_at' => $publishedAt,
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $summary
     */
    private function draftNarrative(Student $student, array $summary): string
    {
        $observationCount = (int) ($summary['observation_count'] ?? 0);
        $cycleName = (string) ($summary['report_cycle']['name'] ?? 'cycle ini');

        if ($observationCount === 0) {
            return "Belum ada observasi yang dipilih sebagai bahan report {$cycleName} untuk {$student->name}.";
        }

        return "{$student->name} memiliki {$observationCount} catatan observasi sebagai bahan report {$cycleName}. Narasi ini masih draft dan harus melalui review sebelum dipublish.";
    }
}
