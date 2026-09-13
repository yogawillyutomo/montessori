<?php

namespace App\Http\Controllers\Alpha;

use App\Http\Controllers\Controller;
use App\Models\DevelopmentProgress;
use App\Models\Indicator;
use App\Models\MontessoriActivity;
use App\Models\Student;
use App\Models\Teacher;
use App\Services\Pedagogy\DevelopmentProgressService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class DevelopmentProgressController extends Controller
{
    public function store(Request $request, DevelopmentProgressService $progress): RedirectResponse
    {
        $validated = $request->validate([
            'student_id' => ['required', 'exists:students,id'],
            'teacher_id' => ['required', 'exists:teachers,id'],
            'indicator_id' => ['nullable', 'exists:indicators,id'],
            'montessori_activity_id' => ['nullable', 'exists:montessori_activities,id'],
            'progress_state' => ['required', 'string', Rule::in(DevelopmentProgress::STATES)],
            'judged_at' => ['nullable', 'date'],
            'note' => ['nullable', 'string', 'max:2000'],
        ]);

        $progress->record(
            Student::query()->findOrFail($validated['student_id']),
            Teacher::query()->findOrFail($validated['teacher_id']),
            $request->user(),
            $validated['progress_state'],
            isset($validated['indicator_id'])
                ? Indicator::query()->findOrFail($validated['indicator_id'])
                : null,
            isset($validated['montessori_activity_id'])
                ? MontessoriActivity::query()->findOrFail($validated['montessori_activity_id'])
                : null,
            $validated['judged_at'] ?? null,
            $validated['note'] ?? null,
        );

        return back()->with('status', 'Development progress berhasil dicatat.');
    }
}
