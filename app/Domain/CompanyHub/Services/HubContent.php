<?php

namespace App\Domain\CompanyHub\Services;

use App\Domain\CompanyHub\Models\HubMedia;
use App\Domain\CompanyHub\Models\HubPage;
use App\Domain\Hr\Services\HrCalendarAccessService;
use App\Models\User;
use App\Services\UserSiteAccessService;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class HubContent
{
    public const BLOCKS = ['text', 'news', 'shoutouts', 'events', 'celebrations', 'links', 'resources', 'people', 'poll', 'image', 'video', 'notices', 'file'];

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
            'title' => 'required|string|max:160', 'summary' => 'present|nullable|string|max:600',
            'category' => 'required|string|max:60', 'hero_label' => 'required|string|max:60',
            'topics' => 'sometimes|array|max:5', 'topics.*' => ['string', 'distinct', Rule::in(HubDiscovery::TOPICS)],
            'team_ids' => 'sometimes|array|max:100', 'team_ids.*' => 'integer|min:1|distinct',
            'collection' => 'sometimes|nullable|string|max:60',
            'resource_type' => 'sometimes|nullable|in:Guide,Policy,Checklist,Form,Information',
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
            'hero.overlay' => 'required_with:hero|integer|min:55|max:90',
            'hero.action_label' => 'nullable|string|max:60',
            'hero.action_url' => ['nullable', 'string', 'max:2000', $url],
            'hero.alignment' => ['sometimes', Rule::in(['left', 'center'])],
            'hero.icon' => ['sometimes', Rule::in(['heart-handshake', 'newspaper', 'sparkles', 'building-2', 'flag', 'door-open'])],
            'hero.show_search' => 'sometimes|boolean',
            'hero.show_summary' => 'sometimes|boolean',
            'hero.show_metrics' => 'sometimes|boolean',
            'audience' => 'required|array:type,ids', 'audience.type' => ['required', Rule::in(['all', 'sites', 'departments'])],
            'audience.ids' => 'present|array|max:100', 'audience.ids.*' => 'integer|min:1|distinct',
            'comments_enabled' => 'required|boolean', 'reactions_enabled' => 'required|boolean',
            'requires_approval' => 'required|boolean', 'show_in_navigation' => 'required|boolean',
            'navigation_order' => 'required|integer|min:0|max:100', 'review_on' => 'nullable|date_format:Y-m-d',
            'planned_for' => 'nullable|date_format:Y-m-d',
            'campaign' => 'sometimes|nullable|array:ends_on,steps',
            'campaign.ends_on' => 'nullable|date_format:Y-m-d',
            'campaign.steps' => 'required_with:campaign|array|min:1|max:12',
            'campaign.steps.*' => 'array:id,title,url',
            'campaign.steps.*.id' => 'required|string|max:60|regex:/^[a-zA-Z0-9_-]+$/|distinct',
            'campaign.steps.*.title' => 'required|string|max:200',
            'campaign.steps.*.url' => ['nullable', 'string', 'max:2000', $url],
            'sections' => 'present|array|max:12', 'sections.*' => 'array:id,title,columns,layout,tone,spacing,blocks',
            'sections.*.title' => 'nullable|string|max:100',
            'sections.*.layout' => ['sometimes', Rule::in(['equal', 'wide-left', 'wide-right'])],
            'sections.*.tone' => ['sometimes', Rule::in(['plain', 'soft', 'brand'])],
            'sections.*.spacing' => ['sometimes', Rule::in(['compact', 'comfortable', 'spacious'])],
            'sections.*.id' => 'required|string|max:60|regex:/^[a-zA-Z0-9_-]+$/|distinct',
            'sections.*.columns' => 'required|integer|min:1|max:3', 'sections.*.blocks' => 'present|array|max:12',
            'sections.*.blocks.*' => 'array:id,type,title,body,limit,category,media_id,alt,url,links,question,options,closes_at,width',
            'sections.*.blocks.*.width' => 'sometimes|integer|min:1|max:12',
            'sections.*.blocks.*.id' => 'required|string|max:60|regex:/^[a-zA-Z0-9_-]+$/|distinct',
            'sections.*.blocks.*.type' => ['required', Rule::in(self::BLOCKS)],
            'sections.*.blocks.*.title' => 'required|string|max:160',
            'sections.*.blocks.*.body' => 'nullable|string|max:20000',
            'sections.*.blocks.*.limit' => 'nullable|integer|min:1|max:12',
            'sections.*.blocks.*.category' => 'nullable|string|max:60',
            'sections.*.blocks.*.media_id' => 'nullable|integer', 'sections.*.blocks.*.alt' => 'nullable|string|max:300',
            'sections.*.blocks.*.url' => ['nullable', 'string', 'max:2000', $url],
            'sections.*.blocks.*.links' => 'nullable|array|max:24',
            'sections.*.blocks.*.links.*' => 'array:id,title,url,description',
            'sections.*.blocks.*.links.*.id' => 'required|string|max:60',
            'sections.*.blocks.*.links.*.title' => 'required|string|max:100',
            'sections.*.blocks.*.links.*.description' => 'nullable|string|max:200',
            'sections.*.blocks.*.links.*.url' => ['required', 'string', 'max:2000', $url],
            'sections.*.blocks.*.question' => 'nullable|string|max:300',
            'sections.*.blocks.*.options' => 'nullable|array|min:2|max:8',
            'sections.*.blocks.*.options.*' => 'required|string|max:120|distinct',
            'sections.*.blocks.*.closes_at' => 'nullable|date_format:Y-m-d',
        ];
        $content = Validator::make($input, $rules)->validate();
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
        if (! empty($content['hero']['action_label']) !== ! empty($content['hero']['action_url'])) {
            throw ValidationException::withMessages(['hero.action_url' => 'Add both a label and a destination for the hero link.']);
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
        $imageIds = $blocks->where('type', 'image')->pluck('media_id')->push($content['cover_id'] ?? null)->filter()->unique();
        if ($imageIds->isNotEmpty() && HubMedia::whereIn('id', $imageIds)->whereIn('mime', ['image/jpeg', 'image/png', 'image/webp', 'image/gif'])->count() !== $imageIds->count()) {
            throw ValidationException::withMessages(['cover_id' => 'Choose an image for the cover or image block.']);
        }
        foreach ($blocks as $block) {
            if ($block['type'] === 'video' && ! in_array(parse_url($block['url'] ?? '', PHP_URL_HOST), ['www.youtube.com', 'www.youtube-nocookie.com', 'player.vimeo.com'], true)) {
                throw ValidationException::withMessages(['sections' => 'Use an approved YouTube or Vimeo video link.']);
            }
            if ($block['type'] === 'poll' && (empty($block['question']) || count($block['options'] ?? []) < 2)) {
                throw ValidationException::withMessages(['sections' => 'A poll needs a question and at least two answers.']);
            }
        }

        return $content;
    }
}
