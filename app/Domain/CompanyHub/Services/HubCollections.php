<?php

namespace App\Domain\CompanyHub\Services;

use App\Domain\CompanyHub\Models\HubCollection;
use App\Domain\CompanyHub\Models\HubPage;
use App\Domain\Hr\Services\HrCurrentStaffService;
use App\Models\AuditLog;
use App\Models\User;
use App\Services\UserSiteAccessService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

/** Registry names are shared metadata; counts never reveal unreadable resource membership. */
class HubCollections
{
    public function ready(): bool
    {
        return Schema::hasTable('hub_collections') && Schema::hasTable('hub_collection_legacy_pages');
    }

    public function context(User $viewer): array
    {
        return $this->read($viewer)['context'];
    }

    /** Optional entries are still checked against the fresh actor's current published access. */
    public function read(User $viewer, ?array $entries = null): array
    {
        $viewer = $this->currentActor($viewer);
        $entries = $this->visibleEntries($viewer, $entries);
        $ready = $this->ready();
        $registry = $ready ? HubCollection::orderBy('name')->orderBy('id')->get()->keyBy('id') : collect();
        $bindings = $ready ? DB::table('hub_collection_legacy_pages')->whereIn('hub_page_id', array_keys($entries))->get()->keyBy('hub_page_id') : collect();
        $counts = [];
        $legacy = [];
        $fields = [];
        foreach ($entries as $id => $entry) {
            $content = $entry['revision']->content;
            $name = is_string($content['collection'] ?? null) ? $content['collection'] : null;
            $modern = $content['collection_id'] ?? null;
            $collection = null;
            if ($modern !== null) {
                // An invalid modern reference must not expose a retained copied label.
                $collection = is_int($modern) ? $registry->get($modern) : null;
                $name = $collection?->name;
            } elseif ($binding = $bindings->get($id)) {
                if ($binding->legacy_label === $name) {
                    $collection = $registry->get($binding->collection_id);
                    $name = $collection?->name;
                }
            }
            $fields[$id] = ['collection_id' => $collection ? (int) $collection->id : null, 'collection' => $name];
            if ($collection) {
                $counts[$collection->id] = ($counts[$collection->id] ?? 0) + 1;
            } elseif ($modern === null && is_string($name) && trim($name) !== '') {
                $key = self::legacyKey($name);
                $legacy[$key]['name'] = $name;
                $legacy[$key]['entries'][$id] = $entry;
            }
        }
        $manage = $viewer->canDo('company_hub.layout');
        $writer = $viewer->canDo('company_hub.write') || $viewer->canDo('company_hub.publish');
        $items = $registry->filter(fn ($row) => $manage || ($counts[$row->id] ?? 0) > 0)
            ->map(fn ($row) => $this->summary($viewer, $row, $counts[$row->id] ?? 0))->values()->all();
        $groups = collect($legacy)->map(function ($group, $key) use ($viewer, $ready, $manage) {
            $adopt = $ready && $manage && collect($group['entries'])->every(fn ($entry) => $this->canAdoptPage($viewer, $entry));

            return ['key' => $key, 'name' => $group['name'], 'count' => count($group['entries']), 'can_adopt' => $adopt,
                'adoption_key' => $adopt ? $this->adoptionKey($viewer, $group['name'], $group['entries']) : null];
        })->sortBy('name')->values()->all();

        return ['context' => [
            'ready' => $ready, 'actor_id' => (int) $viewer->id, 'can_create' => $ready && $manage,
            'items' => $items, 'legacy_groups' => $groups,
            // Empty shared registry names are selectable before the first resource is published.
            'options' => $writer ? $registry->map(fn ($row) => ['id' => (int) $row->id, 'name' => $row->name, 'version' => $row->version])->values()->all() : [],
        ], 'fields' => $fields];
    }

    public function validateReference(User $actor, int $id, ?HubPage $page): void
    {
        $actor = $this->currentActor($actor);
        if (! $this->ready() || ! in_array($page?->kind, ['page', 'knowledge'], true)
            || ! ($actor->canDo('company_hub.write') || $actor->canDo('company_hub.publish')) || ! HubCollection::whereKey($id)->exists()) {
            throw ValidationException::withMessages(['collection_id' => 'Choose an available resource collection.']);
        }
    }

    public function create(User $actor, int $actorId, string $name): HubCollection
    {
        $actor = $this->assertMutation($actor, $actorId);

        return DB::transaction(fn () => $this->newCollection($actor, $name));
    }

    public function rename(User $actor, int $actorId, int $id, int $version, string $name): HubCollection
    {
        $actor = $this->assertMutation($actor, $actorId);

        return DB::transaction(function () use ($actor, $id, $version, $name) {
            $row = HubCollection::whereKey($id)->lockForUpdate()->firstOrFail();
            abort_unless($this->canRename($actor, $row), 404);
            abort_unless($row->version === $version, 409, 'This collection changed. Reload it before renaming.');
            $before = $row->name;
            $row->update(['name' => $this->name($name), 'version' => $row->version + 1, 'updated_by' => $actor->id]);
            $this->audit($actor, $row, 'renamed', ['previous_name' => $before]);

            return $row;
        });
    }

