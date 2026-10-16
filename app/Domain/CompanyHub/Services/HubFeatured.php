<?php

namespace App\Domain\CompanyHub\Services;

use App\Domain\CompanyHub\Models\HubPage;
use App\Domain\Hr\Services\HrCalendarAccessService;
use App\Models\User;
use Illuminate\Support\Collection;

/** Featured blocks resolve current published stories, never retained source copies. */
class HubFeatured
{
    public function stories(User $viewer, ?Collection $records = null, ?array $availableTeamIds = null): array
    {
        $access = app(HubAccess::class);
        $access->assertEnter($viewer);
        $availableTeamIds ??= app(HrCalendarAccessService::class)->visibleDepartmentsQuery($viewer)->pluck('id')->all();
        $records ??= HubPage::with(['currentRelease', 'owner:id,name'])->where('kind', 'news')->get();

        return self::ordered($records->where('kind', 'news')->map(function (HubPage $page) use ($viewer, $access, $availableTeamIds) {
            $revision = $access->visibleRevision($viewer, $page);
            if (! $revision) {
                return null;
            }
            $content = $revision->content;

            return [
                'id' => (int) $page->id, 'slug' => $page->slug, 'kind' => 'news',
                'title' => $content['title'], 'summary' => $content['summary'] ?? '', 'category' => $content['category'],
                'cover_url' => empty($content['cover_id']) ? null : '/company-hub/media/'.$content['cover_id'],
                'featured' => $content['featured'], 'published_at' => $revision->publish_at->toIso8601String(),
                'revision_id' => (int) $revision->id, 'review_on' => $content['review_on'] ?? null,
                'topics' => array_values(array_intersect($content['topics'] ?? [], HubDiscovery::TOPICS)),
                'team_ids' => array_values(array_intersect($content['team_ids'] ?? [], $availableTeamIds)),
                'owner' => $page->owner?->name ?? 'Company team',
                'collection' => $content['collection'] ?? null, 'resource_type' => $content['resource_type'] ?? null,
                'show_in_navigation' => $content['show_in_navigation'], 'navigation_order' => $content['navigation_order'],
                'show_in_news' => (bool) ($content['show_in_news'] ?? true),
            ];
        })->filter()->values()->all());
    }

    /** Input is a server-authorised summary pool, never client-supplied records. */
    public static function ordered(array $stories): array
    {
        return collect($stories)->where('kind', 'news')->filter(fn ($story) => $story['show_in_news'] ?? true)->sort(function ($left, $right) {
            return strcmp($right['published_at'] ?? '', $left['published_at'] ?? '')
                ?: ((int) $right['id'] <=> (int) $left['id']);
        })->values()->all();
    }

    public static function resolve(array $block, array $stories, ?array $sourceTeamIds = null): array
    {
        $pool = collect(self::ordered($stories))->filter(fn ($story) => $sourceTeamIds === null
            || array_intersect($story['team_ids'] ?? [], $sourceTeamIds) !== [])->values();
        $selected = empty($block['story_id']) ? null : $pool->firstWhere('id', (int) $block['story_id']);
        $lead = $selected ?? $pool->first();
        if (! $lead) {
            return ['lead' => null, 'supporting' => []];
        }
        $supporting = ($block['layout'] ?? 'mosaic') === 'single' ? []
            : $pool->reject(fn ($story) => (int) $story['id'] === (int) $lead['id'])->take(2)->values()->all();
        if ($selected) {
            $lead['title'] = trim($block['title'] ?? '') !== '' ? $block['title'] : $lead['title'];
            $lead['summary'] = trim($block['body'] ?? '') !== '' ? $block['body'] : $lead['summary'];
            $lead['cover_url'] = empty($block['media_id']) ? $lead['cover_url'] : '/company-hub/media/'.$block['media_id'];
        }

        return ['lead' => $lead, 'supporting' => $supporting];
    }

    /** Sanitize a reader copy before payload, search and direct media checks. */
    public static function readerContent(array $content, array $stories, ?array $sourceTeamIds = null): array
    {
        foreach ($content['sections'] ?? [] as $sectionIndex => $section) {
            foreach ($section['blocks'] ?? [] as $blockIndex => $block) {
                if ($block['type'] !== 'featured') {
                    continue;
                }
                $lead = self::resolve($block, $stories, $sourceTeamIds)['lead'];
                $explicit = ! empty($block['story_id']) && $lead && (int) $block['story_id'] === (int) $lead['id'];
                $block['story_id'] = $lead['id'] ?? null;
                $block['title'] = $lead['title'] ?? '';
                $block['body'] = $lead['summary'] ?? '';
                $block['media_id'] = $explicit ? ($block['media_id'] ?? null) : null;
                $block['alt'] = $explicit ? ($block['alt'] ?? '') : '';
                $content['sections'][$sectionIndex]['blocks'][$blockIndex] = $block;
            }
        }

        return $content;
    }
}
