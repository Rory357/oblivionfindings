<?php

namespace App\Http\Controllers\Operations;

use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\ClientPathPlan;
use Illuminate\Http\Request;

class ClientPathPlanController extends Controller
{
    public function upsert(Request $request, Client $client)
    {
        $this->authorize('update', $client);

        $data = $request->validate([
            'dream' => ['nullable', 'string'],
            'north_star' => ['nullable', 'string'],
            'strengths' => ['nullable', 'array'],
            'strengths.*' => ['string', 'max:255'],
            'action_steps' => ['nullable', 'array'],
            'action_steps.*' => ['string', 'max:500'],
            'trusted_people' => ['nullable', 'array'],
            'trusted_people.*' => ['string', 'max:255'],
            'independence_goals' => ['nullable', 'array'],
            'independence_goals.*' => ['string', 'max:500'],
            'community' => ['nullable', 'string'],
            'meaningful_outcomes' => ['nullable', 'string'],
            'plan_date' => ['nullable', 'date'],
            'next_review_at' => ['nullable', 'date'],
        ]);

        // Person-centred narrative lives on the client record itself, but is
        // edited from the same Goals Path "Person-centred planning" wizard.
        $narrative = $request->validate([
            'life_story' => ['nullable', 'string'],
            'strengths_abilities' => ['nullable', 'string'],
            'interests_hobbies' => ['nullable', 'string'],
        ]);
        if ($request->hasAny(['life_story', 'strengths_abilities', 'interests_hobbies'])) {
            $client->update($narrative);
        }

        $plan = ClientPathPlan::updateOrCreate(
            ['client_id' => $client->id],
            array_merge($data, [
                'organization_id' => $client->organization_id,
                'updated_by' => $request->user()?->id,
                'facilitator_id' => $request->user()?->id,
            ]),
        );

        return back()->with('success', "PATH plan saved (#{$plan->id}).");
    }

    public function destroy(Request $request, Client $client, ClientPathPlan $plan)
    {
        abort_unless($plan->client_id === $client->id, 404);
        $this->authorize('update', $client);

        $plan->delete();

        return back()->with('success', "PATH plan removed.");
    }
}
