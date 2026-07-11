<?php

namespace App\Http\Controllers\Operations;

use App\Http\Controllers\Controller;
use App\Models\CarePlan;
use App\Models\CarePlanGoal;
use App\Models\ClientNote;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class CarePlanGoalController extends Controller
{
    public function store(Request $request, $carePlan)
    {
        $auth = $request->user();
        abort_unless($auth, 403);

        $carePlan = CarePlan::query()
            ->when($auth->organization_id, fn ($q) => $q->where('organization_id', $auth->organization_id))
            ->findOrFail($carePlan);
        $this->authorize('update', $carePlan);

        $this->ensureMutableCarePlan($carePlan);

        $data = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'category' => ['required', 'string', 'max:100'],
            'priority' => ['required', 'string', 'in:low,medium,high,critical'],
            'target_date' => ['nullable', 'date'],
            'status' => ['nullable', 'string', 'in:not_started,in_progress,completed,on_hold,cancelled'],
            'steps' => ['nullable', 'array'],
            'steps.*' => ['nullable', 'string', 'max:255'],
        ]);

        $goal = CarePlanGoal::create([
            'organization_id' => $auth->organization_id,
            'care_plan_id' => $carePlan->id,
            'client_id' => $carePlan->client_id,
            'title' => $data['title'],
            'description' => $data['description'] ?? null,
            'category' => $data['category'],
            'priority' => $data['priority'],
            'target_date' => $data['target_date'] ?? null,
            'status' => $data['status'] ?? 'not_started',
            'progress_percentage' => 0,
            'created_by' => $auth->id,
        ]);

        $titles = array_values(array_filter(
            $data['steps'] ?? [],
            fn ($t) => trim((string) $t) !== '',
        ));
        foreach ($titles as $i => $title) {
            $goal->steps()->create([
                'organization_id' => $auth->organization_id,
                'title' => $title,
                'sort_order' => $i + 1,
                'created_by' => $auth->id,
            ]);
        }
        $this->recalcProgress($goal);

        return redirect()->back()->with('success', 'Goal added.');
    }

    public function update(Request $request, $carePlan, $goal)
    {
        [, $goal] = $this->authorizeGoal($request, $carePlan, $goal);

        $data = $request->validate([
            'title' => ['sometimes', 'required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'category' => ['sometimes', 'required', 'string', 'max:100'],
            'priority' => ['sometimes', 'required', 'string', 'in:low,medium,high,critical'],
            'target_date' => ['nullable', 'date'],
            'status' => ['nullable', 'string', 'in:not_started,in_progress,completed,on_hold,cancelled'],
            'outcome_notes' => ['nullable', 'string'],
        ]);

        $goal->update($data);

        return redirect()->back()->with('success', 'Goal updated.');
    }

    public function destroy(Request $request, $carePlan, $goal)
    {
        [, $goal] = $this->authorizeGoal($request, $carePlan, $goal);

        $goal->delete();

        return redirect()->back()->with('success', 'Goal removed.');
    }

    /**
     * JSON goal detail for the manage-goal wizard: the goal, its sub-goals,
     * its hurdles (open + resolved) and the progress-note log.
     */
    public function show(Request $request, $carePlan, $goal)
    {
        [, $goal] = $this->authorizeGoal($request, $carePlan, $goal, false);

        $goal->load('steps');

        $notes = ClientNote::query()
            ->where('care_plan_goal_id', $goal->id)
            ->where('type', 'progress_note')
            ->with('author:id,name')
            ->orderByDesc('occurred_at')
            ->orderByDesc('created_at')
            ->get();

        return response()->json([
            'goal' => [
                'id' => $goal->id,
                'title' => $goal->title,
                'description' => $goal->description,
                'category' => $goal->category,
                'priority' => $goal->priority,
                'status' => $goal->status,
                'progress_percentage' => $goal->progress_percentage,
                'target_date' => optional($goal->target_date)->toDateString(),
                'outcome_notes' => $goal->outcome_notes,
            ],
            'steps' => $goal->steps->map(fn ($s) => [
                'id' => $s->id,
                'title' => $s->title,
                'is_complete' => (bool) $s->is_complete,
                'sort_order' => $s->sort_order,
                'target_date' => optional($s->target_date)->toDateString(),
            ])->values(),
            'hurdles' => $notes->where('category', 'goal_hurdle')->map(fn ($n) => [
                'id' => $n->id,
                'content' => $n->body,
                'reason' => $n->flagged_reason,
                'resolved' => ! $n->is_flagged,
                'author' => $n->author?->name,
                'created_at' => optional($n->occurred_at ?? $n->created_at)->toISOString(),
            ])->values(),
            'progress_log' => $notes->where('category', 'goal_progress')->map(fn ($n) => [
                'id' => $n->id,
                'content' => $n->body,
                'author' => $n->author?->name,
                'created_at' => optional($n->occurred_at ?? $n->created_at)->toISOString(),
            ])->values(),
        ]);
    }

    public function updateProgress(Request $request, $carePlan, $goal)
    {
        [, $goal] = $this->authorizeGoal($request, $carePlan, $goal);

        $data = $request->validate([
            'progress_percentage' => ['nullable', 'integer', 'min:0', 'max:100'],
            'status' => ['nullable', 'string', 'in:not_started,in_progress,completed,on_hold,cancelled'],
            'note' => ['nullable', 'string', 'max:2000'],
        ]);

        if ($goal->steps()->exists()) {
            // Auto-calculated from sub-goals — the manual percentage is ignored.
            if (! empty($data['status'])) {
                $goal->update(['status' => $data['status']]);
            }
            $this->recalcProgress($goal);
        } else {
            $pct = $data['progress_percentage'] ?? $goal->progress_percentage;
            $goal->update([
                'progress_percentage' => $pct,
                'status' => $data['status']
                    ?? ($pct >= 100 ? 'completed' : ($pct > 0 ? 'in_progress' : 'not_started')),
            ]);
        }

        if (! empty($data['note'])) {
            $this->logGoalNote($request, $goal, 'goal_progress', $data['note']);
        }

        return redirect()->back()->with('success', 'Progress updated.');
    }

    public function storeStep(Request $request, $carePlan, $goal)
    {
        [, $goal] = $this->authorizeGoal($request, $carePlan, $goal);

        $data = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'target_date' => ['nullable', 'date'],
        ]);

        $goal->steps()->create([
            'organization_id' => $goal->organization_id ?? $request->user()->organization_id,
            'title' => $data['title'],
            'target_date' => $data['target_date'] ?? null,
            'sort_order' => (int) ($goal->steps()->max('sort_order') ?? 0) + 1,
            'created_by' => $request->user()->id,
        ]);
        $this->recalcProgress($goal);

        return redirect()->back()->with('success', 'Sub-goal added.');
    }

    public function updateStep(Request $request, $carePlan, $goal, $step)
    {
        [, $goal] = $this->authorizeGoal($request, $carePlan, $goal);
        $step = $goal->steps()->findOrFail($step);

        $data = $request->validate([
            'title' => ['sometimes', 'required', 'string', 'max:255'],
            'is_complete' => ['sometimes', 'boolean'],
            'target_date' => ['nullable', 'date'],
            'sort_order' => ['sometimes', 'integer', 'min:0'],
        ]);

        if (array_key_exists('is_complete', $data)) {
            $data['completed_at'] = $data['is_complete'] ? now() : null;
            $data['completed_by'] = $data['is_complete'] ? $request->user()->id : null;
        }

        $step->update($data);
        $this->recalcProgress($goal);

        return redirect()->back()->with('success', 'Sub-goal updated.');
    }

    public function destroyStep(Request $request, $carePlan, $goal, $step)
    {
        [, $goal] = $this->authorizeGoal($request, $carePlan, $goal);
        $step = $goal->steps()->findOrFail($step);

        $step->delete();
        $this->recalcProgress($goal);

        return redirect()->back()->with('success', 'Sub-goal removed.');
    }

    public function addHurdle(Request $request, $carePlan, $goal)
    {
        [, $goal] = $this->authorizeGoal($request, $carePlan, $goal);

        $data = $request->validate([
            'content' => ['required', 'string', 'max:2000'],
            'reason' => ['nullable', 'string', 'max:500'],
        ]);

        $this->logGoalNote($request, $goal, 'goal_hurdle', $data['content'], true, $data['reason'] ?? null);

        return redirect()->back()->with('success', 'Hurdle logged.');
    }

    public function resolveHurdle(Request $request, $carePlan, $goal, $note)
    {
        [, $goal] = $this->authorizeGoal($request, $carePlan, $goal);

        $note = ClientNote::query()
            ->where('care_plan_goal_id', $goal->id)
            ->where('type', 'progress_note')
            ->where('category', 'goal_hurdle')
            ->findOrFail($note);

        $note->update(['is_flagged' => false]);

        return redirect()->back()->with('success', 'Hurdle resolved.');
    }

    /**
     * Authorize the caller, scope the care plan to their organization, and
     * resolve a goal that belongs to that plan. Returns [carePlan, goal].
     */
    private function authorizeGoal(
        Request $request,
        $carePlan,
        $goal,
        bool $requiresMutablePlan = true,
    ): array {
        $auth = $request->user();
        abort_unless($auth, 403);

        $carePlan = CarePlan::query()
            ->when($auth->organization_id, fn ($q) => $q->where('organization_id', $auth->organization_id))
            ->findOrFail($carePlan);
        $this->authorize('update', $carePlan);

        if ($requiresMutablePlan) {
            $this->ensureMutableCarePlan($carePlan);
        }

        $goal = CarePlanGoal::query()
            ->where('care_plan_id', $carePlan->id)
            ->findOrFail($goal);

        return [$carePlan, $goal];
    }

    private function ensureMutableCarePlan(CarePlan $carePlan): void
    {
        if (! $carePlan->isMutableVersion()) {
            throw ValidationException::withMessages([
                'care_plan' => 'Only the current working care plan version can be changed.',
            ]);
        }
    }

    /**
     * Recompute a goal's progress from its sub-goals. No-op when the goal has
     * no sub-goals (its percentage is set manually via updateProgress). A
     * manual on_hold / cancelled status is preserved.
     */
    private function recalcProgress(CarePlanGoal $goal): void
    {
        $total = $goal->steps()->count();
        if ($total === 0) {
            return;
        }

        $done = $goal->steps()->where('is_complete', true)->count();
        $pct = (int) round($done / $total * 100);

        $status = $goal->status;
        if (! in_array($status, ['on_hold', 'cancelled'], true)) {
            $status = $pct >= 100 ? 'completed' : ($pct > 0 ? 'in_progress' : 'not_started');
        }

        $goal->update([
            'progress_percentage' => $pct,
            'status' => $status,
        ]);
    }

    private function logGoalNote(
        Request $request,
        CarePlanGoal $goal,
        string $type,
        string $content,
        bool $flagged = false,
        ?string $flaggedReason = null,
    ): ClientNote {
        return ClientNote::create([
            'organization_id' => $request->user()->organization_id,
            'client_id' => $goal->client_id,
            'care_plan_goal_id' => $goal->id,
            'user_id' => $request->user()->id,
            'type' => 'progress_note',
            'category' => $type,
            'subject' => ucfirst(str_replace('_', ' ', $type)),
            'goal' => $goal->title,
            'body' => $content,
            'occurred_at' => now(),
            'is_flagged' => $flagged,
            'flagged_reason' => $flaggedReason,
            'visibility' => 'internal',
            'is_private' => false,
            'appears_on_timeline' => true,
            'is_draft' => false,
        ]);
    }
}
