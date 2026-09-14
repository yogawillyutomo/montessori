<?php

namespace App\Http\Controllers\Alpha;

use App\Http\Controllers\Controller;
use App\Models\ReportCycle;
use App\Models\ReportEligibility;
use App\Models\Student;
use App\Services\Alpha\AccessScopeService;
use App\Services\Reporting\ReportEligibilityService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class ReportEligibilityController extends Controller
{
    public function evaluate(
        Request $request,
        ReportCycle $reportCycle,
        Student $student,
        ReportEligibilityService $eligibilities,
    ): RedirectResponse {
        $actor = $request->user();
        $scope = app(AccessScopeService::class);

        abort_if(! $actor || ! $scope->canGenerateReport($actor) || ! $scope->canViewStudent($actor, $student), 403);

        $eligibility = $eligibilities->evaluateForStudentCycle($student, $reportCycle);

        return back()->with(
            'status',
            $eligibility->status === 'eligible'
                ? 'Anak memenuhi report eligibility untuk cycle ini.'
                : 'Report eligibility diperbarui. Anak masih dalam observation/readiness process.'
        );
    }

    public function confirm(
        Request $request,
        ReportEligibility $reportEligibility,
        ReportEligibilityService $eligibilities,
    ): RedirectResponse {
        $validated = $request->validate([
            'note' => ['nullable', 'string', 'max:1000'],
        ]);
        $actor = $request->user();
        abort_if(! $actor, 403);

        $eligibilities->confirmGuideReadiness(
            $reportEligibility,
            $actor,
            $validated['note'] ?? null,
        );

        return back()->with('status', 'Guide readiness untuk report berhasil dikonfirmasi.');
    }

    public function override(
        Request $request,
        ReportEligibility $reportEligibility,
        ReportEligibilityService $eligibilities,
    ): RedirectResponse {
        $validated = $request->validate([
            'reason' => ['required', 'string', 'max:1000'],
        ]);
        $actor = $request->user();
        abort_if(! $actor, 403);

        $eligibilities->overrideEligibility(
            $reportEligibility,
            $actor,
            $validated['reason'],
        );

        return back()->with('status', 'Report eligibility override berhasil dicatat dengan audit.');
    }
}
