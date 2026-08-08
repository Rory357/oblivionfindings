<?php

namespace App\Http\Controllers;

use App\Models\Asset;
use App\Services\Assets\AssetLifecycleService;
use Illuminate\Http\Request;

/**
 * Legacy top-level asset controller.
 *
 * The index/show pages moved to the canonical `/fleet-assets` shell
 * (FleetAssets\AssetController); `/assets` and `/assets/{asset}` are now
 * permanent redirects in routes/assets.php. Only the delete endpoint
 * remains here.
 */
class AssetController extends Controller
{
    public function __construct(
        private readonly AssetLifecycleService $lifecycle,
    ) {}

    public function destroy(Request $request, Asset $asset)
    {
        $user = $request->user() ?? abort(403);
        $this->lifecycle->retire($user, $asset);

        return redirect()->route('fleet-assets.assets.index')
            ->with('success', 'Asset retired. Its lifecycle history has been retained.');
    }
}
