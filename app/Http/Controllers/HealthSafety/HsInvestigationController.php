<?php

namespace App\Http\Controllers\HealthSafety;

use App\Http\Controllers\Controller;
use App\Models\HsEvent;
use App\Models\HsInvestigation;
use App\Services\HealthSafety\HsInvestigationService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Exposes the (already gated) HsInvestigationService over HTTP (E-Gap 3). Each
 * action maps to one service transition; the service enforces every guard
 * (open event + no active investigation; ≥1 cause; recommendations on complete;
 * no transition skips) and a forbidden transition surfaces as flash.error rather
 * than a raw write. All routes are gated `hazards.manage`.
 */
class HsInvestigationController extends Controller
{
    public function __construct(private readonly HsInvestigationService $service) {}

    /** Start an investigation: create (methodology, lead, team) then move to in_progress. */
    public function store(Request $request, HsEvent $event)
    {
        $data = $request->validate([
            'methodology' => ['required', Rule::in(HsInvestigation::VALID_METHODOLOGIES)],
            'lead_investigator_id' => ['required', 'integer', 'exists:users,id'],
            'team_member_ids' => ['nullable', 'array'],
            'team_member_ids.*' => ['integer', 'exists:users,id'],
            'target_completion_date' => ['nullable', 'date'],
        ]);

        try {
            $investigation = $this->service->create($event, [
                'methodology' => $data['methodology'],
                'lead_investigator_id' => $data['lead_investigator_id'],
                'team_member_ids' => $data['team_member_ids'] ?? [],
                'target_completion_date' => $data['target_completion_date'] ?? null,
            ]);
            $this->service->start($investigation, $data['lead_investigator_id']);
        } catch (\InvalidArgumentException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', 'Investigation started.');
    }

    /** Record findings: causes, contributing factors, recommendations (→ findings_recorded). */
    public function recordFindings(Request $request, HsEvent $event, HsInvestigation $investigation)
    {
        $this->ensureBelongs($event, $investigation);

        $data = $request->validate([
            'immediate_causes' => ['nullable', 'array'],
            'root_causes' => ['nullable', 'array'],
            'contributing_factors' => ['nullable', 'array'],
            'findings_summary' => ['nullable', 'string', 'max:5000'],
            'recommendations' => ['nullable', 'array'],
            'lessons_learned' => ['nullable', 'string', 'max:5000'],
        ]);

        try {
            $this->service->recordFindings($investigation, $data);
        } catch (\InvalidArgumentException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', 'Findings recorded.');
    }

    /** Submit for review (findings_recorded → under_review). */
    public function submit(HsEvent $event, HsInvestigation $investigation)
    {
        $this->ensureBelongs($event, $investigation);

        try {
            $this->service->submitForReview($investigation);
        } catch (\InvalidArgumentException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', 'Investigation submitted for review.');
    }

    /** Return for rework (under_review → in_progress) with reviewer notes. */
    public function returnForRework(Request $request, HsEvent $event, HsInvestigation $investigation)
    {
        $this->ensureBelongs($event, $investigation);

        $data = $request->validate(['review_notes' => ['required', 'string', 'max:2000']]);

        try {
            $this->service->returnForRework($investigation, $data['review_notes']);
        } catch (\InvalidArgumentException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', 'Investigation returned for rework.');
    }

    /** Complete (under_review → completed); auto-advances the event to corrective_action. */
    public function complete(Request $request, HsEvent $event, HsInvestigation $investigation)
    {
        $this->ensureBelongs($event, $investigation);

        $data = $request->validate(['approved_by_id' => ['nullable', 'integer', 'exists:users,id']]);

        try {
            $this->service->complete($investigation, [
                'reviewed_by_id' => $request->user()->id,
                'reviewed_at' => now(),
                'approved_by_id' => $data['approved_by_id'] ?? $request->user()->id,
                'approved_at' => now(),
            ]);
        } catch (\InvalidArgumentException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', 'Investigation completed.');
    }

    private function ensureBelongs(HsEvent $event, HsInvestigation $investigation): void
    {
        abort_unless($investigation->hs_event_id === $event->id, 404);
    }
}
