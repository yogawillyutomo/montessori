<?php

namespace App\Services\Alpha;

use App\Models\Student;
use App\Models\Term;
use App\Models\User;
use App\Support\Alpha\Role;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;

class ReportStudentListService
{
    public function __construct(private readonly AccessScopeService $scope) {}

    /**
     * @param  array<string, mixed>  $filters
     * @return LengthAwarePaginator<int, Student>
     */
    public function paginateForUser(User $user, array $filters, ?Term $term, int $perPage = 12): LengthAwarePaginator
    {
        $query = $this->scope->accessibleStudentsQuery($user)
            ->with(['guardian', 'schoolClass.classLevel'])
            ->with(['reports' => function ($reportQuery) use ($term): void {
                $reportQuery
                    ->when($term, fn ($query) => $query->where('term_id', $term->id))
                    ->latest('updated_at');
            }])
            ->withCount(['observations as report_observations_count' => function (Builder $query) use ($term): void {
                $query
                    ->where('status', '!=', 'archived')
                    ->where(function (Builder $observationQuery): void {
                        $observationQuery
                            ->where('include_in_report', true)
                            ->orWhere('status', 'included_in_report');
                    })
                    ->when($term?->starts_on, fn ($dateQuery) => $dateQuery->whereDate('observed_on', '>=', $term->starts_on))
                    ->when($term?->ends_on, fn ($dateQuery) => $dateQuery->whereDate('observed_on', '<=', $term->ends_on));
            }]);

        $search = trim((string) ($filters['q'] ?? ''));
        $classId = (int) ($filters['school_class_id'] ?? 0);
        $teacherId = (int) ($filters['teacher_id'] ?? 0);
        $status = (string) ($filters['status'] ?? '');

        $query
            ->when($search !== '', function (Builder $studentQuery) use ($search): void {
                $studentQuery->where(function (Builder $searchQuery) use ($search): void {
                    $searchQuery
                        ->where('name', 'like', "%{$search}%")
                        ->orWhere('code', 'like', "%{$search}%")
                        ->orWhereHas('guardian', fn (Builder $guardianQuery) => $guardianQuery->where('name', 'like', "%{$search}%"));
                });
            })
            ->when($classId > 0, fn (Builder $studentQuery) => $studentQuery->where('school_class_id', $classId))
            ->when($teacherId > 0 && Role::hasGlobalAccess($user->role), fn (Builder $studentQuery) => $this->scope->scopeStudentsForTeacher($studentQuery, $teacherId));

        if ($user->role === Role::PARENT) {
            $query->whereHas('reports', function (Builder $reportQuery) use ($term): void {
                $reportQuery
                    ->where('status', 'published')
                    ->when($term, fn ($query) => $query->where('term_id', $term->id));
            });
        } elseif ($status === 'not_created') {
            $query->whereDoesntHave('reports', function (Builder $reportQuery) use ($term): void {
                $reportQuery->when($term, fn ($query) => $query->where('term_id', $term->id));
            });
        } elseif ($status !== '') {
            $query->whereHas('reports', function (Builder $reportQuery) use ($term, $status): void {
                $reportQuery
                    ->where('status', $status)
                    ->when($term, fn ($query) => $query->where('term_id', $term->id));
            });
        }

        return $query
            ->orderBy('name')
            ->paginate($perPage)
            ->withQueryString();
    }
}
