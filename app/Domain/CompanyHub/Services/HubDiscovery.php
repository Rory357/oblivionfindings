<?php

namespace App\Domain\CompanyHub\Services;

use App\Domain\Hr\Services\HrCalendarAccessService;
use App\Domain\Hr\Services\HrCurrentStaffService;
use App\Models\User;
use App\Services\UserSiteAccessService;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/** Discovery projects already-authorised content; interests never grant access. */
class HubDiscovery
{
    public const TOPICS = ['People & culture', 'Wellbeing', 'Learning', 'Digital tools', 'Company news'];

    public function preferences(User $user): array
    {
        $user = $this->currentViewer($user);
        $teams = app(HrCalendarAccessService::class)->visibleDepartmentsQuery($user)->orderBy('name')->get(['id', 'name'])->toArray();
        $row = DB::table('hub_preferences')->where('user_id', $user->id)->first();
        $stored = json_decode($row?->interests ?? '{}', true);

        return [
            'actor_id' => (int) $user->id,
            'version' => (int) ($row?->interests_version ?? 1),
            'topics' => array_values(array_intersect($stored['topics'] ?? [], self::TOPICS)),
            'teams' => array_values(array_intersect($stored['teams'] ?? [], array_column($teams, 'id'))),
            'available_topics' => self::TOPICS, 'available_teams' => $teams,
        ];
    }

    public function saveInterests(User $user, array $data): array
    {
        abort_unless(isset($data['actor_id']) && (int) $data['actor_id'] === (int) $user->id, 409, 'Your signed-in account changed. Reload your saved interests before continuing.');

        return DB::transaction(function () use ($user, $data): array {
            User::whereKey($user->id)->lockForUpdate()->firstOrFail();
            $user = $this->currentViewer($user);
            $allowed = $this->preferences($user);
            $row = DB::table('hub_preferences')->where('user_id', $user->id)->lockForUpdate()->first();
            abort_unless((int) $data['version'] === (int) ($row?->interests_version ?? 1), 409, 'Your interests changed in another window. Reload your saved choices before trying again.');
            if (array_diff($data['topics'], self::TOPICS) || array_diff($data['teams'], array_column($allowed['available_teams'], 'id'))) {
                throw ValidationException::withMessages(['interests' => 'Choose topics and teams currently available to you.']);
            }
            DB::table('hub_preferences')->updateOrInsert(['user_id' => $user->id], [
                'interests' => json_encode(['topics' => array_values($data['topics']), 'teams' => array_map('intval', array_values($data['teams']))]),
                'interests_version' => $data['version'] + 1, 'updated_at' => now(),
            ]);

            return $this->preferences($user);
        });
    }

    private function currentViewer(User $user): User
    {
        $current = User::find($user->id);
        $sites = new UserSiteAccessService;
        $access = new HubAccess(app(HrCurrentStaffService::class), $sites);
        abort_unless($current && $access->canEnter($current) && $sites->applyStaffScope(User::query()->whereKey($current->id), $current)->exists(), 403);

        return $current;
    }

