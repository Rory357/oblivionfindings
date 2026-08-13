<?php

namespace App\Http\Controllers\Portal;

use App\Http\Controllers\Controller;
use App\Models\CarePlan;
use App\Models\Client;
use App\Services\Operations\CarePlanAttestationService;
use Illuminate\Http\Request;

final class CarePlanAttestationController extends Controller
{
    public function __construct(
        private readonly CarePlanAttestationService $attestations,
    ) {}

    public function store(Request $request, Client $client, CarePlan $carePlan)
    {
        $actor = $request->user();
        abort_unless($actor, 404);
        abort_unless($carePlan->client_id === $client->id, 404);
        abort_unless($actor->canAccessClientPortal($client), 404);

        $data = $request->validate($this->attestations->validationRules());
        $this->attestations->record($carePlan, $actor, $data, 'portal');

        return back()->with('success', 'Sign-off recorded.');
    }
}
