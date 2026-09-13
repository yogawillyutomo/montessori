<?php

namespace App\Http\Controllers\Alpha;

use App\Http\Controllers\Controller;
use App\Models\MontessoriActivity;
use App\Models\SessionOccurrence;
use App\Models\Student;
use App\Models\Teacher;
use App\Services\Pedagogy\PresentationService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class PresentationController extends Controller
{
    public function store(Request $request, PresentationService $presentations): RedirectResponse
    {
        $validated = $request->validate([
            'student_id' => ['required', 'exists:students,id'],
            'teacher_id' => ['required', 'exists:teachers,id'],
            'montessori_activity_id' => ['required', 'exists:montessori_activities,id'],
            'session_occurrence_id' => ['nullable', 'exists:session_occurrences,id'],
            'presented_on' => ['required', 'date'],
            'presentation_type' => ['nullable', 'string', 'max:100'],
            'note' => ['nullable', 'string', 'max:2000'],
        ]);

        $presentations->record(
            Student::query()->findOrFail($validated['student_id']),
            Teacher::query()->findOrFail($validated['teacher_id']),
            MontessoriActivity::query()->findOrFail($validated['montessori_activity_id']),
            $request->user(),
            $validated['presented_on'],
            isset($validated['session_occurrence_id'])
                ? SessionOccurrence::query()->findOrFail($validated['session_occurrence_id'])
                : null,
            $validated['presentation_type'] ?? null,
            $validated['note'] ?? null,
        );

        return back()->with('status', 'Presentation Montessori berhasil dicatat.');
    }
}
