<?php

namespace App\Domain\CompanyHub\Services;

use App\Domain\CompanyHub\Models\HubMedia;
use App\Domain\CompanyHub\Models\HubPage;
use App\Domain\Hr\Services\HrCalendarAccessService;
use App\Models\User;
use App\Services\UserSiteAccessService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

class HubContent
{
    public const BLOCKS = ['featured', 'text', 'news', 'shoutouts', 'events', 'celebrations', 'links', 'resources', 'people', 'services', 'teams', 'campaign', 'poll', 'image', 'video', 'notices', 'notice', 'button', 'file'];

    public const RESOURCE_TYPES = ['Guide', 'Policy', 'Process', 'Checklist', 'Form', 'Information'];

    /** Reader copies never alter the complete authored draft/revision snapshot. */
    public static function readerContent(array $content, ?array $availableTeamIds = null): array
    {
        $content['sections'] = collect($content['sections'] ?? [])->filter(fn ($section) => empty($section['hidden']))->map(function ($section) use ($availableTeamIds) {
            $section['blocks'] = collect($section['blocks'] ?? [])->filter(fn ($block) => empty($block['hidden']))
                ->map(function ($block) use ($availableTeamIds) {
                    if ($availableTeamIds !== null && isset($block['team_ids'])) {
                        $block['team_ids'] = array_values(array_intersect($block['team_ids'], $availableTeamIds));
                    }

                    return $block;
                })->values()->all();

            return $section;
        })->filter(fn ($section) => $section['blocks'] !== [])->values()->all();
        if ($availableTeamIds !== null && isset($content['team_ids'])) {
            $content['team_ids'] = array_values(array_intersect($content['team_ids'], $availableTeamIds));
        }

        return $content;
    }

    public static function visibleBlocks(array $content): Collection
    {
        return collect(self::readerContent($content)['sections'])->flatMap(fn ($section) => $section['blocks'])->values();
    }

    public static function defaults(string $title = 'Company Hub'): array
    {
        return [
            'title' => $title, 'summary' => 'News, people and resources from across our company.', 'category' => 'Company news',
            'hero_label' => 'OUR COMPANY', 'cover_id' => null, 'featured' => false,
            'hero' => ['style' => 'brand', 'height' => 'compact', 'focal_x' => 50, 'focal_y' => 50, 'overlay' => 65, 'action_label' => '', 'action_url' => ''],
            'audience' => ['type' => 'all', 'ids' => []], 'comments_enabled' => true,
            'reactions_enabled' => true, 'requires_approval' => false,
            'show_in_navigation' => false, 'navigation_order' => 0, 'review_on' => null,
            'sections' => [
                ['id' => 'featured-stories', 'columns' => 1, 'blocks' => [
                    ['id' => 'featured-stories', 'type' => 'featured', 'title' => '', 'body' => '', 'story_id' => null, 'layout' => 'mosaic', 'tone' => 'plain', 'width' => 12],
                ]],
                ['id' => 'latest', 'columns' => 2, 'blocks' => [
                    ['id' => 'news', 'type' => 'news', 'title' => 'Company news', 'limit' => 4],
                    ['id' => 'links', 'type' => 'links', 'title' => 'Quick links', 'links' => [
                        ['id' => 'directory', 'title' => 'People directory', 'url' => '/company-hub?view=people', 'description' => 'Find the right person'],
                        ['id' => 'policies', 'title' => 'Policies & guides', 'url' => '/hr/my/policies', 'description' => 'The information you need'],
                        ['id' => 'learning', 'title' => 'Learning & development', 'url' => '/hr/my/training', 'description' => 'Your next step starts here'],
                        ['id' => 'surveys', 'title' => 'Have your say', 'url' => '/hr/my/surveys', 'description' => 'Share your ideas and feedback'],
                    ]],
                ]],
                ['id' => 'people', 'columns' => 2, 'blocks' => [
                    ['id' => 'shoutouts', 'type' => 'shoutouts', 'title' => 'Recognition', 'limit' => 2],
                    ['id' => 'celebrations', 'type' => 'celebrations', 'title' => 'People to celebrate', 'limit' => 6],
                ]],
                ['id' => 'coming-up', 'columns' => 1, 'blocks' => [
                    ['id' => 'events', 'type' => 'events', 'title' => 'Upcoming events', 'limit' => 3],
                ]],
            ],
        ];
    }