    public function adopt(User $actor, int $actorId, string $key, string $adoptionKey, string $name): HubCollection
    {
        $actor = $this->assertMutation($actor, $actorId);

        return DB::transaction(function () use ($actor, $key, $adoptionKey, $name) {
            $entries = $this->visibleEntries($actor);
            $read = $this->read($actor, $entries);
            $group = collect($read['context']['legacy_groups'])->firstWhere('key', $key);
            abort_unless($group, 404);
            abort_unless($group['can_adopt'], 403);
            abort_unless(hash_equals($group['adoption_key'], $adoptionKey), 409, 'These resources changed. Reload before adopting the collection.');
            $members = array_filter($entries, fn ($entry) => ($entry['revision']->content['collection_id'] ?? null) === null
                && ($entry['revision']->content['collection'] ?? null) === $group['name']
                && ($read['fields'][$entry['page']->id]['collection_id'] ?? null) === null);
            $locked = HubPage::whereIn('id', array_keys($members))->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            foreach ($members as $id => $entry) {
                $page = $locked->get($id);
                $revision = $page ? $this->access()->visibleRevision($actor, $page) : null;
                abort_unless($revision && (int) $revision->id === (int) $entry['revision']->id, 409, 'These resources changed. Reload before adopting the collection.');
                abort_unless($this->canAdoptPage($actor, ['page' => $page, 'revision' => $revision]), 403);
            }
            $row = $this->newCollection($actor, $name);
            foreach ($members as $id => $entry) {
                DB::table('hub_collection_legacy_pages')->updateOrInsert(['hub_page_id' => $id], [
                    'collection_id' => $row->id, 'legacy_label' => $group['name'], 'adopted_revision_id' => $entry['revision']->id,
                    'created_at' => now(), 'updated_at' => now(),
                ]);
            }
            $this->audit($actor, $row, 'legacy_adopted', ['legacy_label' => $group['name'],
                'pages' => array_map(fn ($entry) => ['page_id' => (int) $entry['page']->id, 'revision_id' => (int) $entry['revision']->id], array_values($members))]);

            return $row;
        });
    }

    public function mutationResponse(User $actor, HubCollection $row): array
    {
        $context = $this->context($actor);
        $summary = collect($context['items'])->firstWhere('id', (int) $row->id);

        return ['collection' => $summary, 'resourceCollections' => $context];
    }

    public static function legacyKey(string $name): string
    {
        return 'legacy:'.hash('sha256', $name);
    }

    private function visibleEntries(User $actor, ?array $entries = null): array
    {
        $pages = $entries === null ? HubPage::with('currentRelease')->whereIn('kind', ['page', 'knowledge'])->get() : collect($entries)->pluck('page');
        $result = [];
        foreach ($pages as $page) {
            $revision = $this->access()->visibleRevision($actor, $page);
            if (in_array($page->kind, ['page', 'knowledge'], true) && $revision) {
                $result[$page->id] = ['page' => $page, 'revision' => $revision];
            }
        }

        return $result;
    }

    private function canAdoptPage(User $actor, array $entry): bool
    {
        return $this->access()->canEdit($actor, $entry['page']) && $this->access()->canAuthorAudience($actor, $entry['revision']->content);
    }

    private function adoptionKey(User $actor, string $name, array $entries): string
    {
        ksort($entries, SORT_NUMERIC);
        $members = array_map(fn ($entry) => [(int) $entry['page']->id, (int) $entry['revision']->id], array_values($entries));

        return hash_hmac('sha256', json_encode([(int) $actor->id, $name, $members], JSON_THROW_ON_ERROR), (string) config('app.key'));
    }

    private function summary(User $actor, HubCollection $row, int $count): array
    {
        return ['id' => (int) $row->id, 'name' => $row->name, 'version' => $row->version, 'count' => $count, 'can_rename' => $this->canRename($actor, $row)];
    }

    private function canRename(User $actor, HubCollection $row): bool
    {
        return $actor->canDo('company_hub.layout') && ((int) $row->created_by === (int) $actor->id || $actor->canDo('company_hub.edit_all'));
    }

    private function assertMutation(User $actor, int $actorId): User
    {
        $actor = $this->currentActor($actor);
        abort_unless((int) $actor->id === $actorId, 409, 'Your signed-in account changed. Reload before changing collections.');
        abort_unless($actor->canDo('company_hub.layout'), 403);
        abort_unless($this->ready(), 503, 'Collection management is not available until the database update is installed.');

        return $actor;
    }

    private function currentActor(User $actor): User
    {
        $actor = $actor->fresh();
        abort_unless($actor, 403);
        $this->access()->assertEnter($actor);

        return $actor;
    }

    private function access(): HubAccess
    {
        return new HubAccess(app(HrCurrentStaffService::class), new UserSiteAccessService);
    }

    private function name(string $name): string
    {
        $name = trim($name);
        if ($name === '' || mb_strlen($name) > 60) {
            throw ValidationException::withMessages(['name' => 'Give the collection a name of up to 60 characters.']);
        }

        return $name;
    }

    private function newCollection(User $actor, string $name): HubCollection
    {
        $row = HubCollection::create(['name' => $this->name($name), 'version' => 1, 'created_by' => $actor->id, 'updated_by' => $actor->id]);
        $this->audit($actor, $row, 'created');

        return $row;
    }

    private function audit(User $actor, HubCollection $row, string $action, array $meta = []): void
    {
        AuditLog::create(['user_id' => $actor->id, 'action' => 'company_hub.collection.'.$action,
            'auditable_type' => HubCollection::class, 'auditable_id' => $row->id,
            'meta' => ['version' => $row->version, 'name' => $row->name, ...$meta]]);
    }
}
