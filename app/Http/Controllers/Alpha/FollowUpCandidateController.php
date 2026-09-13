<?php

namespace App\Http\Controllers\Alpha;

use App\Http\Controllers\Controller;
use App\Models\FollowUpCandidate;
use App\Models\Indicator;
use App\Services\Pedagogy\FollowUpCandidateService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class FollowUpCandidateController extends Controller
{
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
