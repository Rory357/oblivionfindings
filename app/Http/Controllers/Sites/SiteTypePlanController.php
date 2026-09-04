<?php

namespace App\Http\Controllers\Sites;

use App\Http\Controllers\Controller;
use App\Models\Site;
use App\Services\Sites\SiteTypePlanService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class SiteTypePlanController extends Controller
{
    public function __construct(
        private readonly SiteTypePlanService $plans,
    ) {}

    public function show(Request $request, Site $site)
    {
        $this->authorize('view', $site);

        // The plan builder lives inside the Site Profile (Safety > House Plan
        // tab) so building never leaves the site view; legacy /plan deep links
        // land there too.
        return redirect()->route('sites.show', ['site' => $site->id, 'tab' => 'plan']);
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
            'layout.schema_version' => ['nullable', 'integer', 'min:1', 'max:2'],
            'layout.canvas' => ['nullable', 'array'],
            'layout.canvas.width' => ['nullable', 'numeric', 'min:100', 'max:5000'],
            'layout.canvas.height' => ['nullable', 'numeric', 'min:100', 'max:5000'],
            'layout.grid' => ['nullable', 'array'],
            'layout.grid.size' => ['nullable', 'numeric', 'min:2', 'max:200'],
            'layout.grid.snap' => ['nullable', 'boolean'],
            'layout.export' => ['nullable', 'array'],
            'layout.export.paper' => ['nullable', Rule::in(['a3', 'a4', 'a5'])],
            'layout.export.orientation' => ['nullable', Rule::in(['landscape', 'portrait'])],
            'layout.rooms' => ['nullable', 'array'],
            'layout.walls' => ['nullable', 'array'],
            'layout.doors' => ['nullable', 'array'],
            'layout.windows' => ['nullable', 'array'],
            'layout.labels' => ['nullable', 'array'],
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
