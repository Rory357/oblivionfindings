<?php

namespace App\Http\Controllers;

use App\Models\Client;
use App\Models\ClientSupportPlan;
use App\Services\NotificationService;
use Illuminate\Http\Request;

class ClientSupportPlanController extends Controller
{
    public function update(Request $request, Client $client)
    {
        $this->authorize('update', $client);

        $data = $request->validate([
            'goals' => ['nullable', 'string', 'max:10000'],
            'routines' => ['nullable', 'string', 'max:10000'],
            'preferences' => ['nullable', 'string', 'max:10000'],
            'communication_needs' => ['nullable', 'string', 'max:10000'],
            'cultural_needs' => ['nullable', 'string', 'max:10000'],
            'risk_notes' => ['nullable', 'string', 'max:10000'],
            'reviewed_at' => ['nullable', 'date'],
            'next_review_at' => ['nullable', 'date'],
        ]);

        $plan = ClientSupportPlan::updateOrCreate(
            ['client_id' => $client->id],
            array_merge($data, ['updated_by_user_id' => $request->user()?->id])
        );

        app(NotificationService::class)->notifyCrud($request->user(), 'updated', 'support plan', $plan, $client, [
            'title' => "Support plan updated: {$client->first_name} {$client->last_name}",
            'url' => url("/clients/{$client->id}/timeline"),
        ]);

        return back()->with('success', 'Support plan updated.');
    }
}
