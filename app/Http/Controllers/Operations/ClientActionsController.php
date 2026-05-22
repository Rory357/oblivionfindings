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

        return response()->json([
            'data' => $aggregator->forClient($client, $request->user()),
        ]);
    }
}
