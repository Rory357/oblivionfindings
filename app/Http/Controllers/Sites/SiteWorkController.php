<?php

namespace App\Http\Controllers\Sites;

use App\Http\Controllers\Controller;
use App\Models\Site;
use App\Services\Sites\SiteWorkQueue;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * JSON reads for the inspections and maintenance pop-ups on the Sites list
 * and the cross-site Inspections & Maintenance page. Saving still goes to the
 * existing endpoints (SiteInspectionController::store / ::complete and
 * AssetMaintenanceController::store), which keep their own permissions.
 */
class SiteWorkController extends Controller
{
    public function __construct(
        private readonly SiteWorkQueue $queue,
    ) {}

    /** The Overdue list: checklists, inspections and maintenance past due. */
    public function overdue(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Site::class);

        $validated = $request->validate([
            'site_id' => ['nullable', 'integer'],
        ]);

        $user = $request->user();
        $siteIds = $this->queue->siteIds($user);

        if (filled($validated['site_id'] ?? null)) {
            $siteId = (int) $validated['site_id'];
            // A Site outside the viewer's list is indistinguishable from none.
            abort_unless(in_array($siteId, $siteIds, true), 404);
            $siteIds = [$siteId];
        }

        return response()->json($this->queue->overdue($user, $siteIds));
    }

    /**
     * Choices for one Site: its inspection schedules (to record a result) or
     * the assets this viewer may log maintenance for (searchable).
     */
    public function options(Request $request, Site $site): JsonResponse
    {
        $this->authorize('view', $site);
        abort_if((bool) $site->archived, 404);

        $validated = $request->validate([
            'kind' => ['required', Rule::in(['schedules', 'assets'])],
            'q' => ['nullable', 'string', 'max:100'],
        ]);

        $user = $request->user();

        if ($validated['kind'] === 'schedules') {
            // Same gate as recording the result (route + controller).
            abort_unless($user->canDo('checklists.run'), 403);
            $this->authorize('update', $site);

            return response()->json($this->queue->scheduleOptions($site));
        }

        abort_unless($user->canDo('assets.maintenance.record'), 403);

        return response()->json($this->queue->assetOptions($user, $site, $validated['q'] ?? null));
    }
}
