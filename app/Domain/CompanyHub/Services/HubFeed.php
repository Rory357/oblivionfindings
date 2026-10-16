<?php

namespace App\Domain\CompanyHub\Services;

use App\Domain\CompanyHub\Models\HubMedia;
use App\Domain\CompanyHub\Models\HubPage;
use App\Domain\Hr\Services\FeedService;
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
        $user = $user->fresh();
        abort_unless($user, 403);
        $this->access->assertEnter($user);
        $documents = app(HubResourceDocuments::class);
        $savedDocuments = DB::table('hub_bookmarks')->where('user_id', $user->id)->where('subject_type', 'media')->pluck('subject_id')->map(fn ($id) => (int) $id)->all();
        $pages = collect();
        $resources = collect();
        $editable = collect();
        $discardedDrafts = collect();
        $drafts = app(HubDraft::class);
        $home = null;
        $homeSnapshot = null;
        $homeId = null;
        $participation = null;
        $interests = app(HubDiscovery::class)->preferences($user);
        $notificationPreferences = app(HubNotificationPreferences::class)->read($user);
        $birthdayPreferences = app(HubCelebrations::class)->forUser($user);
        $availableTeamIds = array_column($interests['available_teams'], 'id');
        $canAuthor = $user->canDo('company_hub.write') || $user->canDo('company_hub.edit_all');
        $editAll = $user->canDo('company_hub.edit_all');
        $recordQuery = HubPage::with(['currentRelease' => fn ($query) => $query->select([
            'hub_revisions.id', 'hub_revisions.hub_page_id', 'hub_revisions.created_by', 'hub_revisions.draft_version',
            'hub_revisions.content', 'hub_revisions.publish_at', 'hub_revisions.expires_at',
        ]), 'owner:id,name'])
            ->select(['hub_pages.id', 'slug', 'kind', 'owner_id', 'archived_at', 'updated_at', 'draft_version'])
            ->where(function ($records) use ($user, $canAuthor, $editAll): void {
                $records->where(fn ($published) => $published->whereNull('archived_at')->whereHas('currentRelease'));
                if ($canAuthor) {
                    $records->orWhere(fn ($editable) => $editAll ? $editable->whereRaw('1 = 1') : $editable->where('owner_id', $user->id));
                }
            });
        if ($drafts->ready()) {
            $recordQuery->addSelect('draft_discarded_at');
        }
        // Non-authors never load mutable draft bodies. Authors only need their
        // own draft unless the canonical edit-all permission permits more.
        if ($editAll) {
            $recordQuery->addSelect('draft');
        } elseif ($canAuthor) {
            $recordQuery->selectRaw("CASE WHEN owner_id = ? THEN draft ELSE '{}' END AS draft", [$user->id]);
        } else {
            $recordQuery->selectRaw("'{}' AS draft");
        }
        $records = $recordQuery->orderByDesc('updated_at')->get();
        $visibleReleases = $records->mapWithKeys(function ($page) use ($user): array {
            $revision = $this->access->visibleRevision($user, $page);

            return $revision ? [$page->id => $revision] : [];
        });
        $fileIds = $visibleReleases->flatMap(fn ($revision) => HubContent::visibleBlocks($revision->content)->where('type', 'file')->pluck('media_id'))->filter()->unique();
        $fileMedia = $fileIds->isEmpty() ? collect() : HubMedia::whereIn('id', $fileIds)->get()->keyBy('id');
        $stories = app(HubFeatured::class)->stories($user, $records, $availableTeamIds);
        $readerEntries = [];
        foreach ($records as $page) {
            if ($canAuthor && ($editAll || (int) $page->owner_id === (int) $user->id) && $this->access->canEdit($user, $page)) {
                $entry = [
                    'id' => $page->id, 'title' => $page->draft['title'], 'kind' => $page->kind,
                    'version' => $page->draft_version, 'archived' => (bool) $page->archived_at,
                    'review_on' => $page->draft['review_on'] ?? null,
                    'has_saved_draft' => $drafts->hasSavedDraft($page),
                ];
                if ($drafts->isDiscarded($page)) {
                    $discardedDrafts->push([...$entry, 'draft_recovery' => $drafts->read($user, $page)]);
                }
                if (! $drafts->isDiscarded($page) || $page->currentRelease) {
                    $editable->push($entry);
                }
                if ($page->kind === 'home') {
                    $homeId = $page->id;
                }
            }
            $revision = $visibleReleases->get($page->id);
            if (! $revision) {
                continue;
            }
            $sourceTeams = $page->kind === 'department' ? array_values(array_intersect($revision->content['team_ids'] ?? [], $availableTeamIds)) : null;
            $content = HubFeatured::readerContent(HubContent::readerContent($revision->content, $availableTeamIds), $stories, $sourceTeams);
            if ($page->kind === 'home') {
                $home = HubCampaign::readerContent($content, null);
                $homeSnapshot = $revision->content;
                $participation = app(HubParticipation::class)->read($user, $page);

                continue;
            }
            $readerEntries[$page->id] = ['page' => $page, 'revision' => $revision, 'content' => $content];
            $pages->push([
                'id' => $page->id, 'slug' => $page->slug, 'kind' => $page->kind,
                'title' => $content['title'], 'summary' => $content['summary'], 'category' => $content['category'],
                'cover_url' => empty($content['cover_id']) ? null : '/company-hub/media/'.$content['cover_id'],
                'featured' => $content['featured'], 'published_at' => $revision->publish_at->toIso8601String(),
                'review_on' => $content['review_on'] ?? null,
                'revision_id' => $revision->id,
                'campaign_progress' => $page->kind === 'campaign' ? HubCampaign::progress($content) : null,
                'topics' => array_values(array_intersect($content['topics'] ?? [], HubDiscovery::TOPICS)),
                'team_ids' => array_values(array_intersect($content['team_ids'] ?? [], array_column($interests['available_teams'], 'id'))),
                'owner' => $page->owner?->name ?? 'Company team',
                'collection' => $content['collection'] ?? null, 'resource_type' => $content['resource_type'] ?? null,
                'show_in_navigation' => $content['show_in_navigation'], 'navigation_order' => $content['navigation_order'],
                'matches' => true,
            ]);
            foreach (collect($content['sections'])->flatMap(fn ($section) => $section['blocks']) as $block) {
                if ($block['type'] === 'file' && ! empty($block['media_id'])) {
                    $media = $fileMedia->get($block['media_id']);
                    if ($media && (int) $media->hub_page_id === (int) $page->id) {
                        $resources->push([...$pages->last(), ...$documents->metadata($media),
                            'page_title' => $content['title'], 'block_id' => $block['id'],
                            'resource_key' => 'file:'.$page->id.':'.$block['id'], 'title' => $block['title'],
                            'summary' => $block['body'] ?? '', 'resource_url' => '/company-hub/documents/'.$media->documentId(), 'resource_type' => 'File']);
                    }
                }
                if ($block['type'] === 'links' && in_array($page->kind, ['page', 'knowledge'], true)) {
                    foreach ($block['links'] ?? [] as $index => $link) {
                        $resources->push([...$pages->last(), 'resource_key' => 'link:'.$page->id.':'.$block['id'].':'.$index, 'title' => $link['title'], 'summary' => $link['description'] ?? '', 'resource_url' => $link['url'], 'resource_type' => 'Link']);
                    }
                }
            }
        }
        $collections = app(HubCollections::class)->read($user, $readerEntries);
        $metadata = app(HubReaderMetadata::class)->forPages($user, $readerEntries, $interests['available_teams']);
        $pages = $pages->map(function ($row) use ($metadata, $readerEntries, $collections, $search) {
            $row = [...$row, ...$metadata[$row['id']], ...($collections['fields'][$row['id']] ?? [])];
            $row['matches'] = HubReaderMetadata::matches($row, $search, HubReaderMetadata::body($readerEntries[$row['id']]['content']));

            return $row;
        });
        $resources = $resources->map(fn ($row) => [...$row, ...$metadata[$row['id']], ...($collections['fields'][$row['id']] ?? []),
            ...(isset($row['document_id']) ? ['bookmarked' => in_array($row['document_id'], $savedDocuments, true)] : [])]);
        $resourceItems = $resources->filter(fn ($item) => $documents->matches($user, $item, $search))->values()->all();
        $recentResources = app(HubDocumentReads::class)->recent($user, $pages->where('matches', true)->values()->all(), $resourceItems);
        $recognition = app(HubRecognition::class);
        $recognitionBrowse = $recognition->browse($user, ['search' => $search]);
        $kudos = collect($recognitionBrowse['data']);

        // Hub readers can see company events within the canonical calendar
        // audience/Site boundary; HR workspace access is not required.
        $availableEvents = collect(app(HrCalendarAggregator::class)->feed(now()->toDateString(), now()->addDays(90)->toDateString(), ['event'], [], $user));
        $events = $availableEvents
            ->filter(fn ($event) => $search === '' || str_contains(mb_strtolower($event['title'].' '.strip_tags($event['extendedProps']['description'] ?? '').' '.($event['extendedProps']['department'] ?? '')), mb_strtolower($search)))
            ->map(function ($event) {
                $event['extendedProps']['attachments'] = array_map(fn ($file) => HubEventMedia::attachmentUrls($file, '/company-hub/event-files/'.$file['id']), $event['extendedProps']['attachments'] ?? []);

                return $event;
            })->sortBy('start')->values()->all();
        $preferences = DB::table('hub_preferences')->where('user_id', $user->id)->first();
        $milestones = $user->canDo('hr.recognition.view') ? $this->feed->getMilestones() : ['birthdays' => [], 'anniversaries' => [], 'new_hires' => []];
        $announcements = $this->feed->getFeedAnnouncements($user->id, $search ?: null);

        $payload = [
            'starterTemplates' => HubTemplates::catalogue(),
            'resourceCollections' => $collections['context'],
            'sharedNavigation' => app(HubNavigation::class)->forUser($user, $pages->values()->all()),
            'home' => $home ?? HubFeatured::readerContent(HubContent::defaults(), $stories), 'homeId' => $homeId,
            // Stable across Hub tabs/search; only currently visible published records.
            'heroSummary' => [
                ['label' => 'Company stories', 'value' => $pages->where('kind', 'news')->where('show_in_news', true)->count(), 'url' => '/company-hub?view=news'],
                ['label' => 'Guides & pages', 'value' => $pages->whereIn('kind', ['page', 'knowledge'])->count(), 'url' => '/company-hub?view=resources'],
                ['label' => 'Team spaces', 'value' => $pages->where('kind', 'department')->count(), 'url' => '/company-hub?view=teams'],
                ['label' => 'Events · next 90 days', 'value' => $availableEvents->count(), 'url' => '/company-hub?view=events'],
            ],
            'participation' => $participation,
            'pages' => $pages->where('matches', true)->sortByDesc('published_at')->values()->all(),
            'resourceItems' => $resourceItems,
            ...$recentResources,
            'document_text_incomplete' => $documents->textIncomplete(),
            'navigation' => $pages->where('show_in_navigation', true)->sortBy('navigation_order')->values()->all(),
            'editablePages' => $editable->all(), 'discardedDrafts' => $discardedDrafts->all(), 'kudos' => $kudos->all(), 'events' => $events,
            'recognitionBrowse' => $recognitionBrowse, 'savedEvents' => app(HubSavedEvents::class)->forUser($user),
            'recognition' => $recognition->read($user), 'recognitionTeams' => $recognition->teams($user),
            'milestones' => $milestones,
            'announcements' => $announcements,
            'readingAttention' => app(HubReadingAttention::class)->forUser($user),
            'interests' => $interests,
            'updates' => app(HubDiscovery::class)->updates($user, $pages->where('matches', true)->values()->all(), $kudos->all(), $events, $announcements, $interests),
            'capabilities' => [...$this->access->capabilities($user), 'recognise' => $user->canDo('hr.recognition.give'), 'calendar' => $user->canDo('hr.calendar.view'), 'createEvent' => app(HubEventPublishing::class)->canCreate($user)],
            'employees' => $recognition->recipients($user),
            'kudosCategories' => FeedService::KUDOS_CATEGORIES, 'kudosImpacts' => FeedService::KUDOS_IMPACTS,
            'shortcuts' => $preferences ? json_decode($preferences->shortcuts ?? '[]', true) : [],
            'notificationPreferences' => $notificationPreferences['preferences'],
            'notificationPreferenceContext' => array_diff_key($notificationPreferences, ['preferences' => true]),
            'followedPages' => array_values(array_intersect(json_decode($preferences?->followed_pages ?? '[]', true), $pages->pluck('id')->all())),
            'lastSeenAt' => $preferences?->last_seen_at,
            'recentPages' => DB::table('hub_reads')->where('user_id', $user->id)->whereIn('hub_page_id', $pages->pluck('id'))->orderByDesc('last_read_at')->limit(50)->pluck('hub_page_id')->all(),
            'bookmarks' => DB::table('hub_bookmarks')->where('user_id', $user->id)->get(['id', 'subject_type', 'subject_id'])
                ->map(fn ($bookmark) => ['subject_type' => $bookmark->subject_type, 'subject_id' => (int) $bookmark->subject_id,
                    'version' => HubBookmarks::version((int) $user->id, $bookmark->subject_type, (int) $bookmark->subject_id, $bookmark->id)])->all(),
            'search' => $search,
            'birthdaySettings' => $birthdayPreferences['people'],
            'birthdayPreferenceContext' => array_diff_key($birthdayPreferences, ['people' => true]),
        ];

        return [...$payload, 'blockData' => app(HubBlocks::class)->forUser($user, $homeSnapshot ?? HubContent::defaults(), $payload, publishedPages: $pages->sortByDesc('published_at')->values()->all())];
    }
}