    public function updates(User $user, array $pages, array $kudos, array $events, array $announcements, array $interests): array
    {
        $items = [];
        foreach ($pages as $page) {
            if ($page['kind'] === 'news' && ! ($page['show_in_news'] ?? true)) {
                continue;
            }
            $type = match ($page['kind']) {
                'news' => 'news', 'campaign' => 'campaigns', 'department' => 'teams', default => 'resources',
            };
            $items[] = [
                'key' => 'page:'.$page['id'].':'.$page['revision_id'], 'type' => $type,
                'title' => $page['title'], 'summary' => $page['summary'], 'owner' => $page['owner'],
                'at' => $page['published_at'], 'url' => '/company-hub/read/'.$page['slug'],
                'cover_url' => $page['cover_url'], 'topics' => $page['topics'], 'teams' => $page['team_ids'],
                'bookmark' => ['subject_type' => 'page', 'subject_id' => $page['id']], 'page_id' => $page['id'],
            ];
        }
        foreach ($kudos as $post) {
            $items[] = [
                'key' => 'kudos:'.$post['id'], 'type' => 'shoutouts',
                'title' => ($post['to'] ?: 'A colleague').' received a shout-out', 'summary' => $post['message'],
                'owner' => $post['from'], 'at' => $post['created_at'], 'url' => HubRecognition::readerUrl((int) $post['id']),
                'topics' => ['People & culture'], 'teams' => $post['team_ids'] ?? [],
            ];
        }
        // One update per event, even when its series has many upcoming dates.
        foreach (collect($events)->unique('extendedProps.eventId') as $event) {
            $props = $event['extendedProps'];
            $at = $props['updatedAt'] ?? $props['createdAt'] ?? $event['start'];
            $items[] = [
                'key' => 'event:'.$props['eventId'].':'.sha1(json_encode([$at, $event['title'], $props['description'], $event['start'], $event['end'], $props['location'], $props['rrule'] ?? null])), 'type' => 'events',
                'title' => $event['title'], 'summary' => Str::limit(strip_tags($props['description'] ?? ''), 240),
                'owner' => $props['createdBy'] ?? 'Company team', 'at' => $at, 'event_id' => $event['id'],
                'url' => '/company-hub?view=events', 'starts_at' => $event['start'], 'topics' => [],
                'teams' => array_values(array_filter([$props['departmentId'] ?? null])),
                'bookmark' => ['subject_type' => 'event', 'subject_id' => $props['recurrenceParentId'] ?? $props['eventId']],
            ];
        }
        foreach ($announcements as $notice) {
            $at = $notice['updated_at_iso'] ?? $notice['published_at_iso'];
            $items[] = [
                'key' => 'notice:'.$notice['id'].':'.sha1(json_encode([$at, $notice['title'], $notice['content'], $notice['requires_acknowledgement']])), 'type' => 'announcements',
                'title' => $notice['title'], 'summary' => Str::limit(strip_tags($notice['content']), 240),
                'owner' => $notice['creator']['name'] ?? 'Company team', 'at' => $at,
                'url' => '/company-hub?view=updates&announcement='.$notice['id'], 'topics' => ['Company news'], 'teams' => [],
                'required' => $notice['requires_acknowledgement'] && ! $notice['viewer_acknowledged'],
            ];
        }
        $row = DB::table('hub_preferences')->where('user_id', $user->id)->first();
        $following = json_decode($row?->followed_pages ?? '[]', true);
        $seen = DB::table('hub_update_seen')->where('user_id', $user->id)->whereIn('update_key', array_column($items, 'key'))->pluck('update_key')->all();
        $baseline = $row?->last_seen_at ? Carbon::parse($row->last_seen_at)->toIso8601String() : null;

        return collect($items)->map(fn ($item) => [
            ...$item,
            'unread' => ! in_array($item['key'], $seen, true) && (! $baseline || strtotime($item['at']) > strtotime($baseline)),
            'following' => in_array($item['page_id'] ?? null, $following, true)
                || count(array_intersect($item['topics'], $interests['topics'])) > 0
                || count(array_intersect($item['teams'], $interests['teams'])) > 0,
        ])->sortByDesc('at')->values()->all();
    }

    public function seen(User $user, array $keys, ?string $undoBatch = null): array
    {
        app(HubAccess::class)->assertEnter($user);
        // Validate the current visible set again, including publication, expiry and canonical audiences.
        $updates = collect(app(HubFeed::class)->forUser($user)['updates']);
        abort_if(array_diff($keys, $updates->pluck('key')->all()), 409, 'Some updates are no longer available. Refresh the hub and try again.');

        return DB::transaction(function () use ($user, $keys, $undoBatch, $updates): array {
            User::whereKey($user->id)->lockForUpdate()->firstOrFail();
            $query = DB::table('hub_update_seen')->where('user_id', $user->id)->whereIn('update_key', $keys);
            if ($undoBatch) {
                $query->where('batch', $undoBatch)->delete();

                return ['marked' => [], 'batch' => null];
            }
            $existing = $query->pluck('update_key')->all();
            $new = array_values(array_diff(array_intersect($keys, $updates->where('unread', true)->pluck('key')->all()), $existing));
            $batch = (string) Str::uuid();
            $marked = [];
            foreach ($new as $key) {
                // Article read receipts can arrive between the query and this insert.
                if (DB::table('hub_update_seen')->insertOrIgnore(['user_id' => $user->id, 'update_key' => $key, 'batch' => $batch, 'seen_at' => now()])) {
                    $marked[] = $key;
                }
            }

            return ['marked' => $marked, 'batch' => $batch];
        });
    }
}
