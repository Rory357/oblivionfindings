<?php

namespace App\Http\Controllers\Operations;

use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\ClientNote;
use App\Services\UserSiteAccessService;
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
    public function __construct(
        private readonly UserSiteAccessService $siteAccess,
    ) {}

    public function index(Request $request)
    {
        abort_unless(
            $request->user()?->canDo('progress_notes.review'),
            403,
        );

        $siteFilter = $request->integer('site');
        $ageFilter = $request->string('age', '')->toString(); // '24h', '7d', '30d'
        $user = $request->user();
        $accessibleSiteIds = $this->siteAccess->accessibleSiteIds(
            $user,
            ['clients.viewAny'],
        );

        if ($siteFilter) {
            abort_unless(in_array($siteFilter, $accessibleSiteIds, true), 403);
        }

        $accessibleClients = Client::query();
        $this->siteAccess->applyClientScope(
            $accessibleClients,
            $user,
            ['clients.viewAny'],
        );
        $accessibleClientIds = $accessibleClients
            ->when(
                ! $user?->canDo('clients.viewAny'),
                fn ($q) => $q->whereHas(
                    'supportWorkers',
                    fn ($s) => $s->where('users.id', $user?->id),
                ),
            )
            ->pluck('id');

        $baseQuery = ClientNote::query()
            ->forUser($user)
            ->dailyNotes()
            ->where('is_draft', false)
            ->where('is_flagged', true)
            ->whereNull('reviewed_at')
            ->whereIn('client_id', $accessibleClientIds);

        if ($siteFilter) {
            $baseQuery->whereHas(
                'client',
                fn ($q) => $q->where('site_id', $siteFilter),
            );
        }

        if ($ageFilter === '24h') {
            $baseQuery->where('created_at', '>=', Carbon::now()->subDay());
        } elseif ($ageFilter === '7d') {
            $baseQuery->where('created_at', '>=', Carbon::now()->subDays(7));
        } elseif ($ageFilter === '30d') {
            $baseQuery->where('created_at', '>=', Carbon::now()->subDays(30));
        }

        // Stats are computed across the full filtered set, not the current
        // page, so the page hero counters stay accurate as the user pages.
        $now = Carbon::now();
        $total = (clone $baseQuery)->count();
        $critical = (clone $baseQuery)
            ->where('created_at', '<=', $now->copy()->subHours(48))
            ->count();
        $warning = (clone $baseQuery)
            ->where('created_at', '>', $now->copy()->subHours(48))
            ->where('created_at', '<=', $now->copy()->subHours(24))
            ->count();
        $queueClientIds = (clone $baseQuery)->distinct()->pluck('client_id');
        $clientsCount = $queueClientIds->count();
        $sitesCount = Client::query()
            ->whereIn('id', $queueClientIds)
            ->whereNotNull('site_id')
            ->distinct('site_id')
            ->count('site_id');

        $items = $baseQuery
            ->with([
                'client:id,first_name,last_name,site_id',
                'client.site:id,name',
                'author:id,name',
            ])
            ->orderByDesc('created_at')
            ->paginate(50)
            ->withQueryString()
            ->through(function (ClientNote $note) {
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

        return Inertia::render('operations/review-queue/index', [
            'items' => $items,
            'sites' => $sites,
            'stats' => [
                'total' => $total,
                'critical' => $critical,
                'warning' => $warning,
                'sites' => $sitesCount,
                'clients' => $clientsCount,
            ],
            'filters' => [
                'site' => $siteFilter,
                'age' => $ageFilter,
            ],
        ]);
    }
}
