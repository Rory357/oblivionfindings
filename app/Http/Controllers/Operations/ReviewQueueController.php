<?php

namespace App\Http\Controllers\Operations;

use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\ClientNote;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Inertia\Inertia;

/**
 * Phase 3 cross-client manager review dashboard: surfaces every unreviewed
 * flagged daily note across all clients the current user can access.
 * Permission-gated to managers via `progress_notes.review`.
 */
class ReviewQueueController extends Controller
{
    public function index(Request $request)
    {
        abort_unless(
            $request->user()?->canDo('progress_notes.review'),
            403,
        );

        $siteFilter = $request->integer('site');
        $ageFilter = $request->string('age', '')->toString(); // '24h', '7d', '30d'

        $accessibleClientIds = Client::query()
            ->when(
                ! $request->user()?->canDo('clients.viewAny'),
                fn ($q) => $q->whereHas(
                    'supportWorkers',
                    fn ($s) => $s->where('users.id', $request->user()?->id),
                ),
            )
            ->pluck('id');

        $query = ClientNote::query()
            ->where('is_flagged', true)
            ->whereNull('reviewed_at')
            ->whereIn('client_id', $accessibleClientIds)
            ->with([
                'client:id,first_name,last_name,site_id',
                'client.site:id,name',
                'author:id,name',
            ])
            ->orderByDesc('created_at');

        if ($siteFilter) {
            $query->whereHas(
                'client',
                fn ($q) => $q->where('site_id', $siteFilter),
            );
        }

        if ($ageFilter === '24h') {
            $query->where('created_at', '>=', Carbon::now()->subDay());
        } elseif ($ageFilter === '7d') {
            $query->where('created_at', '>=', Carbon::now()->subDays(7));
        } elseif ($ageFilter === '30d') {
            $query->where('created_at', '>=', Carbon::now()->subDays(30));
        }

        $items = $query->limit(200)->get()->map(function (ClientNote $note) {
            $created = $note->created_at;
            $hoursOpen = $created ? (int) $created->diffInHours(now()) : 0;

            return [
                'id' => $note->id,
                'client_id' => $note->client_id,
                'client_name' => trim(
                    ($note->client?->first_name ?? '').' '
                    .($note->client?->last_name ?? ''),
                ),
                'site_name' => $note->client?->site?->name,
                'site_id' => $note->client?->site_id,
                'subject' => $note->subject,
                'body' => $note->body,
                'category' => $note->category,
                'flagged_reason' => $note->flagged_reason,
                'mood_rating' => $note->mood_rating,
                'created_at' => $created?->toISOString(),
                'hours_open' => $hoursOpen,
                'age_severity' => $hoursOpen >= 48
                    ? 'critical'
                    : ($hoursOpen >= 24 ? 'warning' : 'info'),
                'author' => $note->author
                    ? ['id' => $note->author->id, 'name' => $note->author->name]
                    : null,
                'deep_link' => "/operations/clients/{$note->client_id}?tab=progress_notes&flagged=1&reviewed=0",
            ];
        });

        $sites = Client::query()
            ->whereIn('id', $accessibleClientIds)
            ->with('site:id,name')
            ->get()
            ->pluck('site')
            ->filter()
            ->unique('id')
            ->values()
            ->map(fn ($site) => ['id' => $site->id, 'name' => $site->name])
            ->all();

        $stats = [
            'total' => $items->count(),
            'critical' => $items->where('age_severity', 'critical')->count(),
            'warning' => $items->where('age_severity', 'warning')->count(),
            'sites' => $items->pluck('site_id')->filter()->unique()->count(),
            'clients' => $items->pluck('client_id')->unique()->count(),
        ];

        return Inertia::render('operations/review-queue/index', [
            'items' => $items->values(),
            'sites' => $sites,
            'stats' => $stats,
            'filters' => [
                'site' => $siteFilter,
                'age' => $ageFilter,
            ],
        ]);
    }
}
