<?php

namespace App\Domain\CompanyHub\Services;

use App\Domain\CompanyHub\Models\HubPage;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

/** Shared editorial milestones are separate from each colleague's activities. */
class HubCampaign
{
    public function __construct(private readonly HubAccess $access) {}

    public static function progress(array $content): array
    {
        $milestones = collect($content['campaign']['milestones'] ?? []);

        return ['total' => $milestones->count(), 'done' => $milestones->where('status', 'done')->count(), 'ends_on' => $content['campaign']['ends_on'] ?? null];
    }

    public function read(User $viewer, HubPage $page, array $feed, array $people): ?array
    {
        if ($page->kind !== 'campaign') {
            return null;
        }
        $content = $this->access->assertRead($viewer, $page)->content;
        $campaign = $content['campaign'] ?? [];
        $owner = collect($people)->firstWhere('user_id', $page->owner_id);

        return [
            ...self::progress($content), 'milestones' => $campaign['milestones'] ?? [],
            'stories' => $this->ordered($feed['pages'], $campaign['story_ids'] ?? [], ['news']),
            'resources' => $this->ordered($feed['pages'], $campaign['resource_ids'] ?? [], ['page', 'knowledge']),
            'events' => $this->ordered($this->upcomingEvents($feed['events']), $campaign['event_keys'] ?? []),
            'owner' => $owner ? collect($owner)->only(['id', 'name', 'position', 'department', 'work_email', 'work_phone'])->all() : null,
        ];
    }

    private function ordered(array $items, array $ids, ?array $kinds = null): array
    {
        $available = collect($items)->filter(fn ($item) => ! $kinds || in_array($item['kind'], $kinds, true))->keyBy('id');

        return collect($ids)->map(fn ($id) => $available->get($id))->filter()->values()->all();
    }

    private function upcomingEvents(array $events): array
    {
        // The canonical feed includes today's full calendar window. Omit
        // occurrences that have already ended earlier today from curation.
        return array_values(array_filter($events, fn ($event) => ! empty($event['end']) && Carbon::parse($event['end'])->isFuture()));
    }

    /** Recheck authorisation when selecting and publishing linked content. */
    public function validateReferences(User $actor, array $campaign): void
    {
        if (empty($campaign['story_ids']) && empty($campaign['resource_ids']) && empty($campaign['event_keys'])) {
            return;
        }
        $feed = app(HubFeed::class)->forUser($actor);
        foreach (['story_ids' => ['news'], 'resource_ids' => ['page', 'knowledge'], 'event_keys' => null] as $field => $kinds) {
            $ids = $campaign[$field] ?? [];
            $available = $this->ordered($field === 'event_keys' ? $this->upcomingEvents($feed['events']) : $feed['pages'], $ids, $kinds);
            if (count($available) !== count($ids)) {
                throw ValidationException::withMessages(['campaign.'.$field => 'Choose currently available '.($field === 'event_keys' ? 'events in the next 90 days' : ($field === 'story_ids' ? 'published stories' : 'published resources')).'. Remove unavailable selections before saving.']);
            }
        }
    }

    public static function readerContent(array $content, ?array $campaign): array
    {
        if (isset($content['campaign'])) {
            $content['campaign']['story_ids'] = array_column($campaign['stories'] ?? [], 'id');
            $content['campaign']['resource_ids'] = array_column($campaign['resources'] ?? [], 'id');
            $content['campaign']['event_keys'] = array_column($campaign['events'] ?? [], 'id');
        }

        return $content;
    }
}
