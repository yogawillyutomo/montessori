<?php

namespace App\Http\Controllers\Alpha;

use App\Http\Controllers\Controller;
use App\Models\Report;
use App\Models\ReportCycle;
use App\Models\Student;
use App\Services\Reporting\ReportWorkflowService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class CycleReportController extends Controller
{
    public function draft(
        Request $request,
        ReportCycle $reportCycle,
        Student $student,
        ReportWorkflowService $workflow,
    ): RedirectResponse {
        $actor = $request->user();
        abort_if(! $actor, 403);

        $report = $workflow->createOrRefreshDraft($student, $reportCycle, $actor);

        return redirect()
            ->route('alpha.reports.show', $report)
            ->with('status', 'Cycle report draft berhasil dibuat dari evidence window dan eligibility yang valid.');
    }

    public function update(Request $request, Report $report, ReportWorkflowService $workflow): RedirectResponse
    {
        $validated = $request->validate($this->contentRules());
        $actor = $request->user();
        abort_if(! $actor, 403);

        $workflow->saveContent($report, $actor, $validated);

        return back()->with('status', 'Isi cycle report berhasil disimpan.');
    }

    public function submit(Request $request, Report $report, ReportWorkflowService $workflow): RedirectResponse
    {
        $actor = $request->user();
        abort_if(! $actor, 403);
        $workflow->submitForReview($report, $actor);

        return back()->with('status', 'Cycle report diajukan untuk review.');
    }

    public function startReview(Request $request, Report $report, ReportWorkflowService $workflow): RedirectResponse
    {
        $actor = $request->user();
        abort_if(! $actor, 403);
        $workflow->startReview($report, $actor);

        return back()->with('status', 'Review cycle report dimulai.');
    }

    public function requestRevision(Request $request, Report $report, ReportWorkflowService $workflow): RedirectResponse
    {
        $validated = $request->validate([
            'note' => ['required', 'string', 'max:2000'],
        ]);
        $actor = $request->user();
        abort_if(! $actor, 403);
        $workflow->requestRevision($report, $actor, $validated['note']);

        return back()->with('status', 'Revisi cycle report diminta dan alasan tercatat di audit workflow.');
    }

    public function approve(Request $request, Report $report, ReportWorkflowService $workflow): RedirectResponse
    {
        $actor = $request->user();
        abort_if(! $actor, 403);
        $workflow->approve($report, $actor);

        return back()->with('status', 'Cycle report disetujui dan siap dipublish oleh admin.');
    }

    public function publish(Request $request, Report $report, ReportWorkflowService $workflow): RedirectResponse
    {
        $actor = $request->user();
        abort_if(! $actor, 403);
        $published = $workflow->publish($report, $actor);

        return redirect()
            ->route('alpha.reports.show', $published)
            ->with('status', 'Cycle report dipublish sebagai immutable version untuk orang tua.');
    }

    public function beginRevision(Request $request, Report $report, ReportWorkflowService $workflow): RedirectResponse
    {
        $validated = $request->validate([
            'reason' => ['required', 'string', 'max:2000'],
        ]);
        $actor = $request->user();
        abort_if(! $actor, 403);
        $workflow->beginRevision($report, $actor, $validated['reason']);

        return back()->with('status', 'Working revision baru dibuka. Published version sebelumnya tetap menjadi tampilan orang tua.');
    }

    public function archive(Request $request, Report $report, ReportWorkflowService $workflow): RedirectResponse
    {
        $actor = $request->user();
        abort_if(! $actor, 403);
        $workflow->archive($report, $actor);

        return back()->with('status', 'Cycle report diarsipkan.');
    }

    /**
     * @return array<string, array<int, string>>
     */
    private function contentRules(): array
    {
        return [
            'manual_present_total' => ['nullable', 'integer', 'min:0', 'max:999'],
            'manual_sick_total' => ['nullable', 'integer', 'min:0', 'max:999'],
            'manual_excused_total' => ['nullable', 'integer', 'min:0', 'max:999'],
            'manual_absent_total' => ['nullable', 'integer', 'min:0', 'max:999'],
            'manual_late_total' => ['nullable', 'integer', 'min:0', 'max:999'],
            'manual_attendance_note' => ['nullable', 'string', 'max:1200'],
            'teacher_narrative' => ['nullable', 'string', 'max:6000'],
            'general_narrative' => ['nullable', 'string', 'max:6000'],
            'social_emotional_narrative' => ['nullable', 'string', 'max:6000'],
            'independence_narrative' => ['nullable', 'string', 'max:6000'],
            'academic_narrative' => ['nullable', 'string', 'max:6000'],
            'parent_meeting_note' => ['nullable', 'string', 'max:3000'],
            'principal_note' => ['nullable', 'string', 'max:3000'],
        ];
    }
}
