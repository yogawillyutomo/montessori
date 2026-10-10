<?php

namespace App\Http\Middleware;

use App\Models\ClassSession;
use App\Services\Alpha\AccessScopeService;
use App\Services\Scheduling\SessionWriteService;
use App\Support\Alpha\Role;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureTeacherSessionMutationScope
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user || $user->role !== Role::TEACHER) {
            return $next($request);
        }

        $scope = app(AccessScopeService::class);
        $teacher = $scope->teacherFor($user);
        abort_if(! $teacher, 403);

        $routeSession = $request->route('classSession');
        $classSession = $routeSession instanceof ClassSession
            ? $routeSession
            : ClassSession::query()->findOrFail($routeSession);
        $ownership = app(SessionWriteService::class)->ownership($classSession);

        // In target mode this ownership comes from canonical SessionOccurrence lineage,
        // so a stale/corrupted compatibility ClassSession cannot expand teacher scope.
        abort_if($ownership['teacher_id'] === null || $ownership['teacher_id'] !== (int) $teacher->id, 403);

        if ($request->filled('teacher_id')) {
            abort_if((int) $request->input('teacher_id') !== (int) $teacher->id, 403);
        }

        if ($request->filled('school_class_id')) {
            abort_if(
                $ownership['school_class_id'] === null
                || (int) $request->input('school_class_id') !== $ownership['school_class_id'],
                403,
            );
        }

        if ($request->boolean('student_ids_present')) {
            $requestedStudentIds = collect($request->input('student_ids', []))
                ->filter(fn ($id): bool => is_numeric($id))
                ->map(fn ($id): int => (int) $id)
                ->unique()
                ->values()
                ->all();

            $allowedStudentIds = $scope->accessibleStudentIds($user);
            abort_if(array_diff($requestedStudentIds, $allowedStudentIds) !== [], 403);
        }

        return $next($request);
    }
}
