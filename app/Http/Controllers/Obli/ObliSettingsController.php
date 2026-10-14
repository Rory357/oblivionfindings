<?php

namespace App\Http\Controllers\Obli;

use App\Domain\Obli\ObliAiSettings;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final class ObliSettingsController
{
    public function index(Request $request, ObliAiSettings $settings)
    {
        return DB::transaction(function () use ($request, $settings) {
            $actor = $settings->administrator($request->user());
            $data = ['actor_id' => $actor->id, 'configuration' => $settings->present()];

            return $request->expectsJson() ? response()->json($data) : inertia('settings/obli', $data);
        });
    }

    public function update(Request $request, ObliAiSettings $settings)
    {
        return response()->json(['actor_id' => $request->user()->id, 'configuration' => $settings->save($request->user(), $request->all())]);
    }
}
