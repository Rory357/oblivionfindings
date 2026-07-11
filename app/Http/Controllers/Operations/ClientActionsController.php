<?php

namespace App\Http\Controllers\Operations;

use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Services\Client\ActionsAggregator;
use Illuminate\Http\Request;

class ClientActionsController extends Controller
{
    public function index(Request $request, Client $client, ActionsAggregator $aggregator)
    {
        $this->authorize('view', $client);

        $coverage = $aggregator->forClientWithCoverage($client, $request->user());

        return response()->json([
            'data' => $coverage['items'],
            'meta' => [
                'loaded' => count($coverage['items']),
                'has_more' => $coverage['has_more'],
            ],
        ]);
    }
}
