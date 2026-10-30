<?php

namespace App\Domain\CompanyHub\Services;

use App\Domain\CompanyHub\Models\HubPage;
use App\Domain\CompanyHub\Models\HubRevision;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class HubKnowledge
{
    public function __construct(private readonly HubAccess $access) {}

    public static function supports(HubPage $page, array $content): bool
    {
        return $page->kind === 'knowledge' || ($page->kind === 'page' && in_array($content['resource_type'] ?? null, ['Guide', 'Policy', 'Process', 'Checklist', 'Form'], true));
    }

    public static function supportsPersonalSteps(array $content): bool
    {
        return in_array($content['resource_type'] ?? null, ['Checklist', 'Process'], true);
    }

    public function read(User $viewer, HubPage $page, array $feed, array $people): ?array
    {
        $revision = $this->access->assertRead($viewer, $page);
        if (! self::supports($page, $revision->content)) {
            return null;
        }
        $teams = collect($feed['interests']['available_teams'] ?? [])->whereIn('id', $revision->content['team_ids'] ?? [])->values();
        $teamIds = $teams->pluck('id')->all();
        $contact = fn ($person) => $person ? collect($person)->only(['id', 'name', 'position', 'department', 'work_email', 'work_phone'])->all() : null;
        $related = collect($feed['pages'])->filter(fn ($item) => $item['id'] !== $page->id && array_intersect($item['team_ids'] ?? [], $teamIds));

        return [
            ...$this->progress($viewer, $page, $revision),
            'version' => $revision->draft_version,
            'review_overdue' => HubReaderMetadata::overdue($revision->content['review_on'] ?? null),
            'owner' => $contact(collect($people)->firstWhere('user_id', $page->owner_id)),
            'teams' => $teams->all(),
            'contacts' => collect($people)->whereIn('department_id', $teamIds)->take(4)->map($contact)->values()->all(),
            'related' => self::related($page->id, $revision->content['topics'] ?? [], $teamIds, $feed['pages']),
            'spaces' => $related->where('kind', 'department')->take(3)->values()->all(),
        ];
    }

    public static function related(int $pageId, array $topics, array $teamIds, array $pages): array
    {
        $topics = array_values(array_intersect($topics, HubDiscovery::TOPICS));

        return collect($pages)->filter(fn ($item) => (int) $item['id'] !== $pageId && in_array($item['kind'], ['page', 'knowledge'], true))
            ->map(fn ($item) => ['page' => $item, 'score' => count(array_intersect($item['topics'] ?? [], $topics)) * 2
                + (array_intersect($item['team_ids'] ?? [], $teamIds) !== [] ? 1 : 0)])
            ->filter(fn ($item) => $item['score'] > 0)->sort(function ($left, $right) {
                return $right['score'] <=> $left['score'] ?: strcmp($right['page']['published_at'], $left['page']['published_at'])
                    ?: ((int) $right['page']['id'] <=> (int) $left['page']['id']);
            })->take(3)->pluck('page')->values()->all();
    }

    private function progress(User $viewer, HubPage $page, HubRevision $revision): array
    {
        $steps = self::supportsPersonalSteps($revision->content) ? ($revision->content['checklist'] ?? []) : [];
        $done = DB::table('hub_interactions')->where('hub_page_id', $page->id)->where('user_id', $viewer->id)->where('kind', 'checklist')->pluck('body');

        return [
            'actor_id' => (int) $viewer->id, 'page_id' => $page->id, 'revision_id' => $revision->id,
            'steps' => array_map(function ($step) use ($done) {
                $key = HubParticipation::stepKey($step);

                return [...$step, 'key' => $key, 'done' => $done->contains($key)];
            }, $steps),
        ];
    }

    public function update(User $viewer, HubPage $page, array $data): array
    {
        return DB::transaction(function () use ($viewer, $page, $data) {
            $page = HubPage::whereKey($page->id)->lockForUpdate()->firstOrFail();
            $revision = $this->access->assertRead($viewer, $page);
            abort_unless((int) $data['actor_id'] === (int) $viewer->id, 409, 'Your signed-in account changed. Reload this page before updating your checklist.');
            abort_unless((int) $data['revision_id'] === (int) $revision->id, 409, 'This guide has changed. Reload to see the latest checklist.');
            abort_unless(self::supports($page, $revision->content) && self::supportsPersonalSteps($revision->content), 404);
            $step = collect($revision->content['checklist'] ?? [])->firstWhere('id', $data['step_id']);
            abort_unless($step, 404);
            $key = HubParticipation::stepKey($step);
            abort_unless(hash_equals($key, $data['step_key']), 409, 'This step has changed. Reload to see the latest checklist.');
            $query = DB::table('hub_interactions')->where('hub_page_id', $page->id)->where('user_id', $viewer->id)->where('kind', 'checklist')->where('body', $key);
            if (! $data['done']) {
                $query->delete();
            } elseif (! $query->exists()) {
                DB::table('hub_interactions')->insert(['hub_page_id' => $page->id, 'user_id' => $viewer->id, 'kind' => 'checklist', 'body' => $key, 'created_at' => now(), 'updated_at' => now()]);
            }

            return $this->progress($viewer, $page, $revision);
        });
    }
}
