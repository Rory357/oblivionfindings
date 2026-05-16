<?php

namespace App\Http\Controllers\Sites;

use App\Http\Controllers\Controller;
use App\Models\Site;
use App\Services\Sites\SiteTypePlanService;
use Illuminate\Http\Request;
use Inertia\Inertia;

class SiteTypePlanController extends Controller
{
    public function __construct(
        private readonly SiteTypePlanService $plans,
    ) {}

    public function show(Request $request, Site $site)
    {
        $this->authorize('view', $site);

        return Inertia::render('sites/plan/index', [
            'site' => [
                'id' => $site->id,
                'name' => $site->name,
                'type' => $site->type,
                'display_type' => $site->display_type,
            ],
            'typePlan' => $this->plans->summaryFor($site),
            'can' => [
                'update' => (bool) ($request->user()?->can('update', $site)),
            ],
        ]);
    }

    public function storeDraft(Request $request, Site $site)
    {
        $this->authorize('update', $site);

        $data = $this->validatePlan($request);
        $plan = $this->plans->storeDraft(
            $site,
            $data['layout'] ?? $this->plans->seedDefaultLayout($site->type),
            $data['notes'] ?? null,
            $request->user()?->id,
        );

        return $this->respond($request, 'Draft plan saved.', [
            'plan' => $this->plans->serializePlan($plan),
            'typePlan' => $this->plans->summaryFor($site->fresh()),
        ]);
    }

    public function updateDraft(Request $request, Site $site)
    {
        $this->authorize('update', $site);

        $data = $this->validatePlan($request);
        $plan = $this->plans->storeDraft(
            $site,
            $data['layout'],
            $data['notes'] ?? null,
            $request->user()?->id,
        );

        return $this->respond($request, 'Draft plan updated.', [
            'plan' => $this->plans->serializePlan($plan),
            'typePlan' => $this->plans->summaryFor($site->fresh()),
        ]);
    }

    public function publish(Request $request, Site $site)
    {
        $this->authorize('update', $site);

        $plan = $this->plans->publishDraft($site, $request->user()?->id);

        return $this->respond($request, 'Plan published.', [
            'plan' => $this->plans->serializePlan($plan),
            'typePlan' => $this->plans->summaryFor($site->fresh()),
        ]);
    }

    public function duplicate(Request $request, Site $site)
    {
        $this->authorize('update', $site);

        $plan = $this->plans->cloneToDraft($site, $request->user()?->id);

        return $this->respond($request, 'Published plan copied to draft.', [
            'plan' => $this->plans->serializePlan($plan),
            'typePlan' => $this->plans->summaryFor($site->fresh()),
        ]);
    }

    public function discardDraft(Request $request, Site $site)
    {
        $this->authorize('update', $site);

        $this->plans->discardDraft($site);

        return $this->respond($request, 'Draft plan discarded.', [
            'typePlan' => $this->plans->summaryFor($site->fresh()),
        ]);
    }

    private function validatePlan(Request $request): array
    {
        return $request->validate([
            'layout' => ['required', 'array'],
            'notes' => ['nullable', 'string', 'max:5000'],
        ]);
    }

    private function respond(Request $request, string $message, array $payload)
    {
        if ($request->expectsJson()) {
            return response()->json($payload);
        }

        return back()->with('success', $message);
    }
}