    public static function safeUrl(mixed $value): bool
    {
        if (! is_string($value) || preg_match('/[\x00-\x20\\\\]/', $value)) {
            return false;
        }

        return (str_starts_with($value, '/') && ! str_starts_with($value, '//'))
            || (filter_var($value, FILTER_VALIDATE_URL) && parse_url($value, PHP_URL_SCHEME) === 'https'
                && ! parse_url($value, PHP_URL_USER) && ! parse_url($value, PHP_URL_PASS));
    }

    public function validate(array $input, User $actor, ?HubPage $page = null): array
    {
        $url = function ($attribute, $value, $fail): void {
            if (! self::safeUrl($value)) {
                $fail('Use a secure https link or an internal application path.');
            }
        };
        $rules = [
            'title' => 'present|nullable|string|max:160', 'summary' => 'present|nullable|string|max:600',
            'category' => 'required|string|max:60', 'hero_label' => 'required|string|max:60',
            'topics' => 'sometimes|array|max:5', 'topics.*' => ['string', 'distinct', Rule::in(HubDiscovery::TOPICS)],
            'team_ids' => 'sometimes|array|max:100', 'team_ids.*' => 'integer|min:1|distinct',
            'collection' => 'sometimes|nullable|string|max:60',
            'collection_id' => 'sometimes|nullable|integer|min:1|max:2147483647',
            'resource_type' => ['sometimes', 'nullable', Rule::in(self::RESOURCE_TYPES)],
            'show_in_news' => 'sometimes|boolean',
            'checklist' => 'sometimes|array|list|max:12',
            'checklist.*' => 'array:id,title',
            'checklist.*.id' => 'required|string|max:60|regex:/^[a-zA-Z0-9_-]+$/|distinct',
            'checklist.*.title' => 'required|string|max:300',
            'cover_id' => 'nullable|integer', 'featured' => 'required|boolean',
            'hero' => 'sometimes|array:style,height,focal_x,focal_y,overlay,action_label,action_url,alignment,icon,show_search,show_summary,show_metrics',
            'hero.style' => ['required_with:hero', Rule::in(['brand', 'image'])],
            'hero.height' => ['required_with:hero', Rule::in(['compact', 'comfortable'])],
            'hero.focal_x' => 'required_with:hero|integer|min:0|max:100',
            'hero.focal_y' => 'required_with:hero|integer|min:0|max:100',
            'hero.overlay' => 'required_with:hero|integer|min:20|max:90',
            'hero.action_label' => 'nullable|string|max:60',
            'hero.action_url' => ['nullable', 'string', 'max:2000', $url],
            'hero.alignment' => ['sometimes', Rule::in(['left', 'center'])],
            'hero.icon' => ['sometimes', Rule::in(['heart-handshake', 'newspaper', 'sparkles', 'building-2', 'flag', 'door-open', 'users', 'heart', 'book-open'])],
            'hero.show_search' => 'sometimes|boolean',
            'hero.show_summary' => 'sometimes|boolean',
            'hero.show_metrics' => 'sometimes|boolean',
            'audience' => 'required|array:type,ids', 'audience.type' => ['required', Rule::in(['all', 'sites', 'departments'])],
            'audience.ids' => 'present|array|max:100', 'audience.ids.*' => 'integer|min:1|distinct',
            'comments_enabled' => 'required|boolean', 'reactions_enabled' => 'required|boolean',
            'requires_approval' => 'required|boolean', 'show_in_navigation' => 'required|boolean',
            'navigation_order' => 'required|integer|min:0|max:100', 'review_on' => 'nullable|date_format:Y-m-d',
            'planned_for' => 'nullable|date_format:Y-m-d',
            'campaign' => 'sometimes|nullable|array:ends_on,steps,milestones,story_ids,resource_ids,event_keys',
            'campaign.ends_on' => 'nullable|date_format:Y-m-d',
            'campaign.steps' => 'present_with:campaign|array|list|max:12',
            'campaign.steps.*' => 'array:id,title,url',
            'campaign.steps.*.id' => 'required|string|max:60|regex:/^[a-zA-Z0-9_-]+$/|distinct',
            'campaign.steps.*.title' => 'required|string|max:200',
            'campaign.steps.*.url' => ['nullable', 'string', 'max:2000', $url],
            'campaign.milestones' => 'sometimes|array|list|max:12',
            'campaign.milestones.*' => 'array:id,title,status',
            'campaign.milestones.*.id' => 'required|string|max:60|regex:/^[a-zA-Z0-9_-]+$/|distinct',
            'campaign.milestones.*.title' => 'required|string|max:200',
            'campaign.milestones.*.status' => 'required|in:done,active,next',
            'campaign.story_ids' => 'sometimes|array|list|max:12',
            'campaign.story_ids.*' => 'integer|min:1|distinct',
            'campaign.resource_ids' => 'sometimes|array|list|max:12',
            'campaign.resource_ids.*' => 'integer|min:1|distinct',
            'campaign.event_keys' => 'sometimes|array|list|max:12',
            'campaign.event_keys.*' => 'string|max:100|distinct',
            'sections' => 'present|array|max:12', 'sections.*' => 'array:id,title,columns,layout,tone,spacing,hidden,blocks',
            'sections.*.hidden' => 'sometimes|boolean',
            'sections.*.title' => 'nullable|string|max:100',
            'sections.*.layout' => ['sometimes', Rule::in(['equal', 'wide-left', 'wide-right'])],
            'sections.*.tone' => ['sometimes', Rule::in(['plain', 'soft', 'brand'])],
            'sections.*.spacing' => ['sometimes', Rule::in(['compact', 'comfortable', 'spacious'])],
            'sections.*.id' => 'required|string|max:60|regex:/^[a-zA-Z0-9_-]+$/|distinct',
            'sections.*.columns' => 'required|integer|min:1|max:3', 'sections.*.blocks' => 'present|array|max:12',
            'sections.*.blocks.*' => 'array:id,type,title,body,rich_text,limit,category,media_id,alt,url,links,question,options,closes_at,width,hidden,min_height,mode,team_ids,tone,layout,story_id,action_label',
            'sections.*.blocks.*.width' => 'sometimes|integer|min:1|max:12',
            'sections.*.blocks.*.hidden' => 'sometimes|boolean',
            'sections.*.blocks.*.min_height' => 'sometimes|integer|min:0|max:800',
            'sections.*.blocks.*.mode' => 'sometimes|in:cards,finder',
            'sections.*.blocks.*.tone' => 'sometimes|in:plain,soft',
            'sections.*.blocks.*.layout' => 'sometimes|in:mosaic,equal,single,cards,list',
            'sections.*.blocks.*.story_id' => 'sometimes|nullable|integer|min:1|max:2147483647',
            'sections.*.blocks.*.action_label' => 'sometimes|nullable|string|max:60',
            'sections.*.blocks.*.team_ids' => 'sometimes|array|list|max:100',
            'sections.*.blocks.*.team_ids.*' => 'integer|min:1|max:2147483647',
            'sections.*.blocks.*.id' => 'required|string|max:60|regex:/^[a-zA-Z0-9_-]+$/|distinct',
            'sections.*.blocks.*.type' => ['required', Rule::in(self::BLOCKS)],
            'sections.*.blocks.*.title' => 'present|nullable|string|max:160',
            'sections.*.blocks.*.body' => 'nullable|string|max:20000',
            'sections.*.blocks.*.rich_text' => ['sometimes', 'nullable', function ($attribute, $value, $fail): void {
                try {
                    HubRichText::normalise($value);
                } catch (InvalidArgumentException $exception) {
                    $fail($exception->getMessage());
                }
            }],
            'sections.*.blocks.*.limit' => 'nullable|integer|min:1|max:12',
            'sections.*.blocks.*.category' => 'nullable|string|max:60',
            'sections.*.blocks.*.media_id' => 'nullable|integer', 'sections.*.blocks.*.alt' => 'nullable|string|max:300',
            'sections.*.blocks.*.url' => ['nullable', 'string', 'max:2000', $url],
            'sections.*.blocks.*.links' => 'nullable|array|list|max:24',
            'sections.*.blocks.*.links.*' => 'array:id,title,url,description',
            'sections.*.blocks.*.links.*.id' => 'required|string|max:60',
            'sections.*.blocks.*.links.*.title' => 'present|nullable|string|max:100',
            'sections.*.blocks.*.links.*.description' => 'nullable|string|max:200',
            'sections.*.blocks.*.links.*.url' => ['present', 'nullable', 'string', 'max:2000', $url],
            'sections.*.blocks.*.question' => 'nullable|string|max:300',
            'sections.*.blocks.*.options' => 'nullable|array|list|max:8',
            'sections.*.blocks.*.options.*' => 'nullable|string|max:120',
            'sections.*.blocks.*.closes_at' => ['bail', 'nullable', 'string', function ($attribute, $value, $fail): void {
                try {
                    HubPolls::closingInstant($value);
                } catch (ValidationException) {
                    $fail('Choose a valid date or an exact closing time with its timezone.');
                }
            }],
        ];
        $content = Validator::make($input, $rules)->validate();
        $content['title'] ??= '';
        // Rich content is semantic JSON. Derive the plain fallback rather than
        // trusting a second independently supplied body for search or previews.
        foreach ($content['sections'] as $sectionIndex => &$section) {
            foreach ($section['blocks'] as $blockIndex => &$block) {
                if (! array_key_exists('rich_text', $block)) {
                    continue;
                }
                if ($block['type'] !== 'text') {
                    throw ValidationException::withMessages(['sections.'.$sectionIndex.'.blocks.'.$blockIndex.'.rich_text' => 'Formatted text belongs on a text block.']);
                }
                if ($block['rich_text'] !== null) {
                    try {
                        $block['rich_text'] = HubRichText::normalise($block['rich_text']);
                    } catch (InvalidArgumentException $exception) {
                        throw ValidationException::withMessages(['sections.'.$sectionIndex.'.blocks.'.$blockIndex.'.rich_text' => $exception->getMessage()]);
                    }
                    $block['body'] = HubRichText::plainText($block['rich_text']);
                }
            }
            unset($block);
        }
        unset($section);
        if (array_key_exists('show_in_news', $content)) {
            if ($page?->kind !== 'news') {
                throw ValidationException::withMessages(['show_in_news' => 'Company news visibility belongs on a news story.']);
            }
            $content['show_in_news'] = (bool) $content['show_in_news'];
        }
        if (! empty($content['checklist']) && ! in_array($page?->kind, ['page', 'knowledge'], true)) {
            throw ValidationException::withMessages(['checklist' => 'Personal checklists belong on a company resource page.']);
        }
        $teams = app(HrCalendarAccessService::class)->visibleDepartmentsQuery($actor)->pluck('id')->all();
        if (array_diff($content['team_ids'] ?? [], $teams)) {
            throw ValidationException::withMessages(['team_ids' => 'Choose related teams from your available departments.']);
        }
        if (array_key_exists('team_ids', $content)) {
            $content['team_ids'] = array_map('intval', array_values($content['team_ids']));
        }
        $ids = $content['audience']['ids'];
        $type = $content['audience']['type'];
        $allowed = match ($type) {
            'sites' => app(UserSiteAccessService::class)->accessibleSiteIds($actor),
            'departments' => app(HrCalendarAccessService::class)->visibleDepartmentsQuery($actor)->pluck('id')->all(),
            default => [],
        };
        if (($type !== 'all' && empty($ids)) || array_diff($ids, $allowed) !== []) {
            throw ValidationException::withMessages(['audience' => 'Choose an audience from your approved sites or departments.']);
        }
        if ($page?->kind === 'home' && $type !== 'all') {
            throw ValidationException::withMessages(['audience' => 'The company homepage must be available to all staff.']);
        }
        $blocks = collect($content['sections'])->flatMap(fn ($section) => $section['blocks']);
        $mediaIds = $blocks->pluck('media_id')->push($content['cover_id'] ?? null)->filter()->unique();
        if ($mediaIds->isNotEmpty() && (! $page || HubMedia::where('hub_page_id', $page->id)->whereIn('id', $mediaIds)->count() !== $mediaIds->count())) {
            throw ValidationException::withMessages(['cover_id' => 'Choose media uploaded to this page.']);
        }
        $imageIds = $blocks->whereIn('type', ['image', 'featured'])->pluck('media_id')->push($content['cover_id'] ?? null)->filter()->unique();
        if ($imageIds->isNotEmpty() && HubMedia::whereIn('id', $imageIds)->whereIn('mime', ['image/jpeg', 'image/png', 'image/webp', 'image/gif'])->count() !== $imageIds->count()) {
            throw ValidationException::withMessages(['cover_id' => 'Choose an image for the cover or image block.']);
        }
        foreach ($content['sections'] as $sectionIndex => &$section) {
            if (isset($section['hidden'])) {
                $section['hidden'] = (bool) $section['hidden'];
            }
            foreach ($section['blocks'] as $blockIndex => &$block) {
                $field = 'sections.'.$sectionIndex.'.blocks.'.$blockIndex;
                $block['title'] ??= '';
                if ($block['type'] === 'links' && array_key_exists('links', $block)) {
                    $block['links'] ??= [];
                    foreach ($block['links'] as &$link) {
                        $link['title'] ??= '';
                        $link['url'] ??= '';
                    }
                    unset($link);
                }
                if ($block['type'] === 'poll' && array_key_exists('options', $block)) {
                    $block['options'] = array_map(fn ($answer) => trim($answer ?? ''), $block['options'] ?? []);
                    $labels = array_map('mb_strtolower', array_values(array_filter($block['options'], fn ($answer) => $answer !== '')));
                    if (count(array_unique($labels)) !== count($labels)) {
                        throw ValidationException::withMessages([$field.'.options' => 'Give each option a different, clear label.']);
                    }
                }
                if ($block['type'] === 'poll' && ! empty($block['closes_at']) && strlen($block['closes_at']) > 10) {
                    $block['closes_at'] = HubPolls::closingInstant($block['closes_at'])->toISOString();
                }
                $allowed = match ($block['type']) {
                    'featured' => ['id', 'type', 'title', 'body', 'media_id', 'alt', 'story_id', 'layout', 'tone', 'width', 'hidden', 'min_height'],
                    'button' => ['id', 'type', 'title', 'body', 'url', 'action_label', 'tone', 'width', 'hidden', 'min_height'],
                    'notice' => ['id', 'type', 'title', 'body', 'tone', 'width', 'hidden', 'min_height'],
                    'campaign' => ['id', 'type', 'title', 'limit', 'team_ids', 'tone', 'width', 'hidden', 'min_height'],
                    default => null,
                };
                if ($allowed !== null && array_diff(array_keys($block), $allowed) !== []) {
                    throw ValidationException::withMessages([$field => 'Use settings available for this block type.']);
                }
                if (isset($block['layout']) && ! in_array($block['layout'], match ($block['type']) {
                    'featured' => ['mosaic', 'equal', 'single'], 'news', 'links', 'resources', 'teams' => ['cards', 'list'], default => [],
                }, true)) {
                    throw ValidationException::withMessages([$field.'.layout' => 'Choose a presentation available for this block.']);
                }
                if (array_key_exists('story_id', $block) && $block['type'] !== 'featured') {
                    throw ValidationException::withMessages([$field.'.story_id' => 'Choose a published story for a featured block.']);
                }
                if (array_key_exists('action_label', $block) && $block['type'] !== 'button') {
                    throw ValidationException::withMessages([$field.'.action_label' => 'Button labels belong on call to action blocks.']);
                }
                if ($block['type'] === 'featured' && mb_strlen($block['body'] ?? '') > 600) {
                    throw ValidationException::withMessages([$field.'.body' => 'Keep the featured summary within 600 characters.']);
                }
                if (isset($block['mode']) && $block['type'] !== 'people') {
                    throw ValidationException::withMessages([$field.'.mode' => 'Choose a display mode for a people block.']);
                }
                if (isset($block['team_ids'])) {
                    if (! in_array($block['type'], ['people', 'teams', 'news', 'resources', 'campaign'], true) || array_diff($block['team_ids'], $teams)
                        || count($block['team_ids']) !== count(array_unique($block['team_ids']))) {
                        throw ValidationException::withMessages([$field.'.team_ids' => 'Choose available departments for a people, team, news, resource or campaign block.']);
                    }
                    $block['team_ids'] = array_map('intval', array_values($block['team_ids']));
                }
                if (isset($block['hidden'])) {
                    $block['hidden'] = (bool) $block['hidden'];
                }
                if (isset($block['min_height'])) {
                    $block['min_height'] = (int) $block['min_height'];
                }
                if (isset($block['story_id'])) {
                    $block['story_id'] = (int) $block['story_id'];
                }
                if ($block['type'] === 'video' && ! empty($block['url']) && ! in_array(parse_url($block['url'], PHP_URL_HOST), ['www.youtube.com', 'www.youtube-nocookie.com', 'player.vimeo.com'], true)) {
                    throw ValidationException::withMessages(['sections' => 'Use an approved YouTube or Vimeo video link.']);
                }
            }
            unset($block);
        }
        unset($section);

        $featured = collect($content['sections'])->flatMap(fn ($section) => $section['blocks'])->where('type', 'featured')->filter(fn ($block) => ! empty($block['story_id']));
        if ($featured->isNotEmpty()) {
            $stories = app(HubFeatured::class)->stories($actor, availableTeamIds: $teams);
            if ($page?->kind === 'department') {
                $stories = array_values(array_filter($stories, fn ($story) => array_intersect($story['team_ids'], $content['team_ids'] ?? []) !== []));
            }
            $available = array_column($stories, 'id');
            foreach ($content['sections'] as $sectionIndex => $section) {
                foreach ($section['blocks'] as $blockIndex => $block) {
                    if ($block['type'] === 'featured' && ! empty($block['story_id']) && ! in_array($block['story_id'], $available, true)) {
                        throw ValidationException::withMessages(['sections.'.$sectionIndex.'.blocks.'.$blockIndex.'.story_id' => 'Choose a currently available published story. Clear or replace an unavailable selection before saving.']);
                    }
                }
            }
        }

        // Legacy/migration-pending saves without a reference never query registry tables.
        if (isset($content['collection_id'])) {
            $content['collection_id'] = (int) $content['collection_id'];
            app(HubCollections::class)->validateReference($actor, $content['collection_id'], $page);
        }

        if (! empty($content['campaign'])) {
            app(HubCampaign::class)->validateReferences($actor, $content['campaign']);
        }

        return $content;
    }
}
