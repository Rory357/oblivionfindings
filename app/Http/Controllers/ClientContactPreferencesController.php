<?php

namespace App\Http\Controllers;

use App\Models\Client;
use App\Services\Clients\ClientContactPreferences;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class ClientContactPreferencesController extends Controller
{
    public function update(Request $request, Client $client, ClientContactPreferences $contacts): JsonResponse
    {
        $this->authorize('view', $client);
        $this->authorize('update', $client);
        $data = $request->validate(['fingerprint' => 'required|string|size:64', 'review' => 'required|array']);

        return response()->json(['saved' => true, 'contact_profile' => $contacts->update($request->user(), $client->id, $data['fingerprint'], $data['review'])])
            ->header('Cache-Control', 'no-store, private');
    }
}
