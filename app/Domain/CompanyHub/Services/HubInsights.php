<?php

namespace App\Domain\CompanyHub\Services;

use App\Domain\CompanyHub\Models\HubPage;
use App\Domain\Hr\Models\HrCalendarEvent;
use App\Domain\Hr\Services\HrCalendarAggregator;
use App\Domain\Hr\Services\HrEventRegistrationService;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

class HubInsights
{
    public function forUser(User $user, int $days): array
    {
        $polls = app(HubPolls::class);
        $user = $polls->actor($user);
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
            ->map(fn ($event) => ['id' => $event['id'], 'title' => $event['title'], 'start' => $event['start'], 'url' => $this->eventUrl($user, $event), ...collect($event['extendedProps']['registration'])->only(['going', 'waiting', 'capacity'])->all()])->values()->all();

        return [
            'days' => $days, 'since' => $since->toIso8601String(), 'until' => $until->toIso8601String(),
            'readers' => (clone $reads)->distinct()->count('user_id'), 'pages' => $rows,
            'health' => [
                'reports' => $pages->flatMap(fn ($p) => $p['reports'])->filter(fn ($r) => ! ($r['resolved'] ?? false))->count(),
                'overdue' => $pages->filter(fn ($p) => $p['status'] !== 'archived' && $p['review_on'] && $p['review_on'] < now(config('app.worker_timezone', 'Pacific/Auckland'))->toDateString())->count(),
                'review' => $pages->where('status', 'review')->count(),
            ],
            'events' => $events,
            'ideas' => $this->ideas($since, $until),
            'polls' => HubPage::whereIn('id', $ids)->get()->flatMap(function (HubPage $page) use ($user, $polls, $since, $until) {
                $revision = $polls->visibleRevision($user, $page);
                if (! $revision) {
                    return [];
                }

                return HubContent::visibleBlocks($revision->content)->where('type', 'poll')->map(function (array $block) use ($page, $revision, $user, $polls, $since, $until): array {
                    $projection = $polls->project($user, $page, $revision, $block);

                    return ['page_id' => (int) $page->id, 'block_id' => $block['id'], 'key' => $projection['key'], 'question' => $block['question'],
                        'url' => ($page->kind === 'home' ? '/company-hub' : '/company-hub/read/'.rawurlencode($page->slug)).'#block-'.rawurlencode($block['id']),
                        'closed' => $projection['closed'], 'results_visible' => $projection['results_visible'], 'response_count' => $projection['total'],
                        'responses_in_period' => $projection['results_visible'] ? $polls->votes($page, $block)->filter(fn ($vote) => $vote['created_at'] && CarbonImmutable::parse($vote['created_at'])->betweenIncluded($since, $until))->count() : null];
                });
            })->values()->all(),
            'searches' => app(HubSearchInsights::class)->summary($user, $days),
        ];
    }

    private function eventUrl(User $user, array $row): ?string
    {
        $eventId = $row['extendedProps']['eventId'] ?? null;
        $date = $row['extendedProps']['registration']['date'] ?? null;
        if (! is_int($eventId) || $eventId < 1 || ! is_string($date) || ! preg_match('/^\d{4}-\d{2}-\d{2}$/D', $date)) {
            return null;
        }
        $event = HrCalendarEvent::find((int) $eventId);
        if (! $event) {
            return null;
        }
        try {
            // An editable calendar row is not proof that its current occurrence is readable.
            $current = app(HrEventRegistrationService::class)->occurrence($user, $event, $date);
        } catch (HttpExceptionInterface $exception) {
            if (in_array($exception->getStatusCode(), [403, 404], true)) {
                return null;
            }
            throw $exception;
        }

        return '/company-hub/events/'.$current['extendedProps']['eventId'].'?date='.rawurlencode($current['extendedProps']['registration']['date']);
    }

    private function ideas($since, $until): array
    {
        // Ideas already have a public staff audience; routing never grants extra identity data.
        $ideas = DB::table('hub_ideas')->whereNull('hidden_at')->orderByDesc('updated_at')->limit(200)->get(['id', 'title', 'status']);
        $supports = DB::table('hub_idea_supports')->whereIn('hub_idea_id', $ideas->pluck('id'))->groupBy('hub_idea_id')
            ->selectRaw('hub_idea_id, count(*) as total, sum(case when created_at between ? and ? then 1 else 0 end) as in_period', [$since, $until])->get()->keyBy('hub_idea_id');

        return $ideas->map(fn ($idea) => ['id' => (int) $idea->id, 'title' => $idea->title, 'status' => $idea->status, 'url' => '/company-hub?view=ideas&idea='.$idea->id,
            'support_count' => (int) ($supports[$idea->id]->total ?? 0), 'supports_in_period' => (int) ($supports[$idea->id]->in_period ?? 0)])->all();
    }
}
