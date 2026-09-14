<?php

namespace App\Http\Controllers\Alpha;

use App\Http\Controllers\Controller;
use App\Models\ClassSession;
use App\Models\Indicator;
use App\Models\Observation;
use App\Models\Teacher;
use App\Services\Alpha\AccessScopeService;
use App\Services\Pedagogy\FollowUpCandidateService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

class ObservationController extends Controller
{
    public function store(
        Request $request,
        AccessScopeService $scope,
        FollowUpCandidateService $followUps,
    ): RedirectResponse {
        $teacher = $request->user() ? $scope->teacherFor($request->user()) : null;

        if ($request->has('observations')) {
            $validated = $request->validate([
                'class_session_id' => ['required', 'exists:class_sessions,id'],
                'student_id' => ['required', 'exists:students,id'],
                'teacher_id' => ['required', 'exists:teachers,id'],
                'observed_on' => ['required', 'date'],
                'note' => ['nullable', 'string', 'max:2000'],
                'observations' => ['required', 'array', 'min:1'],
                'observations.*.status' => ['required', 'in:achieved,emerging,needs_support,developing,independent,exceeding'],
            ]);

            $this->assertObservationTeacher($teacher, (int) $validated['teacher_id']);
            abort_if(! in_array((int) $validated['student_id'], $scope->accessibleStudentIds($request->user()), true), 403);

            $classSession = ClassSession::query()->findOrFail($validated['class_session_id']);
            $this->authorizeTeacherSession($request, $classSession, $scope);
            $this->assertSessionStudent($classSession, (int) $validated['student_id']);

            $createdCount = 0;
            foreach ($validated['observations'] as $indicatorId => $row) {
                $indicator = Indicator::query()->findOrFail((int) $indicatorId);
                $level = $this->normalizeObservationLevel($row['status']);
                $needsFollowUp = $row['status'] === 'needs_support';

                $observation = Observation::query()->create([
                    'class_session_id' => $classSession->id,
                    'student_id' => $validated['student_id'],
                    'indicator_id' => $indicator->id,
                    'development_area_id' => $indicator->development_area_id,
                    'teacher_id' => $validated['teacher_id'],
                    'observation_type' => 'scheduled',
                    'observed_on' => Carbon::parse($validated['observed_on'])->toDateString(),
                    'level' => $level,
                    'status' => 'included_in_report',
                    'score' => Observation::scoreForLevel($level),
                    'note' => $validated['note'] ?? null,
                    'needs_follow_up' => $needsFollowUp,
                    'include_in_report' => true,
                ]);
                $createdCount++;

                if ($observation->needs_follow_up) {
                    $followUps->captureFromObservation($observation, $request->user());
                }
            }

            return redirect(route('alpha.process.observations').'#monitoring-harian')
                ->with('status', "{$createdCount} observasi tersimpan sebagai bahan perkembangan siswa.");
        }

        $validated = $request->validate([
            'class_session_id' => ['nullable', 'exists:class_sessions,id'],
            'student_id' => ['required', 'exists:students,id'],
            'teacher_id' => ['required', 'exists:teachers,id'],
            'development_area_id' => ['required', 'exists:development_areas,id'],
            'indicator_id' => ['nullable', 'exists:indicators,id'],
            'observed_on' => ['required', 'date'],
            'level' => ['required', 'in:emerging,developing,independent,exceeding'],
            'note' => ['required', 'string', 'max:2000'],
            'needs_follow_up' => ['nullable', 'boolean'],
            'include_in_report' => ['nullable', 'boolean'],
        ]);

        $this->assertObservationTeacher($teacher, (int) $validated['teacher_id']);
        abort_if(! in_array((int) $validated['student_id'], $scope->accessibleStudentIds($request->user()), true), 403);

        $classSession = null;
        if ($validated['class_session_id'] ?? null) {
            $classSession = ClassSession::query()->findOrFail($validated['class_session_id']);
            $this->authorizeTeacherSession($request, $classSession, $scope);
            $this->assertSessionStudent($classSession, (int) $validated['student_id']);
        }

        $indicator = isset($validated['indicator_id'])
            ? Indicator::query()->findOrFail($validated['indicator_id'])
            : null;

        if ($indicator && (int) $indicator->development_area_id !== (int) $validated['development_area_id']) {
            throw ValidationException::withMessages([
                'indicator_id' => 'Indikator yang dipilih tidak sesuai dengan area perkembangan.',
            ]);
        }

        $includeInReport = $request->boolean('include_in_report');
        $observation = Observation::query()->create([
            'class_session_id' => $classSession?->id,
            'student_id' => $validated['student_id'],
            'indicator_id' => $indicator?->id,
            'development_area_id' => $validated['development_area_id'],
            'teacher_id' => $validated['teacher_id'],
            'observation_type' => $classSession ? 'scheduled' : 'spontaneous',
            'observed_on' => Carbon::parse($validated['observed_on'])->toDateString(),
            'level' => $validated['level'],
            'status' => $includeInReport ? 'included_in_report' : 'saved',
            'score' => Observation::scoreForLevel($validated['level']),
            'note' => $validated['note'],
            'needs_follow_up' => $request->boolean('needs_follow_up'),
            'include_in_report' => $includeInReport,
        ]);

        if ($observation->needs_follow_up) {
            $followUps->captureFromObservation($observation, $request->user());
        }

        return redirect(route('alpha.process.observations').'#monitoring-harian')
            ->with('status', 'Observasi cepat berhasil disimpan.');
    }

    private function assertObservationTeacher(?Teacher $teacher, int $submittedTeacherId): void
    {
        if ($teacher && $submittedTeacherId !== $teacher->id) {
            throw ValidationException::withMessages([
                'teacher_id' => 'Guru hanya boleh menyimpan observasi atas nama dirinya sendiri.',
            ]);
        }
    }

    private function authorizeTeacherSession(
        Request $request,
        ClassSession $classSession,
        AccessScopeService $scope,
    ): void {
        $teacher = $scope->teacherFor($request->user());

        abort_if($teacher && (int) $classSession->teacher_id !== $teacher->id, 403);
    }

    private function assertSessionStudent(ClassSession $classSession, int $studentId): void
    {
        $sessionStudentExists = $classSession
            ->students()
            ->whereKey($studentId)
            ->exists();

        if (! $sessionStudentExists) {
            throw ValidationException::withMessages([
                'student_id' => 'Siswa harus terdaftar pada sesi belajar yang dipilih.',
            ]);
        }
    }

    private function normalizeObservationLevel(string $value): string
    {
        return [
            'achieved' => 'independent',
            'needs_support' => 'emerging',
        ][$value] ?? $value;
    }
}
