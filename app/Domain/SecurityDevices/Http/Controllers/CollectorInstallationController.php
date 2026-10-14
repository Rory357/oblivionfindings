<?php

namespace App\Domain\SecurityDevices\Http\Controllers;

use App\Domain\Monitoring\Services\CollectorInstallationProfile;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class CollectorInstallationController extends Controller
{
    public function options(Request $request, CollectorInstallationProfile $profiles): JsonResponse
    {
        abort_unless($request->isSecure(), 400);
        $data = $request->validate(['site_id' => ['required', 'integer', 'min:1'], 'platform' => ['required', 'in:windows,linux']]);

        return response()->json($profiles->options($request->user(), (int) $data['site_id'], $data['platform']), 200, $this->headers());
    }

    public function issue(Request $request, CollectorInstallationProfile $profiles): JsonResponse
    {
        abort_unless($request->isSecure(), 400);
        abort_if(array_diff(array_keys($request->all()), ['site_id', 'platform', 'context_hash']) !== [], 422);
        $data = $request->validate([
            'site_id' => ['required', 'integer', 'min:1'], 'platform' => ['required', 'in:windows,linux'],
            'context_hash' => ['required', 'string', 'regex:/\A[a-f0-9]{64}\z/'],
        ]);
        $profile = $profiles->issue($request->user(), (int) $data['site_id'], $data['platform'], $data['context_hash']);

        return response()->json($profile, 201, [
            ...$this->headers(),
            'Content-Disposition' => 'attachment; filename="oblivion-collector-'.$profile['collector_uuid'].'.json"',
        ]);
    }

    private function headers(): array
    {
        return ['Cache-Control' => 'private, no-store, max-age=0', 'Pragma' => 'no-cache', 'X-Content-Type-Options' => 'nosniff'];
    }
}
