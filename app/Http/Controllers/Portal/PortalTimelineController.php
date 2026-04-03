<?php

namespace App\Http\Controllers\Portal;

use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\TimelineEvent;
use Illuminate\Http\Request;

class PortalTimelineController extends Controller
{
    public function index(Request $request, Client $client)
    {
        $user = $request->user();
        abort_unless($user, 403);
        abort_unless($user->canAccessClientPortal($client), 403);

        $query = TimelineEvent::where('client_id', $client->id)
            ->where('visibility', 'portal')
            ->with('actor:id,name');

        if ($request->filled('type') && in_array($request->type, ['care', 'visits', 'other'], true)) {
            $query->where('type', $request->type);
        }

        $events = $query->orderByDesc('occurred_at')
            ->paginate(20)
            ->through(fn ($event) => [
                'id' => $event->id,
                'type' => $event->type,
                'subject' => $event->subject,
                'body' => $event->body,
                'occurred_at' => $event->occurred_at?->toISOString(),
                'actor_name' => $event->actor?->name,
            ]);

        return inertia('portal/timeline', [
            'client' => [
                'id' => $client->id,
                'first_name' => $client->first_name,
                'last_name' => $client->last_name,
                'avatar' => $client->avatar,
                'profile_photo_url' => $client->profile_photo_url,
            ],
            'events' => $events,
            'filter' => $request->type,
        ]);
    }
}
