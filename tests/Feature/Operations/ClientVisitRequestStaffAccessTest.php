<?php

use App\Models\Client;
use App\Models\FamilyVisitRequest;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use Inertia\Testing\AssertableInertia;

/*
 * S13 (design refresh, 20 Oct 2026): the staff visit-request desk and the
 * staff client calendar only checked `view`, which a linked family portal
 * account passes, so family could approve or decline visits on the staff
 * routes. Family keep their own /portal visit-request flow.
 */

beforeEach(function () {
    $this->visitSite = Site::factory()->create(['is_active' => true, 'archived' => false]);
    $this->visitOtherSite = Site::factory()->create(['is_active' => true, 'archived' => false]);
    $this->visitClient = Client::factory()->create(['site_id' => $this->visitSite->id]);
});

function grantVisitRequestStaffRole(User $user, string $roleName, array $permissionKeys): void
{
    $role = Role::query()->firstOrCreate(
        ['name' => $roleName],
        ['label' => $roleName, 'level' => 50, 'type' => 'custom'],
    );

    foreach ($permissionKeys as $key) {
        Permission::query()->firstOrCreate(
            ['key' => $key],
            ['description' => $key, 'group' => 'test', 'module' => 'Test'],
        );
    }

    $role->permissions()->syncWithoutDetaching(
        Permission::query()->whereIn('key', $permissionKeys)->pluck('id')->all(),
    );
    $user->roles()->syncWithoutDetaching([$role->id]);
}

function makeVisitRequestStaff(Site $site, array $permissionKeys, string $roleName = 'visit_request_staff'): User
{
    $user = User::factory()->create([
        'role' => $roleName === 'support_worker' ? 'support_worker' : 'coordinator',
        'approved_at' => now(),
    ]);
    grantVisitRequestStaffRole($user, $roleName.'_'.$user->id, $permissionKeys);
    if ($roleName === 'support_worker') {
        grantVisitRequestStaffRole($user, 'support_worker', []);
    }
    ensureCanonicalHrStaffProfile($user, $site);

    return $user;
}

function makeVisitRequestPortalUser(Client $client): User
{
    $user = User::factory()->create(['role' => 'next_of_kin', 'approved_at' => now()]);
    grantVisitRequestStaffRole($user, 'next_of_kin', ['clients.viewPortal']);
    $client->portalUsers()->attach($user->id, ['relation' => 'next_of_kin']);

    return $user;
}

function makePendingVisitRequest(Client $client, User $requester): FamilyVisitRequest
{
    return FamilyVisitRequest::query()->create([
        'user_id' => $requester->id,
        'client_id' => $client->id,
        'requested_date' => now()->addDays(3)->toDateString(),
        'visit_type' => 'in_person',
        'status' => 'pending',
    ]);
}

it('refuses a linked family portal account on every staff visit-request route and the staff calendar', function () {
    $client = $this->visitClient;
    $portalUser = makeVisitRequestPortalUser($client);
    $visit = makePendingVisitRequest($client, $portalUser);

    // The portal account does reach the person through `view`; that is what
    // let it through before, so the refusal below is the new staff gate.
    expect($portalUser->can('view', $client))->toBeTrue();

    $this->actingAs($portalUser)
        ->get("/operations/clients/{$client->id}/visit-requests")
        ->assertForbidden();
    $this->actingAs($portalUser)
        ->post("/operations/clients/{$client->id}/visit-requests/{$visit->id}/approve")
        ->assertForbidden();
    $this->actingAs($portalUser)
        ->post("/operations/clients/{$client->id}/visit-requests/{$visit->id}/decline")
        ->assertForbidden();
    $this->actingAs($portalUser)
        ->get("/operations/clients/{$client->id}/calendar")
        ->assertForbidden();

    expect($visit->fresh()->status)->toBe('pending')
        ->and($visit->fresh()->reviewed_by)->toBeNull();
});

it('keeps the family portal visit-request flow working for the linked account', function () {
    $client = $this->visitClient;
    $portalUser = makeVisitRequestPortalUser($client);
    $visit = makePendingVisitRequest($client, $portalUser);

    $this->actingAs($portalUser)
        ->post("/portal/clients/{$client->id}/visit-requests/{$visit->id}/cancel")
        ->assertRedirect();

    expect($visit->fresh()->status)->toBe('cancelled');
});

