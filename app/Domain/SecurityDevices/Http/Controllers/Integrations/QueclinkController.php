<?php

namespace App\Domain\SecurityDevices\Http\Controllers\Integrations;

use App\Http\Controllers\Controller;
use App\Models\Integration\Integration;
use App\Models\Integration\IntegrationProviderConnection;
use App\Services\Integration\Adapters\QueclinkAdapter;
use Illuminate\Http\Request;

/**
 * Cleanup boundary for credentials created by the retired Queclink cloud
 * scaffold. New cloud credentials cannot be saved, tested, or rotated until a
 * verified public provider contract is implemented.
 */
class QueclinkController extends Controller
{
    private const PROVIDER = QueclinkAdapter::PROVIDER_SLUG;

    public function removeKey(Request $request)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('securityDevices.integrations.manage'), 403);

        IntegrationProviderConnection::query()
            ->forProvider(self::PROVIDER)
            ->delete();

        Integration::query()
            ->where('provider', self::PROVIDER)
            ->update([
                'status' => Integration::STATUS_INACTIVE,
            ]);

        return redirect()->back()->with('success', 'Legacy Queclink cloud credential removed. Native TCP monitoring and Device Management are unchanged.');
    }
}
