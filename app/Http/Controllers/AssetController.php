<?php

namespace App\Http\Controllers;

use App\Models\Asset;
use App\Services\AuditLogger;
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
    public function destroy(Request $request, Asset $asset)
    {
        $this->authorize('delete', $asset);

        $asset->delete();

        AuditLogger::log('assets.delete', $asset, [
            'site_id' => $asset->site_id,
            'client_id' => $asset->client_id,
        ]);

        return redirect()->route('fleet-assets.assets.index');
    }
}
