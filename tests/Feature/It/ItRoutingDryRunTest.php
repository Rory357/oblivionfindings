<?php

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Domain\It\Services\ItTicketRoutingService;
use App\Models\ItQueue;
use App\Models\ItTeam;
use App\Models\ItTicket;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use Database\Seeders\RbacSeeder;

function dryRunUser(Site $site, string $role = 'hr'): User
{
    $user = User::factory()->create(['role' => $role, 'approved_at' => now()]);
    $user->roles()->sync([Role::query()->where('name', $role)->firstOrFail()->id]);
    HrEmployeeProfile::factory()->create([
        'user_id' => $user->id, 'primary_site_id' => $site->id, 'secondary_site_ids' => [],
        'is_active' => true, 'start_date' => today()->subYear(), 'end_date' => null,
        'created_by' => $user->id, 'updated_by' => $user->id,
    ]);

    return $user;
}

beforeEach(function () {
    $this->seed(RbacSeeder::class);
    $this->site = Site::factory()->create();
    $this->agent = dryRunUser($this->site);
    $this->ticket = ItTicket::factory()->create([
        'site_id' => $this->site->id,
        'requester_user_id' => $this->agent->id,
        'category' => 'network',
    ]);
});

test('the routing dry run explains what the rules would decide without mutating any ticket', function () {
    // Nothing is configured yet: the dry run names the gaps instead of a queue.
    $bare = collect($this->actingAs($this->agent)->getJson('/it/setup/routing-dry-run')->assertOk()->json('rows'))
        ->firstWhere('id', $this->ticket->id);
    expect($bare['proposed']['queue'])->toBeNull()
        ->and($bare['gaps'])->toContain('no_eligible_queue');

    // A newly configured default queue changes what the rules WOULD decide…
    $team = ItTeam::factory()->create(['manager_user_id' => $this->agent->id]);
    $team->members()->attach($this->agent->id, ['role' => 'member']);
    $queue = ItQueue::factory()->create(['team_id' => $team->id, 'name' => 'Network desk', 'filter_rules' => [
        'is_default' => true, 'site_ids' => [$this->site->id], 'cover_user_id' => $this->agent->id,
        'default_assignee_user_id' => $this->agent->id,
    ]]);

    $row = collect($this->actingAs($this->agent)->getJson('/it/setup/routing-dry-run')->assertOk()->json('rows'))
        ->firstWhere('id', $this->ticket->id);
    expect($row['proposed']['queue'])->toBe('Network desk')
        ->and($row['differs'])->toBeTrue()
        ->and($row['current']['queue'])->toBeNull();

    // …but the dry run itself never moved the ticket.
    expect($this->ticket->fresh()->queue_id)->toBeNull();

    // Once actually routed, the dry run agrees with reality.
    app(ItTicketRoutingService::class)->route($this->ticket->fresh());
    expect($this->ticket->fresh()->queue_id)->toBe($queue->id);
    $settled = collect($this->actingAs($this->agent)->getJson('/it/setup/routing-dry-run')->json('rows'))
        ->firstWhere('id', $this->ticket->id);
    expect($settled['differs'])->toBeFalse()
        ->and($settled['proposed']['assignee'])->toBe($this->agent->name);
});

test('the routing dry run is it.manage-only', function () {
    $viewer = User::factory()->create(['role' => 'support_worker', 'approved_at' => now()]);
    $viewer->roles()->sync([Role::query()->where('name', 'support_worker')->firstOrFail()->id]);
    $viewer->permissionOverrides()->attach(Permission::where('key', 'it.view')->firstOrFail()->id, ['allowed' => true]);

    $this->actingAs($viewer)->getJson('/it/setup/routing-dry-run')->assertForbidden();
});
