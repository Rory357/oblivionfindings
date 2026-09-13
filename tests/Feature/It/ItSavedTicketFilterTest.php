<?php

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Domain\It\Services\ItSavedTicketFilterService;
use App\Models\ItSavedTicketFilter;
use App\Models\ItService;
use App\Models\ItTicket;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;

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

test('one filter normalizer never reuses another actors permitted options', function () {
    $site = Site::factory()->create();
    $otherSite = Site::factory()->create();
    $first = savedTicketFilterActor(['it.view', 'it.manage'], $site);
    $second = savedTicketFilterActor(['it.view', 'it.manage'], $otherSite);
    $normalizer = app(ItSavedTicketFilterService::class);

    expect($normalizer->sanitize($first, ['site_id' => $site->id, 'assignee' => $first->id]))
        ->toBe(['site_id' => $site->id, 'assignee' => $first->id]);
    expect($normalizer->sanitize($second, ['site_id' => $site->id, 'assignee' => $first->id]))
        ->toBe([]);
    expect($normalizer->sanitize($second, ['site_id' => $otherSite->id, 'assignee' => $second->id]))
        ->toBe(['site_id' => $otherSite->id, 'assignee' => $second->id]);
});

test('a reused filter normalizer drops revoked Site and retired service options before applying or listing saved views', function () {
    $oldSite = Site::factory()->create();
    $currentSite = Site::factory()->create();
    $owner = savedTicketFilterActor(['it.view', 'it.manage'], $oldSite);
    $service = ItService::factory()->create(['is_active' => true]);
    $saved = ItSavedTicketFilter::query()->create([
        'user_id' => $owner->id, 'name' => 'Previous scope',
        'filters' => ['ticket_status' => 'open', 'site_id' => $oldSite->id, 'service' => $service->id],
    ]);
    $normalizer = app(ItSavedTicketFilterService::class);
    expect($normalizer->sanitize($owner, $saved->filters))->toBe($saved->filters);

    HrEmployeeProfile::query()->where('user_id', $owner->id)->update(['primary_site_id' => $currentSite->id]);
    $service->update(['is_active' => false]);

    expect($normalizer->sanitize($owner, $saved->filters))->toBe(['ticket_status' => 'open']);
    expect($normalizer->ownedRows($owner))->toBe([['id' => $saved->id, 'name' => 'Previous scope']]);
    expect($saved->fresh()->filters)->toBe(['ticket_status' => 'open']);
});

test('personal ticket totals match all own rows independently from the agent queue filter', function () {
    $site = Site::factory()->create();
    $outside = Site::factory()->create();
    $actor = savedTicketFilterActor(['it.request', 'it.view', 'it.manage'], $site);
    ItTicket::factory()->create(['site_id' => $site->id, 'requester_user_id' => $actor->id, 'status' => 'open']);
    ItTicket::factory()->create(['site_id' => $outside->id, 'requested_for_user_id' => $actor->id, 'status' => 'waiting']);
    ItTicket::factory()->create([
        'site_id' => $site->id, 'requester_user_id' => $actor->id, 'requested_for_user_id' => $actor->id,
        'status' => 'closed', 'resolved_at' => now()->subDays(60),
    ]);
    ItTicket::factory()->create(['site_id' => $site->id, 'status' => 'open']);

    $this->actingAs($actor)->get('/it?tab=tickets&q=No%20matching%20ticket')
        ->assertOk()->assertInertia(fn ($page) => $page
        ->where('summary.my.total', 3)->where('summary.my.open', 2)
        ->where('summary.my.waiting', 1)->where('summary.my.resolved_30d', 0)
        ->has('myTickets', 3)->where('tickets.total', 0));
});

test('operational view counts and rows agree without exposing participant-only waiting or ownership', function (string $view, array $attributes) {
    $site = Site::factory()->create();
    $actor = savedTicketFilterActor(['it.request', 'it.view', 'it.manage'], $site);
    $allowed = ItTicket::factory()->create(['site_id' => $site->id, ...$attributes]);
    ItTicket::factory()->create(['site_id' => $site->id, 'requester_user_id' => $actor->id, 'is_sensitive' => true, ...$attributes]);
    ItTicket::factory()->create(['site_id' => Site::factory()->create()->id, 'requested_for_user_id' => $actor->id, ...$attributes]);
    ItTicket::factory()->create(['site_id' => Site::factory()->create()->id, ...$attributes]);

    $this->actingAs($actor)->get('/it?tab=tickets&view='.$view)->assertOk()
        ->assertInertia(fn ($page) => $page->where('filters.view', $view)
            ->where('summary.tickets.views.'.$view, 1)->where('tickets.total', 1)->where('tickets.data.0.id', $allowed->id));
    $reader = savedTicketFilterActor(['it.view'], $site);
    $this->actingAs($reader)->get('/it?tab=tickets&view='.$view)->assertOk()
        ->assertInertia(fn ($page) => $page->where('summary.tickets.views.'.$view, 0)->where('tickets.total', 0));
})->with([
    ['unowned', ['status' => 'open', 'owner_user_id' => null]],
    ['waiting_requester', ['status' => 'waiting', 'waiting_party' => 'requester']],
    ['waiting_vendor', ['status' => 'waiting', 'waiting_party' => 'vendor']],
    ['waiting_approver', ['status' => 'waiting', 'waiting_party' => 'approver']],
]);

test('canonical saved view creation enforces the current count and database name comparison after request validation', function () {
    $actor = savedTicketFilterActor(['it.view'], Site::factory()->create());
    $store = app(ItSavedTicketFilterService::class);
    foreach (range(1, 24) as $number) {
        ItSavedTicketFilter::query()->create(['user_id' => $actor->id, 'name' => 'View '.$number, 'filters' => ['ticket_status' => 'open']]);
    }
    $last = $store->store($actor, '  Final view  ', ['ticket_status' => 'waiting']);
    expect($last->name)->toBe('Final view');
    expect(fn () => $store->store($actor, 'final VIEW', ['ticket_status' => 'open']))
        ->toThrow(ValidationException::class, 'You already have a ticket view with this name.');
    expect(fn () => $store->store($actor, 'View twenty six', ['ticket_status' => 'open']))
        ->toThrow(ValidationException::class, 'You can keep up to 25 personal ticket filters.');
    expect(ItSavedTicketFilter::query()->where('user_id', $actor->id)->count())->toBe(25);

    $other = savedTicketFilterActor(['it.view'], Site::factory()->create());
    expect($store->store($other, 'Final view', ['ticket_status' => 'open'])->user_id)->toBe($other->id);
});

test('canonical saved view creation reauthorizes a previously loaded actor and rejects an empty normalized view', function () {
    $site = Site::factory()->create();
    $actor = savedTicketFilterActor(['it.view'], $site);
    $actor->load('roles.permissions', 'permissionOverrides');
    expect($actor->canDo('it.view'))->toBeTrue();
    $store = app(ItSavedTicketFilterService::class);
    $hiddenSite = Site::factory()->create();
    expect(fn () => $store->store($actor, 'Unavailable Site', ['site_id' => $hiddenSite->id]))
        ->toThrow(ValidationException::class, 'Choose at least one ticket filter');

    $permission = Permission::query()->where('key', 'it.view')->firstOrFail();
    $actor->permissionOverrides()->syncWithoutDetaching([$permission->id => ['allowed' => false]]);
    expect(fn () => $store->store($actor, 'Old session', ['ticket_status' => 'open']))
        ->toThrow(AuthorizationException::class);
    expect(ItSavedTicketFilter::query()->where('user_id', $actor->id)->count())->toBe(0);
});
