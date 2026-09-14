<?php

namespace App\Services\Alpha;

use App\Models\Observation;
use App\Models\ReportCycle;
use App\Models\Student;
use App\Models\Term;
use Illuminate\Support\Collection;

class ObservationSummaryService
{
    /**
     * @return array<string, mixed>
     */
    public function summarizeForStudent(Student $student, ?Term $term): array
    {
        return $this->summarizeBetween(
            $student,
            $term?->starts_on?->toDateString(),
            $term?->ends_on?->toDateString(),
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function summarizeForCycle(Student $student, ReportCycle $cycle): array
    {
        return $this->summarizeBetween(
            $student,
            $cycle->window_start->toDateString(),
            $cycle->cutoff_date->toDateString(),
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function summarizeBetween(Student $student, ?string $startDate, ?string $endDate): array
    {
        $observations = Observation::query()
            ->with(['developmentArea', 'indicator.developmentArea', 'teacher'])
            ->where('student_id', $student->id)
            ->where('status', '!=', 'archived')
            ->where(function ($query): void {
                $query
                    ->where('include_in_report', true)
                    ->orWhere('status', 'included_in_report');
            })
            ->when($startDate, fn ($query) => $query->whereDate('observed_on', '>=', $startDate))
            ->when($endDate, fn ($query) => $query->whereDate('observed_on', '<=', $endDate))
            ->orderByDesc('observed_on')
            ->orderByDesc('id')
            ->get();

        return [
            'total' => $observations->count(),
            'needs_follow_up' => $observations->where('needs_follow_up', true)->count(),
            'included_in_report' => $observations->where('include_in_report', true)->count(),
            'areas' => $this->areaSummaries($observations),
            'latest' => $observations->take(8)->values(),
        ];
    }

    /**
     * @param  Collection<int, Observation>  $observations
     * @return array<int, array<string, mixed>>
     */
    private function areaSummaries(Collection $observations): array
    {
        return $observations
            ->groupBy(function (Observation $observation): string {
                return $observation->developmentArea?->name
                    ?? $observation->indicator?->developmentArea?->name
                    ?? 'Area belum dipilih';
            })
            ->map(function (Collection $rows, string $areaName): array {
                $score = $rows->count() > 0 ? (int) round($rows->avg('score')) : 0;
                $latestNotes = $rows
                    ->whereNotNull('note')
                    ->take(4)
                    ->map(fn (Observation $observation): array => [
                        'date' => $observation->observed_on?->format('d M Y'),
                        'student_level' => $observation->level_label,
                        'indicator' => $observation->indicator?->description,
                        'note' => $observation->note,
                        'teacher' => $observation->teacher?->name,
                    ])
                    ->values()
                    ->all();

                return [
                    'name' => $areaName,
                    'score' => $score,
                    'observed' => $rows->count(),
                    'needs_follow_up' => $rows->where('needs_follow_up', true)->count(),
                    'level_counts' => $rows->countBy(fn (Observation $observation) => $observation->level ?? 'unknown')->all(),
                    'latest_notes' => $latestNotes,
                ];
            })
            ->sortBy('name')
            ->values()
            ->all();
    }
}
