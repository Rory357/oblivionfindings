<?php

namespace App\Http\Controllers;

use App\Models\Client;
use App\Models\ClientAssessment;
use Illuminate\Http\Request;

class ClientAssessmentController extends Controller
{
    public function store(Request $request, Client $client)
    {
        $this->authorize('update', $client);

        $data = $request->validate([
            'type' => ['nullable', 'string', 'max:100'],
            'score' => ['nullable', 'string', 'max:50'],
            'notes' => ['nullable', 'string', 'max:10000'],
            'assessed_at' => ['nullable', 'date'],
            'next_review_at' => ['nullable', 'date'],
        ]);

        ClientAssessment::create(array_merge($data, [
            'client_id' => $client->id,
            'created_by_user_id' => $request->user()?->id,
        ]));

        return back()->with('success', 'Assessment added.');
    }

    public function update(Request $request, Client $client, ClientAssessment $assessment)
    {
        $this->authorize('update', $client);
        abort_unless($assessment->client_id === $client->id, 404);

        $data = $request->validate([
            'type' => ['nullable', 'string', 'max:100'],
            'score' => ['nullable', 'string', 'max:50'],
            'notes' => ['nullable', 'string', 'max:10000'],
            'assessed_at' => ['nullable', 'date'],
            'next_review_at' => ['nullable', 'date'],
        ]);

        $assessment->update($data);

        return back()->with('success', 'Assessment updated.');
    }

    public function destroy(Request $request, Client $client, ClientAssessment $assessment)
    {
        $this->authorize('update', $client);
        abort_unless($assessment->client_id === $client->id, 404);

        $assessment->delete();

        return back()->with('success', 'Assessment removed.');
    }
}
