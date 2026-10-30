<?php

namespace App\Http\Controllers\CompanyHub;

use App\Domain\CompanyHub\Services\HubRecognition;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

class HubRecognitionController extends Controller
{
    public function show(Request $request, HubRecognition $recognition)
    {
        return response()->json($recognition->read($request->user()))->header('Cache-Control', 'private, no-store');
    }

    public function update(Request $request, HubRecognition $recognition)
    {
        $data = $request->validate([
            'actor_id' => 'required|integer|min:1', 'version' => 'required|integer|min:1',
            'kudos_id' => 'present|nullable|integer|min:1', 'user_id' => 'prohibited',
        ]);

        return response()->json($recognition->select($request->user(), $data))->header('Cache-Control', 'private, no-store');
    }
}
