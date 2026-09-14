<?php

namespace App\Http\Controllers\Alpha;

use App\Http\Controllers\Controller;
use App\Models\IlpPlan;
use App\Services\Alpha\AccessScopeService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class IlpController extends Controller
{
    public function update(Request $request, IlpPlan $ilpPlan): RedirectResponse
    {
        $scope = app(AccessScopeService::class);
        abort_if(! in_array((int) $ilpPlan->student_id, $scope->accessibleStudentIds($request->user()), true), 403);

        $validated = $request->validate([
            'status' => ['required', 'in:draft,in_progress,completed,cancelled'],
            'analysis' => ['nullable', 'string', 'max:3000'],
            'target' => ['required', 'string', 'max:3000'],
            'follow_up' => ['nullable', 'string', 'max:3000'],
            'starts_on' => ['nullable', 'date'],
            'ends_on' => ['nullable', 'date', 'after_or_equal:starts_on'],
        ]);

        $ilpPlan->update($validated);

        return redirect(route('alpha.process.ilp')."#ilp-plan-{$ilpPlan->id}")
            ->with('status', 'Rencana ILP berhasil diperbarui.');
    }
}
