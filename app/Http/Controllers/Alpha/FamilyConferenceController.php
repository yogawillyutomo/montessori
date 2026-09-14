<?php

namespace App\Http\Controllers\Alpha;

use App\Http\Controllers\Alpha\Concerns\ProvidesAlphaShell;
use App\Http\Controllers\Controller;
use App\Http\Requests\Alpha\FamilyConference\SaveFamilyConferenceRequest;
use App\Models\FamilyConference;
use App\Models\ReportVersion;
use App\Models\Student;
use App\Models\Teacher;
use App\Services\Family\FamilyConferenceService;
use App\Support\Alpha\Role;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class FamilyConferenceController extends Controller
{
    use ProvidesAlphaShell;

    public function index(
        Request $request,
        Student $student,
        FamilyConferenceService $conferences,
    ): View {
        $user = $request->user();
        abort_if(! $user || ! $conferences->canViewStudent($user, $student), 403);

        $student->loadMissing(['guardian', 'schoolClass.classLevel']);
        $conferenceQuery = FamilyConference::query()
            ->with(['leadTeacher', 'reportVersion.report.reportCycle', 'createdBy', 'updatedBy'])
            ->where('student_id', $student->id)
            ->orderByDesc('conference_on')
            ->orderByDesc('id');

        if ($user->role === Role::PARENT) {
            $conferenceQuery->where('share_with_parent', true);
        }

        $canManage = $conferences->canManage($user, $student);

        return view('alpha.family-conferences', [
            ...$this->shell($request, 'reports'),
            'student' => $student,
            'conferences' => $conferenceQuery->get(),
            'canManage' => $canManage,
            'isParentView' => $user->role === Role::PARENT,
            'teachers' => $canManage && $user->role !== Role::TEACHER
                ? Teacher::query()->where('is_active', true)->orderBy('name')->get()
                : collect(),
            'reportVersions' => $canManage
                ? ReportVersion::query()
                    ->with(['report.reportCycle'])
                    ->whereHas('report', fn ($query) => $query->where('student_id', $student->id))
                    ->orderByDesc('published_at')
                    ->get()
                : collect(),
        ]);
    }

    public function store(
        SaveFamilyConferenceRequest $request,
        Student $student,
        FamilyConferenceService $conferences,
    ): RedirectResponse {
        $conferences->create($student, $request->user(), $request->validated());

        return redirect()
            ->route('alpha.family-conferences.index', $student)
            ->with('status', 'Family conference berhasil dicatat.');
    }

    public function update(
        SaveFamilyConferenceRequest $request,
        FamilyConference $familyConference,
        FamilyConferenceService $conferences,
    ): RedirectResponse {
        $updated = $conferences->update($familyConference, $request->user(), $request->validated());

        return redirect()
            ->route('alpha.family-conferences.index', $updated->student_id)
            ->with('status', 'Family conference berhasil diperbarui.');
    }
}
