<?php

namespace App\Domain\Referrals\Http\Controllers;

use App\Domain\Referrals\Services\InternalSupportPreparation;
use App\Http\Controllers\Controller;
use App\Models\Client;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class InternalSupportPreparationController extends Controller
{
    public function show(Request $request, Client $client, InternalSupportPreparation $service): JsonResponse
    {
        return response()->json($service->options($request->user(), $client))->header('Cache-Control', 'no-store, private');
    }

    public function store(Request $request, Client $client, InternalSupportPreparation $service): JsonResponse
    {
        $record = $service->create($request->user(), $client->id, $request->all());

        return response()->json(['saved' => true, 'reference' => $record->reference, 'url' => '/operations/referrals/'.$record->reference, 'service_commenced' => false])
            ->header('Cache-Control', 'no-store, private');
    }
}