it('lets family portal staff at the Site list, approve and decline visit requests', function () {
    $client = $this->visitClient;
    $family = makeVisitRequestPortalUser($client);
    $first = makePendingVisitRequest($client, $family);
    $second = makePendingVisitRequest($client, $family);
    $staff = makeVisitRequestStaff($this->visitSite, ['clients.viewAny', 'family_portal.viewAny', 'family_portal.manage']);

    $this->actingAs($staff)
        ->get("/operations/clients/{$client->id}/visit-requests")
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('operations/clients/visit-requests')
            ->where('can_review', true)
            ->where('stats.pending', 2));

    $this->actingAs($staff)
        ->get("/operations/clients/{$client->id}/calendar")
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('operations/clients/calendar')
            ->where('can_view_visit_requests', true)
            ->where('pending_visit_count', 2));

    $this->actingAs($staff)
        ->post("/operations/clients/{$client->id}/visit-requests/{$first->id}/approve", ['review_notes' => 'See you Saturday.'])
        ->assertRedirect();
    $this->actingAs($staff)
        ->post("/operations/clients/{$client->id}/visit-requests/{$second->id}/decline")
        ->assertRedirect();

    expect($first->fresh()->status)->toBe('approved')
        ->and($first->fresh()->reviewed_by)->toBe($staff->id)
        ->and($second->fresh()->status)->toBe('declined')
        ->and($second->fresh()->reviewed_by)->toBe($staff->id);
});

it('lets an assigned support worker review visit requests for the person they support', function () {
    $client = $this->visitClient;
    $family = makeVisitRequestPortalUser($client);
    $visit = makePendingVisitRequest($client, $family);
    $worker = makeVisitRequestStaff(
        $this->visitSite,
        ['clients.viewAssigned', 'progress_notes.viewAny', 'progress_notes.create'],
        'support_worker',
    );
    $client->supportWorkers()->attach($worker->id);

    $this->actingAs($worker)
        ->post("/operations/clients/{$client->id}/visit-requests/{$visit->id}/approve")
        ->assertRedirect();

    expect($visit->fresh()->status)->toBe('approved');
});

it('shows visit requests read-only to family portal viewers without manage rights', function () {
    $client = $this->visitClient;
    $family = makeVisitRequestPortalUser($client);
    $visit = makePendingVisitRequest($client, $family);
    $viewer = makeVisitRequestStaff($this->visitSite, ['clients.viewAny', 'family_portal.viewAny']);

    $this->actingAs($viewer)
        ->get("/operations/clients/{$client->id}/visit-requests")
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->where('can_review', false));
    $this->actingAs($viewer)
        ->post("/operations/clients/{$client->id}/visit-requests/{$visit->id}/approve")
        ->assertForbidden();

    expect($visit->fresh()->status)->toBe('pending');
});

it('keeps the staff calendar for client viewers but hides visit requests without family capabilities', function () {
    $client = $this->visitClient;
    makePendingVisitRequest($client, makeVisitRequestPortalUser($client));
    $viewer = makeVisitRequestStaff($this->visitSite, ['clients.viewAny']);

    $this->actingAs($viewer)
        ->get("/operations/clients/{$client->id}/calendar")
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('can_view_visit_requests', false)
            ->where('pending_visit_count', 0));
    $this->actingAs($viewer)
        ->get("/operations/clients/{$client->id}/visit-requests")
        ->assertForbidden();
});

it('refuses family portal staff from another Site', function () {
    $client = $this->visitClient;
    $family = makeVisitRequestPortalUser($client);
    $visit = makePendingVisitRequest($client, $family);
    $foreign = makeVisitRequestStaff($this->visitOtherSite, ['clients.viewAny', 'family_portal.viewAny', 'family_portal.manage']);

    $this->actingAs($foreign)
        ->get("/operations/clients/{$client->id}/visit-requests")
        ->assertForbidden();
    $this->actingAs($foreign)
        ->post("/operations/clients/{$client->id}/visit-requests/{$visit->id}/approve")
        ->assertForbidden();
    $this->actingAs($foreign)
        ->post("/operations/clients/{$client->id}/visit-requests/{$visit->id}/decline")
        ->assertForbidden();
    $this->actingAs($foreign)
        ->get("/operations/clients/{$client->id}/calendar")
        ->assertForbidden();

    expect($visit->fresh()->status)->toBe('pending');
});
