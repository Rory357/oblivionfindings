<?php

namespace App\Domain\CompanyHub\Services;

use App\Domain\CompanyHub\Models\HubPage;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/** Department spaces compose existing published content and current work profiles. */
class HubDepartment
{
    public function __construct(private readonly HubAccess $access, private readonly HubDiscovery $discovery) {}

    public function read(User $viewer, HubPage $page, array $feed, array $people): ?array
    {
        if ($page->kind !== 'department') {
            return null;
        }
        $revision = $this->access->assertRead($viewer, $page);
        $teams = collect($feed['interests']['available_teams'])->whereIn('id', $revision->content['team_ids'] ?? [])->values();
        $ids = $teams->pluck('id')->all();
        $related = collect($feed['pages'])->filter(fn ($item) => $item['id'] !== $page->id && array_intersect($item['team_ids'] ?? [], $ids));
        $owner = collect($people)->firstWhere('user_id', $page->owner_id);

        return [
            'actor_id' => (int) $viewer->id, 'page_id' => $page->id, 'revision_id' => $revision->id, 'version' => $revision->draft_version,
            'teams' => $teams->all(),
            'owner' => $owner ? collect($owner)->only(['id', 'name', 'position', 'department', 'work_email', 'work_phone'])->all() : null,
            'updates' => $related->where('kind', 'news')->values()->all(),
            'resources' => $related->whereIn('kind', ['page', 'knowledge'])->values()->all(),
            'people' => collect($people)->whereIn('department_id', $ids)->values()->all(),
            'can_create_update' => $viewer->canDo('company_hub.write') && $ids !== [] && $this->access->canAuthorAudience($viewer, $revision->content),
        ];
    }

    public function follow(User $viewer, HubPage $page, array $data): array
    {
        return DB::transaction(function () use ($viewer, $page, $data) {
            $page = HubPage::whereKey($page->id)->lockForUpdate()->firstOrFail();
            $revision = $this->access->assertRead($viewer, $page);
            abort_unless($page->kind === 'department', 404);
            abort_unless((int) $data['actor_id'] === (int) $viewer->id, 409, 'Your signed-in account changed. Reload this space before following its teams.');
            abort_unless((int) $data['revision_id'] === (int) $revision->id, 409, 'This space has changed. Reload to see its current teams.');
            User::whereKey($viewer->id)->lockForUpdate()->firstOrFail();
            $preferences = $this->discovery->preferences($viewer);
            $ids = array_values(array_intersect($revision->content['team_ids'] ?? [], array_column($preferences['available_teams'], 'id')));
            abort_unless($ids !== [], 409, 'No teams are currently available in this space. Reload the page.');
            $teams = $data['following'] ? array_values(array_unique([...$preferences['teams'], ...$ids])) : array_values(array_diff($preferences['teams'], $ids));
            abort_if(count($teams) > 100, 422, 'You can follow up to 100 teams. Update your interests before following another team.');
            $saved = $this->discovery->saveInterests($viewer, ['actor_id' => $data['actor_id'], 'version' => $data['version'], 'topics' => $preferences['topics'], 'teams' => $teams]);

            return ['actor_id' => (int) $viewer->id, 'page_id' => $page->id, 'revision_id' => $revision->id, 'interests' => $saved];
        });
    }
}
