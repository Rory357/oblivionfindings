<?php

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Models\Role;
use App\Models\Site;
use App\Models\SiteHazard;
use App\Models\User;
use App\Services\Tasks\Providers\SiteHazardProvider;
use App\Services\Tasks\TaskAggregator;
use App\Services\Tasks\TaskItem;
use Database\Seeders\RbacSeeder;

// My Day's "My tasks" card asks the task modules for the viewer's own work and the sidebar count
// asks for everything. In a request that needed both, every plain module ran twice (31 Oct 2026;
// the medication modules on /my-day since c59d5ca28). "Assigned to me" is applied after the
// modules answer, so they now share one read. Real admin role, real hazard module.

beforeEach(function () {
    $this->seed(RbacSeeder::class);
    $this->site = Site::factory()->create(['type' => 'house', 'is_active' => true]);
    $this->admin = sharedReadAdmin($this->site);
    $this->other = User::factory()->create(['approved_at' => now()]);
    foreach ([$this->admin, $this->admin, $this->other, null] as $index => $assignee) {
        SiteHazard::query()->create(['site_id' => $this->site->id, 'reported_by_user_id' => $this->other->id,
            'hazard_type' => 'other', 'severity' => 'medium', 'likelihood' => 'possible',
            'description' => "Shared read hazard {$index}", 'status' => 'open', 'assigned_to_user_id' => $assignee?->id]);
    }
});

function sharedReadAdmin(Site $site): User
{
    $admin = User::factory()->create(['approved_at' => now(), 'role' => 'admin']);
    $admin->roles()->sync([Role::query()->where('name', 'admin')->value('id')]);
    HrEmployeeProfile::factory()->create(['user_id' => $admin->id, 'primary_site_id' => $site->id, 'secondary_site_ids' => [],
        'is_active' => true, 'start_date' => '2020-01-01', 'end_date' => null]);

    return $admin->fresh();
}

/** @return list<string> */
function sharedReadIds(array $items): array
{
    $ids = array_map(fn (TaskItem $item) => $item->id, $items);
    sort($ids);

    return $ids;
}

function sharedReadSpy(): SiteHazardProvider
{
    return new class extends SiteHazardProvider
    {
        public int $reads = 0;

        public function authorizedTasks(User $user, array $filters = []): array
        {
            $this->reads++;

            return parent::authorizedTasks($user, $filters);
        }
    };
}

it("reads each task module once when My Day's card and the sidebar count run in the same request", function () {
    $hazards = sharedReadSpy();
    $aggregate = new TaskAggregator([$hazards]);

    $mine = $aggregate->itemsFor($this->admin, ['assigned' => 'me']);
    $count = $aggregate->navigationBadgeFor($this->admin);

    expect($hazards->reads)->toBe(1);

    // The same answers as asking each on its own, with nothing shared.
    $all = (new TaskAggregator([new SiteHazardProvider]))->itemsFor($this->admin);
    expect($all)->toHaveCount(4)
        ->and($mine)->toHaveCount(2)
        ->and(sharedReadIds($mine))->toBe(sharedReadIds(array_filter($all, fn (TaskItem $item) => ($item->assignee['id'] ?? null) === $this->admin->id)))
        ->and($count)->toBe((new TaskAggregator([new SiteHazardProvider]))->navigationBadgeFor($this->admin));
});

it('still gives "unassigned" and "assigned to me" their own rows from the one read', function () {
    $hazards = sharedReadSpy();
    $aggregate = new TaskAggregator([$hazards]);

    expect($aggregate->itemsFor($this->admin, ['assigned' => 'unassigned']))->toHaveCount(1)
        ->and($aggregate->itemsFor($this->admin, ['assigned' => 'me']))->toHaveCount(2)
        ->and($aggregate->itemsFor($this->admin))->toHaveCount(4)
        ->and($hazards->reads)->toBe(1);
});

it('never shares a read between two people', function () {
    $second = sharedReadAdmin($this->site);
    $hazards = sharedReadSpy();
    $aggregate = new TaskAggregator([$hazards]);

    expect($aggregate->itemsFor($this->admin, ['assigned' => 'me']))->toHaveCount(2)
        ->and($aggregate->itemsFor($second, ['assigned' => 'me']))->toHaveCount(0)
        ->and($hazards->reads)->toBe(2);
});
