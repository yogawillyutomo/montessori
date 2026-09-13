<?php

namespace App\Http\Middleware;

use App\Models\ClassSession;
use App\Services\Alpha\AccessScopeService;
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

        // A teacher may mutate only a session that is already owned by that teacher.
        abort_if((int) $classSession->teacher_id !== (int) $teacher->id, 403);

        // Teacher/class ownership of an existing session is immutable for teacher users.
        if ($request->filled('teacher_id')) {
            abort_if((int) $request->input('teacher_id') !== (int) $teacher->id, 403);
        }

        if ($request->filled('school_class_id')) {
            abort_if((int) $request->input('school_class_id') !== (int) $classSession->school_class_id, 403);
        }

        // Most importantly, a PATCH must not be able to attach an arbitrary student and
        // thereby expand the teacher's broader student/report scope through the session.
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
