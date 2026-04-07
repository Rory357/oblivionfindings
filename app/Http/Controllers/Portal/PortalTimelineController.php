<?php

namespace App\Http\Controllers\Portal;

use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\FamilyPortalSetting;
use App\Models\TimelineEvent;
use App\Services\ShiftTimelineService;
use Illuminate\Http\Request;
use Illuminate\Database\Eloquent\Builder;

class PortalTimelineController extends Controller
{
    public function index(Request $request, Client $client)
    {
        $user = $request->user();
        abort_unless($user, 403);
        abort_unless($user->canAccessClientPortal($client), 403);
        $showShiftSchedule = FamilyPortalSetting::query()
            ->where('client_id', $client->id)
            ->value('show_shift_schedule');
        $showShiftSchedule = $showShiftSchedule === null ? true : (bool) $showShiftSchedule;

        $query = TimelineEvent::where('client_id', $client->id)
            ->where('visibility', 'portal')
            ->with([
                'actor:id,name',
                'comments' => fn ($q) => $q->whereNull('parent_id')->with(['user:id,name,role', 'replies' => fn ($r) => $r->with('user:id,name,role')->orderBy('created_at'), 'replies.likes', 'likes'])->orderBy('created_at'),
                'reactions',
            ]);

        if (! $showShiftSchedule) {
            $query->whereNotIn('type', ShiftTimelineService::shiftEventTypes());
        }

        if ($request->filled('type') && $request->type !== 'all') {
            $this->applyTypeFilter($query, (string) $request->type);
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
                'meta' => $event->meta ?? [],
                'comments' => $event->comments->map(fn ($c) => [
                    'id' => $c->id,
                    'body' => $c->body,
                    'user_id' => $c->user_id,
                    'user_name' => $c->user?->name,
                    'is_staff' => !in_array($c->user?->role, ['client', 'next_of_kin'], true),
                    'likes_count' => $c->likes->count(),
                    'liked_by_user_ids' => $c->likes->pluck('user_id')->all(),
                    'created_at' => $c->created_at?->toISOString(),
                    'replies' => $c->replies->map(fn ($r) => [
                        'id' => $r->id,
                        'body' => $r->body,
                        'user_id' => $r->user_id,
                        'user_name' => $r->user?->name,
                        'is_staff' => !in_array($r->user?->role, ['client', 'next_of_kin'], true),
                        'likes_count' => $r->likes->count(),
                        'liked_by_user_ids' => $r->likes->pluck('user_id')->all(),
                        'created_at' => $r->created_at?->toISOString(),
                    ]),
                ]),
                'reactions' => $event->reactions
                    ->groupBy('emoji')
                    ->map(fn ($group, $emoji) => [
                        'emoji' => $emoji,
                        'count' => $group->count(),
                        'user_ids' => $group->pluck('user_id')->all(),
                    ])
                    ->values()
                    ->all(),
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
            'showShiftSchedule' => $showShiftSchedule,
        ]);
    }

    protected function applyTypeFilter(Builder $query, string $filter): void
    {
        $groups = [
            'shifts' => ShiftTimelineService::shiftEventTypes(),
            'care' => [
                'note',
                'shift_note',
                'progress_note',
                'handover',
                'medication_given',
                'medication_refused',
                'medication_missed',
                'medication_prescribed',
                'medication_correction',
            ],
            'visits' => [
                'visit_requested',
                'visit_approved',
                'visit_cancelled',
            ],
        ];

        if ($filter === 'other') {
            $query->whereNotIn('type', array_merge(...array_values($groups)));

            return;
        }

        if (array_key_exists($filter, $groups)) {
            $query->whereIn('type', $groups[$filter]);
        }
    }
}
