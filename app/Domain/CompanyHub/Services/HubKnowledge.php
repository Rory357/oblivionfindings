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
        return $page->kind === 'knowledge' || ($page->kind === 'page' && in_array($content['resource_type'] ?? null, ['Guide', 'Policy', 'Checklist', 'Form'], true));
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
            'owner' => $contact(collect($people)->firstWhere('user_id', $page->owner_id)),
            'teams' => $teams->all(),
            'contacts' => collect($people)->whereIn('department_id', $teamIds)->take(4)->map($contact)->values()->all(),
            'related' => $related->whereIn('kind', ['knowledge', 'page'])->take(3)->values()->all(),
            'spaces' => $related->where('kind', 'department')->take(3)->values()->all(),
        ];
    }

    private function progress(User $viewer, HubPage $page, HubRevision $revision): array
    {
        $steps = ($revision->content['resource_type'] ?? null) === 'Checklist' ? ($revision->content['checklist'] ?? []) : [];
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
            abort_unless(self::supports($page, $revision->content) && ($revision->content['resource_type'] ?? null) === 'Checklist', 404);
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
