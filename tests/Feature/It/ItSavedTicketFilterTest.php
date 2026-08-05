<?php

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Models\ItSavedTicketFilter;
use App\Models\ItService;
use App\Models\ItTicket;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;

function savedTicketFilterActor(array $permissionKeys, Site $site): User
{
    $actor = User::factory()->create(['approved_at' => now()]);
    $role = Role::query()->create([
        'name' => 'saved-ticket-filter-'.str()->uuid(),
        'label' => 'Saved ticket filter test role',
        'level' => 50,
        'type' => 'custom',
    ]);

    foreach ($permissionKeys as $key) {
        $permission = Permission::query()->firstOrCreate(
            ['key' => $key],
            ['description' => $key, 'group' => 'it', 'module' => 'Operations'],
        );
        $role->permissions()->syncWithoutDetaching([$permission->id]);
    }
    $actor->roles()->attach($role);

    HrEmployeeProfile::factory()->create([
        'user_id' => $actor->id,
        'created_by' => $actor->id,
        'updated_by' => $actor->id,
        'primary_site_id' => $site->id,
        'secondary_site_ids' => [],
        'is_active' => true,
        'start_date' => now()->subMonth()->toDateString(),
        'end_date' => null,
    ]);

    return $actor;
}

test('an agent saves only allow-listed filters and currently accessible options', function () {
    $site = Site::factory()->create();
    $hiddenSite = Site::factory()->create();
    $agent = savedTicketFilterActor(['it.view', 'it.manage'], $site);
    $hiddenAssignee = savedTicketFilterActor(['it.view', 'it.manage'], $hiddenSite);
    $inactiveService = ItService::factory()->create(['is_active' => false]);

    $this->actingAs($agent)->post('/it/ticket-filters', [
        'name' => 'Urgent queue',
        'filters' => [
            'ticket_priority' => 'urgent',
            'site_id' => $hiddenSite->id,
            'assignee' => $hiddenAssignee->id,
            'service' => $inactiveService->id,
            'reopened' => true,
            'unknown_key' => 'must-not-survive',
        ],
    ])->assertRedirect();

    $saved = ItSavedTicketFilter::query()->sole();

    expect($saved->user_id)->toBe($agent->id)
        ->and($saved->name)->toBe('Urgent queue')
        ->and($saved->filters)->toHaveCount(2)
        ->and($saved->filters['ticket_priority'])->toBe('urgent')
        ->and($saved->filters['reopened'])->toBeTrue()
        ->and($saved->filters)->not->toHaveKey('site_id')
        ->and($saved->filters)->not->toHaveKey('assignee')
        ->and($saved->filters)->not->toHaveKey('service')
        ->and($saved->filters)->not->toHaveKey('unknown_key');
});

test('a personal filter applies server-side and combines Site scope with queue filters', function () {
    $site = Site::factory()->create();
    $secondSite = Site::factory()->create();
    $agent = savedTicketFilterActor(['it.view', 'it.manage'], $site);
    HrEmployeeProfile::query()->where('user_id', $agent->id)->update([
        'secondary_site_ids' => [$secondSite->id],
    ]);

    $matching = ItTicket::factory()->create([
        'title' => 'Urgent ticket at selected Site',
        'site_id' => $site->id,
        'priority' => 'urgent',
    ]);
    ItTicket::factory()->create([
        'title' => 'Normal ticket at selected Site',
        'site_id' => $site->id,
        'priority' => 'normal',
    ]);
    ItTicket::factory()->create([
        'title' => 'Urgent ticket at other Site',
        'site_id' => $secondSite->id,
        'priority' => 'urgent',
    ]);
    $saved = ItSavedTicketFilter::query()->create([
        'user_id' => $agent->id,
        'name' => 'Urgent at my Site',
        'filters' => ['site_id' => $site->id, 'ticket_priority' => 'urgent'],
    ]);

    $this->actingAs($agent)
        ->get("/it?tab=tickets&saved_filter={$saved->id}")
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('activeSavedTicketFilterId', $saved->id)
            ->where('filters.site_id', $site->id)
            ->where('filters.ticket_priority', 'urgent')
            ->has('tickets.data', 1)
            ->where('tickets.data.0.id', $matching->id));
});

test('saved filter payloads are private and stale Site access is pruned before display', function () {
    $oldSite = Site::factory()->create();
    $currentSite = Site::factory()->create();
    $owner = savedTicketFilterActor(['it.view', 'it.manage'], $oldSite);
    $other = savedTicketFilterActor(['it.view', 'it.manage'], $currentSite);

    $owned = ItSavedTicketFilter::query()->create([
        'user_id' => $owner->id,
        'name' => 'Old Site queue',
        'filters' => ['site_id' => $oldSite->id, 'ticket_status' => 'open'],
    ]);
    ItSavedTicketFilter::query()->create([
        'user_id' => $other->id,
        'name' => 'Someone else filter',
        'filters' => ['ticket_status' => 'waiting'],
    ]);

    HrEmployeeProfile::query()->where('user_id', $owner->id)->update([
        'primary_site_id' => $currentSite->id,
        'secondary_site_ids' => [],
    ]);

    $this->actingAs($owner)
        ->get('/it?tab=tickets')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('savedTicketFilters', 1)
            ->where('savedTicketFilters.0.id', $owned->id)
            ->where('savedTicketFilters.0.name', 'Old Site queue')
            ->missing('savedTicketFilters.0.filters'));

    expect($owned->fresh()->filters)->toBe(['ticket_status' => 'open']);
});

test('foreign saved filter reads and direct-object deletion fail closed', function () {
    $site = Site::factory()->create();
    $owner = savedTicketFilterActor(['it.view', 'it.manage'], $site);
    $other = savedTicketFilterActor(['it.view', 'it.manage'], $site);
    $saved = ItSavedTicketFilter::query()->create([
        'user_id' => $owner->id,
        'name' => 'Owner only',
        'filters' => ['ticket_status' => 'open'],
    ]);

    $this->actingAs($other)
        ->get("/it?saved_filter={$saved->id}")
        ->assertNotFound();
    $this->actingAs($other)
        ->delete("/it/ticket-filters/{$saved->id}")
        ->assertNotFound();

    expect($saved->fresh())->not->toBeNull();

    $this->actingAs($owner)
        ->delete("/it/ticket-filters/{$saved->id}")
        ->assertRedirect();

    expect($saved->fresh())->toBeNull();
});

test('request-only staff cannot create personal agent queue filters', function () {
    $site = Site::factory()->create();
    $requester = savedTicketFilterActor(['it.request'], $site);

    $this->actingAs($requester)->post('/it/ticket-filters', [
        'name' => 'Not an agent queue',
        'filters' => ['ticket_status' => 'open'],
    ])->assertForbidden();

    expect(ItSavedTicketFilter::query()->count())->toBe(0);
});
