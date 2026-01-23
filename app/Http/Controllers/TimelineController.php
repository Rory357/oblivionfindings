<?php

namespace App\Http\Controllers;

use App\Models\Client;
use App\Models\TimelineEvent;
use App\Models\User;
use Illuminate\Http\Request;
use Carbon\Carbon;

class TimelineController extends Controller
{
    public function my(Request $request)
    {
        $user = $request->user();
        abort_unless($user, 403);

        return $this->staff($request, $user);
    }

    public function staff(Request $request, User $user)
    {
        $viewer = $request->user();
        abort_unless($viewer, 403);

        if ($viewer->id !== $user->id) {
            abort_unless($viewer->canDo('timeline.viewAny') || $viewer->canDo('staff.viewAny'), 403);
        }

        $range = $this->parseRange($request);

        $events = TimelineEvent::query()
            ->where('actor_user_id', $user->id)
            ->whereBetween('occurred_at', [$range['from'], $range['to']])
            ->orderBy('occurred_at')
            ->with([
                'client:id,first_name,last_name,site_id',
                'site:id,name',
                'actor:id,name,email',
            ])
            ->limit(400)
            ->get();

        return inertia('timeline/index', [
            'scope' => ['type' => 'staff', 'id' => $user->id, 'name' => $user->name],
            'range' => [
                'from' => $range['from']->toISOString(),
                'to' => $range['to']->toISOString(),
            ],
            'events' => $events->map(fn($e) => $this->toEventDto($e))->values(),
        ]);
    }

    public function client(Request $request, Client $client)
    {
        $viewer = $request->user();
        abort_unless($viewer, 403);

        // Existing client policy is used elsewhere; keep it consistent.
        $this->authorize('view', $client);

        $range = $this->parseRange($request);

        $events = TimelineEvent::query()
            ->where('client_id', $client->id)
            ->whereBetween('occurred_at', [$range['from'], $range['to']])
            ->orderBy('occurred_at')
            ->with([
                'client:id,first_name,last_name,site_id',
                'site:id,name',
                'actor:id,name,email',
            ])
            ->limit(400)
            ->get();

        return inertia('timeline/index', [
            'scope' => ['type' => 'client', 'id' => $client->id, 'name' => trim($client->first_name . ' ' . $client->last_name)],
            'range' => [
                'from' => $range['from']->toISOString(),
                'to' => $range['to']->toISOString(),
            ],
            'events' => $events->map(fn($e) => $this->toEventDto($e))->values(),
        ]);
    }

    private function parseRange(Request $request): array
    {
        $from = $request->query('from') ? Carbon::parse($request->query('from')) : now()->startOfDay();
        $to = $request->query('to') ? Carbon::parse($request->query('to')) : (clone $from)->addDays(7)->endOfDay();

        // Guardrails
        if ($to->lessThan($from)) {
            [$from, $to] = [$to, $from];
        }
        if ($to->diffInDays($from) > 60) {
            $to = (clone $from)->addDays(60);
        }

        return compact('from', 'to');
    }

    private function toEventDto(TimelineEvent $e): array
    {
        return [
            'id' => $e->id,
            'type' => $e->type,
            'occurred_at' => optional($e->occurred_at)->toISOString(),
            'subject' => $e->subject,
            'body' => $e->body,
            'visibility' => $e->visibility,
            'meta' => $e->meta ?? [],
            'actor' => $e->actor ? ['id' => $e->actor->id, 'name' => $e->actor->name] : null,
            'client' => $e->client ? ['id' => $e->client->id, 'first_name' => $e->client->first_name, 'last_name' => $e->client->last_name] : null,
            'site' => $e->site ? ['id' => $e->site->id, 'name' => $e->site->name] : null,
        ];
    }
}
