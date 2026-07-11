<?php

namespace App\Http\Controllers;

use App\Models\Client;
use App\Models\TimelineEvent;
use App\Models\User;
use App\Services\Clients\ClientProfileSectionAccess;
use Carbon\Carbon;
use Illuminate\Http\Request;

class TimelineController extends Controller
{
    public function __construct(
        protected ClientProfileSectionAccess $sectionAccess,
    ) {}

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
        abort_if($viewer->hasRole('client', 'next_of_kin'), 403);

        if ($viewer->id !== $user->id) {
            abort_unless($viewer->canDo('timeline.viewAny') || $viewer->canDo('staff.viewAny'), 403);
            abort_unless($this->sharesOrganization($viewer, $user), 403);
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
            'events' => $events->map(fn ($e) => $this->toEventDto($e))->values(),
        ]);
    }

    public function client(Request $request, Client $client)
    {
        $viewer = $request->user();
        abort_unless($viewer, 403);

        // Existing client policy is used elsewhere; keep it consistent.
        $this->authorize('view', $client);
        abort_unless($this->sectionAccess->canViewTimeline($viewer, $client), 403);

        $range = $this->parseRange($request);

        $query = TimelineEvent::query()
            ->where('client_id', $client->id)
            ->whereBetween('occurred_at', [$range['from'], $range['to']])
            ->with([
                'client:id,first_name,last_name,site_id',
                'site:id,name',
                'actor:id,name,email',
                'comments' => fn ($q) => $q->whereNull('parent_id')->with(['user:id,name,role', 'replies' => fn ($r) => $r->with('user:id,name,role')->orderBy('created_at'), 'replies.likes', 'likes'])->orderBy('created_at'),
                'reactions',
            ]);

        if ($request->filled('type') && $request->type !== 'all') {
            $query->where('type', $request->type);
        }

        $events = $query->orderByDesc('occurred_at')->limit(400)->get();

        $client->load(['site:id,name', 'serviceContext:id,name,type']);

        return inertia('timeline/index', [
            'scope' => ['type' => 'client', 'id' => $client->id, 'name' => trim($client->first_name.' '.$client->last_name)],
            'client' => [
                'id' => $client->id,
                'first_name' => $client->first_name,
                'last_name' => $client->last_name,
                'preferred_name' => $client->preferred_name,
                'nhi_number' => $client->nhi_number,
                'status' => $client->status,
                'avatar' => $client->avatar,
                'profile_photo_url' => $client->profile_photo_url,
                'funding_type' => $client->funding_type,
                'site' => $client->site ? ['name' => $client->site->name] : null,
                'service_context' => $client->serviceContext ? ['name' => $client->serviceContext->name] : null,
            ],
            'range' => [
                'from' => $range['from']->toISOString(),
                'to' => $range['to']->toISOString(),
            ],
            'events' => $events->map(fn ($e) => $this->toEventDto($e))->values(),
            'filters' => [
                'type' => $request->type ?? 'all',
                'from' => $request->query('from'),
                'to' => $request->query('to'),
            ],
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
            'source_id' => $e->source_id,
            'source_type' => $e->source_type,
            'type' => $e->type,
            'occurred_at' => optional($e->occurred_at)->toISOString(),
            'subject' => $e->subject,
            'body' => $e->body,
            'visibility' => $e->visibility,
            'is_pinned' => (bool) $e->is_pinned,
            'shift_id' => $e->shift_id,
            'meta' => $e->meta ?? [],
            'actor' => $e->actor ? ['id' => $e->actor->id, 'name' => $e->actor->name] : null,
            'client' => $e->client ? ['id' => $e->client->id, 'first_name' => $e->client->first_name, 'last_name' => $e->client->last_name] : null,
            'site' => $e->site ? ['id' => $e->site->id, 'name' => $e->site->name] : null,
            'comments' => $e->relationLoaded('comments')
                ? $e->comments->map(fn ($c) => [
                    'id' => $c->id,
                    'body' => $c->body,
                    'user_id' => $c->user_id,
                    'user_name' => $c->user?->name,
                    'is_staff' => ! in_array($c->user?->role, ['client', 'next_of_kin'], true),
                    'likes_count' => $c->likes->count(),
                    'liked_by_user_ids' => $c->likes->pluck('user_id')->all(),
                    'created_at' => $c->created_at?->toISOString(),
                    'replies' => $c->replies->map(fn ($r) => [
                        'id' => $r->id,
                        'body' => $r->body,
                        'user_id' => $r->user_id,
                        'user_name' => $r->user?->name,
                        'is_staff' => ! in_array($r->user?->role, ['client', 'next_of_kin'], true),
                        'likes_count' => $r->likes->count(),
                        'liked_by_user_ids' => $r->likes->pluck('user_id')->all(),
                        'created_at' => $r->created_at?->toISOString(),
                    ]),
                ])
                : [],
            'reactions' => $e->relationLoaded('reactions')
                ? $e->reactions
                    ->groupBy('emoji')
                    ->map(fn ($group, $emoji) => [
                        'emoji' => $emoji,
                        'count' => $group->count(),
                        'user_ids' => $group->pluck('user_id')->all(),
                    ])
                    ->values()
                    ->all()
                : [],
        ];
    }

    private function sharesOrganization(User $viewer, User $target): bool
    {
        return $viewer->organization_id === null
            || $target->organization_id === null
            || (int) $viewer->organization_id === (int) $target->organization_id;
    }
}
