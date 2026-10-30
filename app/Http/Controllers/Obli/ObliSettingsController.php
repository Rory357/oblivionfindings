<?php

namespace App\Http\Controllers\Obli;

use App\Domain\Obli\ObliAiSettings;
use App\Domain\SecurityDevices\Endpoints\Services\EndpointRemoteSupportSettings;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final class ObliSettingsController
{
    public function index(Request $request, ObliAiSettings $settings, EndpointRemoteSupportSettings $remoteSupport)
    {
        return DB::transaction(function () use ($request, $settings, $remoteSupport) {
            $actor = $settings->administrator($request->user());
            $data = [
                'actor_id' => $actor->id,
                'configuration' => $settings->present(),
                'remote_support' => $remoteSupport->present(),
            ];

            return $request->expectsJson() ? response()->json($data) : inertia('settings/obli', $data);
        });
    }

    public function update(Request $request, ObliAiSettings $settings)
    {
        return response()->json(['actor_id' => $request->user()->id, 'configuration' => $settings->save($request->user(), $request->all())]);
    }

    public function updateRemoteSupport(Request $request, EndpointRemoteSupportSettings $remoteSupport)
    {
        $saved = $remoteSupport->save($request->user(), $request->all());

        return response()->json([
            'actor_id' => $request->user()->id,
            'remote_support' => $saved['remote_support'],
            'generated_relay_secret' => $saved['generated_relay_secret'],
        ]);
    }

    public function verifyRelay(Request $request, EndpointRemoteSupportSettings $remoteSupport)
    {
        return response()->json([
            'actor_id' => $request->user()->id,
            'verification' => $remoteSupport->verifyRelay($request->user()),
        ]);
    }
}
