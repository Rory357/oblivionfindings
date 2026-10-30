<?php

namespace App\Http\Controllers;

use App\Domain\SecurityDevices\Services\SecurityDevicesAccessService;
use App\Models\Asset;
use App\Models\AssetScanEvent;
use App\Services\AuditLogger;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class AssetScanEventController extends Controller
{
    public function store(Request $request, Asset $asset)
    {
        $this->authorize('recordScan', $asset);

        $data = $request->validate([
            'qr_token' => ['required', 'string', 'max:64', Rule::in([$asset->qr_token])],
            'scanned_at' => ['nullable', 'date', 'before_or_equal:now'],
            'site_id' => ['nullable', 'integer', 'exists:sites,id'],
            'client_id' => ['nullable', 'integer', 'exists:clients,id'],
            'context' => ['nullable', 'array:note'],
            'context.note' => ['nullable', 'string', 'max:1000'],
        ]);

        $access = app(SecurityDevicesAccessService::class);
        $siteId = $data['site_id'] ?? $asset->site_id;
        abort_unless($siteId && in_array((int) $siteId, $access->accessibleSiteIds($request->user()), true), 404);
        if (! empty($data['client_id'])) {
            $client = $access->assignableClient($request->user(), (int) $data['client_id']);
            abort_unless($client && (int) $client->site_id === (int) $siteId, 404);
        }
        $scan = DB::transaction(function () use ($request, $asset, $data, $siteId) {
            $scan = AssetScanEvent::create([
                'asset_id' => $asset->id,
                'qr_token' => $data['qr_token'],
                'scanned_by_type' => 'user',
                'scanned_by_id' => $request->user()->id,
                'scanned_at' => $data['scanned_at'] ?? now(),
                'site_id' => $siteId,
                'client_id' => $data['client_id'] ?? null,
                'context' => $data['context'] ?? null,
            ]);

            AuditLogger::logOrFail('assets.scan.logged', $asset, [
                'scan_id' => $scan->id,
            ]);

            return $scan;
        });

        return response()->json(['ok' => true, 'id' => $scan->id]);
    }
}
