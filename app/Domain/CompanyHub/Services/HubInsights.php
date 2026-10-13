<?php

namespace App\Domain\CompanyHub\Services;

use App\Domain\Hr\Services\HrCalendarAggregator;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class HubInsights
{
    public function forUser(User $user, int $days): array
    {
        $pages = collect(app(HubEditorial::class)->workspace($user));
        $ids = $pages->pluck('id');
        $until = now();
        $since = $until->copy()->subDays($days);
        $reads = DB::table('hub_reads')->whereIn('hub_page_id', $ids)->whereBetween('last_read_at', [$since, $until]);
        $readCounts = (clone $reads)->selectRaw('hub_page_id, count(*) as total')->groupBy('hub_page_id')->pluck('total', 'hub_page_id');
        $interactions = DB::table('hub_interactions')->whereIn('hub_page_id', $ids)->whereNull('hidden_at')->whereBetween('created_at', [$since, $until])->get(['hub_page_id', 'kind']);
        $saves = DB::table('hub_bookmarks')->where('subject_type', 'page')->whereIn('subject_id', $ids)->whereBetween('created_at', [$since, $until])->selectRaw('subject_id, count(*) as total')->groupBy('subject_id')->pluck('total', 'subject_id');
        $rows = $pages->map(fn ($page) => [
            'id' => $page['id'], 'title' => $page['title'], 'readers' => (int) ($readCounts[$page['id']] ?? 0), 'saves' => (int) ($saves[$page['id']] ?? 0),
            ...collect(['comment' => 'comments', 'reaction' => 'reactions', 'helpful' => 'helpful'])->mapWithKeys(fn ($label, $kind) => [$label => $interactions->where('hub_page_id', $page['id'])->where('kind', $kind)->count()])->all(),
        ])->sortByDesc('readers')->values()->all();
        $events = collect(app(HrCalendarAggregator::class)->feed(now()->toDateString(), now()->addDays(90)->toDateString(), ['event'], [], $user))
            ->filter(fn ($event) => $event['editable'] && ($event['extendedProps']['registration']['enabled'] ?? false))
            ->map(fn ($event) => ['id' => $event['id'], 'title' => $event['title'], 'start' => $event['start'], ...collect($event['extendedProps']['registration'])->only(['going', 'waiting', 'capacity'])->all()])->values()->all();

        return [
            'days' => $days, 'since' => $since->toIso8601String(), 'until' => $until->toIso8601String(),
            'readers' => (clone $reads)->distinct()->count('user_id'), 'pages' => $rows,
            'health' => [
                'reports' => $pages->flatMap(fn ($p) => $p['reports'])->filter(fn ($r) => ! ($r['resolved'] ?? false))->count(),
                'overdue' => $pages->filter(fn ($p) => $p['status'] !== 'archived' && $p['review_on'] && $p['review_on'] < now(config('app.worker_timezone', 'Pacific/Auckland'))->toDateString())->count(),
                'review' => $pages->where('status', 'review')->count(),
            ],
            'events' => $events,
            'searches' => app(HubSearchInsights::class)->summary($user, $days),
        ];
    }
}
