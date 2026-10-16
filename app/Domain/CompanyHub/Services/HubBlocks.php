<?php

namespace App\Domain\CompanyHub\Services;

use App\Models\User;

/** Dynamic blocks reuse current authorised records for this single organisation. */
class HubBlocks
{
    public function forUser(User $viewer, array $content, array $feed, ?array $people = null, bool $includeHidden = false, ?array $sourceTeamIds = null, ?array $publishedPages = null): array
    {
        app(HubAccess::class)->assertEnter($viewer);
        $visibleBlocks = HubContent::visibleBlocks($content);
        $blocks = $includeHidden
            ? collect($content['sections'] ?? [])->flatMap(fn ($section) => $section['blocks'] ?? [])
            : $visibleBlocks;
        $dynamic = $blocks->whereIn('type', ['featured', 'news', 'resources', 'people', 'services', 'teams', 'campaign']);
        $people ??= $dynamic->contains('type', 'people') ? app(HubDirectory::class)->people($viewer) : [];
        $services = $dynamic->contains('type', 'services') ? app(HubServices::class)->search($viewer, '') : [];
        $availableTeamIds = array_column($feed['interests']['available_teams'] ?? [], 'id');
        $pages = collect($publishedPages ?? $feed['pages'] ?? []);
        $spaces = $pages->where('kind', 'department')
            ->filter(fn ($page) => array_intersect($page['team_ids'] ?? [], $availableTeamIds) !== []);
        $source = $sourceTeamIds === null ? null : array_values(array_intersect($sourceTeamIds, $availableTeamIds));
        $stories = HubFeatured::ordered($pages->all());
        $features = $dynamic->where('type', 'featured')->mapWithKeys(fn ($block) => [$block['id'] => HubFeatured::resolve($block, $stories, $source)])->all();
        // Hidden editor sections/blocks have data, but do not displace visible news.
        $featuredIds = $visibleBlocks->where('type', 'featured')
            ->flatMap(fn ($block) => array_merge(empty($features[$block['id']]['lead']) ? [] : [$features[$block['id']]['lead']['id']], array_column($features[$block['id']]['supporting'], 'id')))->unique()->all();

        return $dynamic->mapWithKeys(function ($block) use ($people, $services, $spaces, $pages, $features, $featuredIds, $availableTeamIds, $sourceTeamIds) {
            $selected = $block['team_ids'] ?? [];
            $teamIds = array_values(array_intersect($selected, $availableTeamIds));
            $scoped = $selected !== [] || $sourceTeamIds !== null;
            if ($sourceTeamIds !== null) {
                $source = array_values(array_intersect($sourceTeamIds, $availableTeamIds));
                $teamIds = $selected !== [] ? array_values(array_intersect($teamIds, $source)) : $source;
            }
            $limit = max(1, min(12, (int) ($block['limit'] ?? ($block['type'] === 'campaign' ? 1 : 4))));
            $eligible = $pages->filter(fn ($page) => (! $scoped || array_intersect($page['team_ids'] ?? [], $teamIds) !== [])
                && (empty($block['category']) || $page['category'] === $block['category']));
            $news = collect(HubFeatured::ordered($eligible->all()));
            $unfeatured = $news->whereNotIn('id', $featuredIds);
            $data = match ($block['type']) {
                'featured' => ['featured' => $features[$block['id']]],
                'news' => ['news' => ($unfeatured->isEmpty() ? $news : $unfeatured)->take($limit)->values()->all()],
                'resources' => ['resources' => $eligible->whereIn('kind', ['page', 'knowledge'])->sortByDesc('published_at')->take($limit)->values()->all()],
                'campaign' => ['campaigns' => $eligible->where('kind', 'campaign')->sortByDesc('published_at')->take($limit)->values()->all()],
                // Finder filtering needs the full authorised pool, including
                // colleagues beyond the first row of directory cards.
                'people' => ['people' => collect($people)->filter(fn ($person) => ! $scoped || in_array($person['department_id'], $teamIds, true))->values()->all()],
                'teams' => ['teams' => $spaces->filter(fn ($page) => ! $scoped || array_intersect($page['team_ids'] ?? [], $teamIds) !== [])->take($limit)->values()->all()],
                'services' => ['services' => collect($services)
                    ->filter(fn ($service) => empty($block['category']) || $service['category'] === $block['category'])
                    ->take($limit)->map(fn ($service) => [
                        'id' => $service['id'], 'name' => $service['name'], 'description' => $service['description'], 'category' => $service['category'],
                        'icon' => $service['icon'],
                        'url' => $service['url'] ?? '/company-hub?view=services&search='.rawurlencode($service['name']),
                    ])->values()->all()],
            };

            return [$block['id'] => $data];
        })->all();
    }
}
