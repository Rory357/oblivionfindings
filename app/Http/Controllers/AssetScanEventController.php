<?php

namespace App\Http\Controllers;

use App\Models\Asset;
use App\Models\AssetScanEvent;
use App\Services\AuditLogger;
use Illuminate\Http\Request;

class AssetScanEventController extends Controller
{
    public function store(Request $request, Asset $asset)
    {
        $this->authorize('recordScan', $asset);

        $data = $request->validate([
            'qr_token' => ['required', 'string', 'max:64'],
            'scanned_by_type' => ['required', 'string', 'max:40'],
            'scanned_by_id' => ['required', 'integer'],
            'scanned_at' => ['nullable', 'date'],
            'site_id' => ['nullable', 'integer', 'exists:sites,id'],
            'client_id' => ['nullable', 'integer', 'exists:clients,id'],
            'context' => ['nullable', 'array'],
        ]);

        $scan = AssetScanEvent::create([
            'asset_id' => $asset->id,
            'qr_token' => $data['qr_token'],
            'scanned_by_type' => $data['scanned_by_type'],
            'scanned_by_id' => $data['scanned_by_id'],
            'scanned_at' => $data['scanned_at'] ?? now(),
            'site_id' => $data['site_id'] ?? null,
            'client_id' => $data['client_id'] ?? null,
            'context' => $data['context'] ?? null,
        ]);

        AuditLogger::log('assets.scan.logged', $asset, [
            'scan_id' => $scan->id,
            'qr_token' => $scan->qr_token,
        ]);

        return response()->json(['ok' => true, 'id' => $scan->id]);
    }
}
