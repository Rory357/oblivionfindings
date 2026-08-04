<?php

namespace App\Domain\SecurityDevices\Http\Controllers;

use App\Domain\Monitoring\Models\ConfigurationSnapshot;
use App\Domain\Monitoring\Services\ConfigurationSnapshotService;
use App\Domain\SecurityDevices\Models\Device;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class ConfigurationSnapshotController extends Controller
{
    public function __invoke(
        Request $request,
        Device $device,
        ConfigurationSnapshot $snapshot,
        ConfigurationSnapshotService $snapshots,
    ): Response {
        abort_unless((int) $snapshot->device_id === (int) $device->id, 404);
        $payload = $snapshots->retrieve($snapshot, $request->user());
        $name = preg_replace('/[^A-Za-z0-9._-]/', '-', (string) $device->device_uid) ?: "device-{$device->id}";

        return response($payload, 200, [
            'Content-Type' => 'application/json',
            'Content-Disposition' => sprintf('attachment; filename="%s-configuration.json"', $name),
            'Cache-Control' => 'private, no-store, max-age=0',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
