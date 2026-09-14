<?php

namespace App\Http\Controllers\Alpha;

use App\Http\Controllers\Alpha\Concerns\ProvidesAlphaShell;
use App\Http\Controllers\Controller;
use App\Models\FollowUpCandidate;
use App\Models\Indicator;
use App\Services\Alpha\AccessScopeService;
use App\Services\Pedagogy\FollowUpCandidateService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class FollowUpCandidateController extends Controller
{
    use ProvidesAlphaShell;

    public function index(Request $request, AccessScopeService $scope): View
    {
        $requestedStatus = $request->query('status', FollowUpCandidate::STATUS_OPEN);
        $status = in_array($requestedStatus, FollowUpCandidate::STATUSES, true)
            ? $requestedStatus
            : FollowUpCandidate::STATUS_OPEN;
        $studentIds = $scope->accessibleStudentIds($request->user());

        return view('alpha.follow-up-candidates', [
            ...$this->shell($request, 'process'),
            'selectedStatus' => $status,
            'statusOptions' => FollowUpCandidate::STATUSES,
            'candidates' => FollowUpCandidate::query()
                ->with([
                    'student.schoolClass',
                    'sourceObservation.teacher',
                    'sourceObservation.developmentArea',
                    'indicator.developmentArea',
                    'reviewedBy',
                    'supportPlan',
                ])
                ->whereIn('student_id', $studentIds)
                ->where('status', $status)
                ->latest()
                ->get(),
            'indicators' => Indicator::query()
                ->with('developmentArea')
                ->where('is_active', true)
                ->orderBy('development_area_id')
                ->orderBy('code')
                ->get(),
        ]);
    }

    public function confirm(
        Request $request,
        FollowUpCandidate $followUpCandidate,
        FollowUpCandidateService $followUps,
    ): RedirectResponse {
        $validated = $request->validate([
            'indicator_id' => ['nullable', 'exists:indicators,id'],
            'review_note' => ['nullable', 'string', 'max:3000'],
        ]);

        $plan = $followUps->confirm(
            $followUpCandidate,
            $request->user(),
            isset($validated['indicator_id'])
                ? Indicator::query()->findOrFail($validated['indicator_id'])
                : null,
            $validated['review_note'] ?? null,
        );

        return redirect(route('alpha.process.ilp')."#ilp-plan-{$plan->id}")
            ->with('status', 'Follow-up candidate dikonfirmasi. Draft support plan siap ditinjau guide.');
    }

    public function dismiss(
        Request $request,
        FollowUpCandidate $followUpCandidate,
        FollowUpCandidateService $followUps,
    ): RedirectResponse {
        $validated = $request->validate([
            'review_note' => ['nullable', 'string', 'max:3000'],
        ]);

        $followUps->dismiss(
            $followUpCandidate,
            $request->user(),
            $validated['review_note'] ?? null,
        );

        return back()->with('status', 'Follow-up candidate ditandai tidak memerlukan support plan.');
    }
}
