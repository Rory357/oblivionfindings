<?php

namespace App\Domain\CompanyHub\Services;

use App\Domain\CompanyHub\Models\HubPage;
use App\Domain\Hr\Services\FeedService;
use App\Domain\Hr\Services\HrCalendarAccessService;
use App\Domain\Hr\Services\HrCalendarAggregator;
use App\Domain\Hr\Services\HrCurrentStaffService;
use App\Models\User;
use App\Services\UserSiteAccessService;
use Illuminate\Support\Facades\DB;

class HubFeed
{
    public function __construct(
        private readonly HubAccess $access,
        private readonly FeedService $feed,
        private readonly UserSiteAccessService $sites,
        private readonly HrCurrentStaffService $staff,
    ) {}

    public function forUser(User $user, string $search = ''): array
    {
        $this->access->assertEnter($user);
        $pages = collect();
        $resources = collect();
        $editable = collect();
        $home = null;
        $homeId = null;
        $participation = null;
        $interests = app(HubDiscovery::class)->preferences($user);
        foreach (HubPage::with(['currentRelease', 'owner:id,name'])->orderByDesc('updated_at')->get() as $page) {
            if ($this->access->canEdit($user, $page)) {
                $editable->push([
                    'id' => $page->id, 'title' => $page->draft['title'], 'kind' => $page->kind,
                    'version' => $page->draft_version, 'archived' => (bool) $page->archived_at,
                    'review_on' => $page->draft['review_on'] ?? null,
                ]);
                if ($page->kind === 'home') {
                    $homeId = $page->id;
                }
            }
            $revision = $this->access->visibleRevision($user, $page);
            if (! $revision) {
                continue;
            }
            $content = $revision->content;
            if ($page->kind === 'home') {
                $home = $content;
                $participation = app(HubParticipation::class)->read($user, $page);

                continue;
            }
            // Search only the authorised published snapshot, never a draft.
            $matches = $search === '' || str_contains(mb_strtolower(implode(' ', [
                $content['title'], $content['summary'] ?? '', $content['category'],
                implode(' ', $content['topics'] ?? []),
                collect($interests['available_teams'])->whereIn('id', $content['team_ids'] ?? [])->pluck('name')->implode(' '),
                collect($content['sections'])->flatMap(fn ($s) => $s['blocks'])->pluck('body')->implode(' '),
                ($content['resource_type'] ?? null) === 'Checklist' ? collect($content['checklist'] ?? [])->pluck('title')->implode(' ') : '',
            ])), mb_strtolower($search));
            $pages->push([
                'id' => $page->id, 'slug' => $page->slug, 'kind' => $page->kind,
                'title' => $content['title'], 'summary' => $content['summary'], 'category' => $content['category'],
                'cover_url' => empty($content['cover_id']) ? null : '/company-hub/media/'.$content['cover_id'],
                'featured' => $content['featured'], 'published_at' => $revision->publish_at->toIso8601String(),
                'review_on' => $content['review_on'] ?? null,
                'revision_id' => $revision->id,
                'topics' => array_values(array_intersect($content['topics'] ?? [], HubDiscovery::TOPICS)),
                'team_ids' => array_values(array_intersect($content['team_ids'] ?? [], array_column($interests['available_teams'], 'id'))),
                'owner' => $page->owner?->name ?? 'Company team',
                'collection' => $content['collection'] ?? null, 'resource_type' => $content['resource_type'] ?? null,
                'show_in_navigation' => $content['show_in_navigation'], 'navigation_order' => $content['navigation_order'],
                'matches' => $matches,
            ]);
            if (in_array($page->kind, ['page', 'knowledge'], true)) {
                foreach (collect($content['sections'])->flatMap(fn ($section) => $section['blocks']) as $block) {
                    if ($block['type'] === 'file' && ! empty($block['media_id'])) {
                        $resources->push([...$pages->last(), 'resource_key' => 'file:'.$page->id.':'.$block['id'], 'title' => $block['title'], 'resource_url' => '/company-hub/media/'.$block['media_id'], 'resource_type' => 'File']);
                    }
                    if ($block['type'] === 'links') {
                        foreach ($block['links'] ?? [] as $index => $link) {
                            $resources->push([...$pages->last(), 'resource_key' => 'link:'.$page->id.':'.$block['id'].':'.$index, 'title' => $link['title'], 'summary' => $link['description'] ?? '', 'resource_url' => $link['url'], 'resource_type' => 'Link']);
                        }
                    }
                }
            }
        }
        $kudos = collect();
        if ($user->canDo('hr.recognition.view')) {
            $posts = $this->feed->getFeed('kudos', $search ?: null, $user->id, $this->sites->accessibleSiteIds($user), 20);
            $kudos = $posts->getCollection()->filter(fn ($post) => $post->kudos && $this->feed->canViewKudos($post->kudos, $user))
                ->map(function ($post) use ($user) {
                    $kudos = $post->kudos;

                    return [
                        'id' => $kudos->id, 'message' => $kudos->message,
                        'from' => $kudos->fromUser?->name, 'to' => $kudos->toUser?->name,
                        'to_id' => $kudos->to_user_id, 'category' => FeedService::KUDOS_CATEGORIES[$kudos->category] ?? $kudos->category,
                        'created_at' => $kudos->created_at->toIso8601String(),
                        'reactions' => collect(FeedService::REACTION_EMOJIS)->mapWithKeys(fn ($emoji) => [$emoji => [
                            'count' => $kudos->reactions->where('emoji', $emoji)->count(),
                            'mine' => $kudos->reactions->where('emoji', $emoji)->contains('user_id', $user->id),
                        ]])->all(),
                        'replies' => $kudos->replies->map(fn ($reply) => ['id' => $reply->id, 'name' => $reply->user?->name, 'body' => $reply->body])->all(),
                        'can_reply' => in_array((int) $user->id, [(int) $kudos->from_user_id, (int) $kudos->to_user_id], true),
                    ];
                })->values();
        }
        // Team interests use only departments on current, visible work profiles.
        $recipientTeams = app(HrCalendarAccessService::class)->visibleCurrentProfilesQuery($user)
            ->whereIn('user_id', $kudos->pluck('to_id'))
            ->whereIn('department_id', array_column($interests['available_teams'], 'id'))
            ->pluck('department_id', 'user_id');
        $kudos = $kudos->map(fn ($post) => [...$post, 'team_ids' => array_values(array_filter([$recipientTeams[$post['to_id']] ?? null]))]);

        // Hub readers can see company events within the canonical calendar
        // audience/Site boundary; HR workspace access is not required.
        $availableEvents = collect(app(HrCalendarAggregator::class)->feed(now()->toDateString(), now()->addDays(90)->toDateString(), ['event'], [], $user));
        $events = $availableEvents
            ->filter(fn ($event) => $search === '' || str_contains(mb_strtolower($event['title'].' '.strip_tags($event['extendedProps']['description'] ?? '').' '.($event['extendedProps']['department'] ?? '')), mb_strtolower($search)))
            ->map(function ($event) {
                $event['extendedProps']['attachments'] = array_map(fn ($file) => [...$file, 'url' => '/company-hub/event-files/'.$file['id']], $event['extendedProps']['attachments'] ?? []);

                return $event;
            })->sortBy('start')->values()->all();
        $preferences = DB::table('hub_preferences')->where('user_id', $user->id)->first();
        $milestones = $user->canDo('hr.recognition.view') ? $this->feed->getMilestones() : ['birthdays' => [], 'anniversaries' => [], 'new_hires' => []];
        $announcements = $this->feed->getFeedAnnouncements($user->id, $search ?: null);

        return [
            'sharedNavigation' => app(HubNavigation::class)->forUser($user),
            'home' => $home ?? HubContent::defaults(), 'homeId' => $homeId,
            // Stable across Hub tabs/search; only currently visible published records.
            'heroSummary' => [
                ['label' => 'Company stories', 'value' => $pages->where('kind', 'news')->count(), 'url' => '/company-hub?view=news'],
                ['label' => 'Guides & pages', 'value' => $pages->whereIn('kind', ['page', 'knowledge'])->count(), 'url' => '/company-hub?view=resources'],
                ['label' => 'Team spaces', 'value' => $pages->where('kind', 'department')->count(), 'url' => '/company-hub?view=teams'],
                ['label' => 'Events · next 90 days', 'value' => $availableEvents->count(), 'url' => '/company-hub?view=events'],
            ],
            'participation' => $participation,
            'pages' => $pages->where('matches', true)->sortByDesc('published_at')->values()->all(),
            'resourceItems' => $resources->filter(fn ($item) => $search === '' || str_contains(mb_strtolower($item['title'].' '.$item['summary'].' '.$item['category']), mb_strtolower($search)))->values()->all(),
            'navigation' => $pages->where('show_in_navigation', true)->sortBy('navigation_order')->values()->all(),
            'editablePages' => $editable->all(), 'kudos' => $kudos->all(), 'events' => $events,
            'milestones' => $milestones,
            'announcements' => $announcements,
            'interests' => $interests,
            'updates' => app(HubDiscovery::class)->updates($user, $pages->where('matches', true)->values()->all(), $kudos->all(), $events, $announcements, $interests),
            'capabilities' => [...$this->access->capabilities($user), 'recognise' => $user->canDo('hr.recognition.give'), 'calendar' => $user->canDo('hr.calendar.view'), 'createEvent' => app(HubEventPublishing::class)->canCreate($user)],
            'employees' => $user->canDo('hr.recognition.give') ? $this->staff->currentUsersQuery()->orderBy('name')->get(['id', 'name'])->toArray() : [],
            'kudosCategories' => FeedService::KUDOS_CATEGORIES, 'kudosImpacts' => FeedService::KUDOS_IMPACTS,
            'shortcuts' => $preferences ? json_decode($preferences->shortcuts ?? '[]', true) : [],
            'notificationPreferences' => $preferences ? json_decode($preferences->notifications ?? '{}', true) : [],
            'followedPages' => array_values(array_intersect(json_decode($preferences?->followed_pages ?? '[]', true), $pages->pluck('id')->all())),
            'lastSeenAt' => $preferences?->last_seen_at,
            'recentPages' => DB::table('hub_reads')->where('user_id', $user->id)->whereIn('hub_page_id', $pages->pluck('id'))->orderByDesc('last_read_at')->limit(50)->pluck('hub_page_id')->all(),
            'bookmarks' => DB::table('hub_bookmarks')->where('user_id', $user->id)->get(['subject_type', 'subject_id'])->toArray(),
            'search' => $search,
            'birthdaySettings' => $user->canDo('company_hub.celebrations')
                ? $this->sites->applyStaffScope(User::query(), $user)
                    ->leftJoin('hub_celebration_settings', 'hub_celebration_settings.user_id', '=', 'users.id')
                    ->orderBy('users.name')->get(['users.id', 'users.name', 'hub_celebration_settings.birthday_enabled'])
                    ->map(fn ($person) => ['id' => $person->id, 'name' => $person->name, 'enabled' => (bool) $person->birthday_enabled])->all()
                : [],
        ];
    }
}
