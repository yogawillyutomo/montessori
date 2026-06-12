<?php

namespace App\Services\Alpha;

use App\Models\IlpPlan;
use App\Models\Report;
use App\Models\Student;
use App\Models\Teacher;
use App\Models\Term;
use App\Models\User;

class ReportBuilderService
{
    public function __construct(
        private readonly AccessScopeService $scope,
        private readonly ObservationSummaryService $observationSummary,
    ) {}

    public function buildDraft(Student $student, Term $term, User $user): Report
    {
        $report = Report::query()
            ->where('student_id', $student->id)
            ->where('term_id', $term->id)
            ->first();

        $summary = $this->summaryFor($student, $term);
        $teacher = $this->homeroomTeacherFor($student, $user);
        $status = $report?->status === 'published' ? 'published' : 'draft';

        return Report::query()->updateOrCreate(
            [
                'student_id' => $student->id,
                'term_id' => $term->id,
            ],
            [
                'homeroom_teacher_id' => $teacher?->id,
                'status' => $status,
                'summary' => $summary,
                'teacher_narrative' => $report?->teacher_narrative ?: $this->draftNarrative($student, $summary),
                'general_narrative' => $report?->general_narrative,
                'social_emotional_narrative' => $report?->social_emotional_narrative,
                'independence_narrative' => $report?->independence_narrative,
                'academic_narrative' => $report?->academic_narrative,
                'parent_meeting_note' => $report?->parent_meeting_note,
                'principal_note' => $report?->principal_note,
                'generated_at' => now(),
            ]
        );
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function saveManualReport(Student $student, Term $term, User $user, array $data): Report
    {
        $report = Report::query()->firstOrCreate(
            [
                'student_id' => $student->id,
                'term_id' => $term->id,
            ],
            [
                'homeroom_teacher_id' => $this->homeroomTeacherFor($student, $user)?->id,
                'status' => 'draft',
                'summary' => $this->summaryFor($student, $term),
                'generated_at' => now(),
            ]
        );

        $report->fill([
            'status' => $data['status'] ?? $report->status,
            'manual_present_total' => $data['manual_present_total'] ?? 0,
            'manual_sick_total' => $data['manual_sick_total'] ?? 0,
            'manual_excused_total' => $data['manual_excused_total'] ?? 0,
            'manual_absent_total' => $data['manual_absent_total'] ?? 0,
            'manual_late_total' => $data['manual_late_total'] ?? 0,
            'manual_attendance_note' => $data['manual_attendance_note'] ?? null,
            'teacher_narrative' => $data['teacher_narrative'] ?? null,
            'general_narrative' => $data['general_narrative'] ?? null,
            'social_emotional_narrative' => $data['social_emotional_narrative'] ?? null,
            'independence_narrative' => $data['independence_narrative'] ?? null,
            'academic_narrative' => $data['academic_narrative'] ?? null,
            'parent_meeting_note' => $data['parent_meeting_note'] ?? null,
            'principal_note' => $data['principal_note'] ?? null,
        ]);

        if ($report->status === 'ready' && ! $report->reviewed_at) {
            $report->reviewed_by = $user->id;
            $report->reviewed_at = now();
        }

        if ($report->status === 'published' && ! $report->published_at) {
            $report->published_at = now();
        }

        $report->summary = $this->summaryFor($student, $term, $report);
        $report->save();

        return $report;
    }

    /**
     * @return array<string, mixed>
     */
    public function summaryFor(Student $student, Term $term, ?Report $report = null): array
    {
        $student->loadMissing(['guardian', 'schoolClass.classLevel']);
        $observationSummary = $this->observationSummary->summarizeForStudent($student, $term);

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
            'observation' => $observationSummary,
            'observation_count' => $observationSummary['total'],
            'needs_support_count' => $observationSummary['needs_follow_up'],
            'attendance' => $report?->manualAttendanceSummary() ?? [
                'recorded' => 0,
                'present' => 0,
                'late' => 0,
                'sick' => 0,
                'excused' => 0,
                'absent' => 0,
                'attendance_rate' => 0,
            ],
            'ilp_plans' => $this->ilpSummaryForStudent($student, $term),
            'generated_from' => 'observations_and_manual_attendance',
        ];
    }

    public function publish(Report $report, User $user): Report
    {
        $report->forceFill([
            'status' => 'published',
            'published_at' => now(),
            'reviewed_by' => $report->reviewed_by ?: $user->id,
            'reviewed_at' => $report->reviewed_at ?: now(),
        ])->save();

        return $report;
    }

    private function homeroomTeacherFor(Student $student, User $user): ?Teacher
    {
        $teacher = $this->scope->teacherFor($user);

        if ($teacher) {
            return $teacher;
        }

        return Teacher::query()
            ->where(function ($query) use ($student): void {
                $query
                    ->whereHas('weeklySchedules.students', fn ($studentQuery) => $studentQuery->whereKey($student->id))
                    ->orWhereHas('classSessions.students', fn ($studentQuery) => $studentQuery->whereKey($student->id))
                    ->orWhereHas('observations', fn ($observationQuery) => $observationQuery->where('student_id', $student->id));
            })
            ->orderBy('name')
            ->first();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function ilpSummaryForStudent(Student $student, Term $term): array
    {
        return IlpPlan::query()
            ->with(['indicator.developmentArea'])
            ->where('student_id', $student->id)
            ->where(function ($query) use ($term): void {
                $query
                    ->where('term_id', $term->id)
                    ->orWhere(function ($dateQuery) use ($term): void {
                        $dateQuery
                            ->whereDate('starts_on', '<=', $term->ends_on)
                            ->whereDate('ends_on', '>=', $term->starts_on);
                    });
            })
            ->orderByRaw("case status when 'draft' then 1 when 'in_progress' then 2 when 'completed' then 3 else 4 end")
            ->latest('updated_at')
            ->get()
            ->map(fn (IlpPlan $plan): array => [
                'id' => $plan->id,
                'status' => $plan->status,
                'area' => $plan->indicator?->developmentArea?->name,
                'indicator_code' => $plan->indicator?->code,
                'indicator' => $plan->indicator?->description,
                'sub_area' => $plan->indicator?->sub_area,
                'analysis' => $plan->analysis,
                'target' => $plan->target,
                'follow_up' => $plan->follow_up,
                'starts_on' => $plan->starts_on?->toDateString(),
                'ends_on' => $plan->ends_on?->toDateString(),
            ])
            ->values()
            ->all();
    }

    /**
     * @param  array<string, mixed>  $summary
     */
    private function draftNarrative(Student $student, array $summary): string
    {
        $observationCount = (int) ($summary['observation_count'] ?? 0);

        if ($observationCount === 0) {
            return "Belum ada observasi yang dipilih sebagai bahan rapor untuk {$student->name}.";
        }

        $topArea = collect($summary['observation']['areas'] ?? [])
            ->sortByDesc('observed')
            ->first();

        $areaText = $topArea
            ? "Area yang paling banyak tercatat adalah {$topArea['name']} dengan {$topArea['observed']} catatan."
            : 'Catatan observasi sudah mulai terkumpul.';

        return "{$student->name} memiliki {$observationCount} catatan observasi sebagai bahan rapor. {$areaText} Narasi ini masih draft dan perlu dirapikan guru sebelum dibagikan ke orang tua.";
    }
}
