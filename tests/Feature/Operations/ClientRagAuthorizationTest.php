<?php

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Models\Client;
use App\Models\NextOfKin;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

function grantClientRagTestRole(User $user, string $roleName, array $permissions): void
{
    $role = Role::query()->firstOrCreate(
        ['name' => $roleName],
        [
            'label' => str($roleName)->headline(),
            'level' => 50,
            'type' => in_array($roleName, ['client', 'next_of_kin', 'support_worker'], true)
                ? 'system'
                : 'custom',
        ],
    );

    foreach ($permissions as $permissionKey) {
        Permission::query()->firstOrCreate(
            ['key' => $permissionKey],
            [
                'description' => $permissionKey,
                'group' => 'test',
                'module' => 'Test',
            ],
        );
    }

    $role->permissions()->sync(
        Permission::query()->whereIn('key', $permissions)->pluck('id')->all(),
    );
    $user->roles()->sync([$role->id]);
}

function makeClientRagTestSite(string $name): Site
{
    return Site::factory()->create([
        'name' => $name,
        'is_active' => true,
        'archived' => false,
        'archived_at' => null,
    ]);
}

function makeClientRagTestClient(Site $site): Client
{
    return Client::factory()->create([
        'site_id' => $site->id,
        'status' => 'active',
    ]);
}

function makeClientRagTestStaff(
    Site $site,
    string $roleName,
    array $permissions,
    array $attributes = [],
): User {
    $staff = User::factory()->create([
        'role' => 'support_worker',
        'approved_at' => now(),
        ...$attributes,
    ]);
    grantClientRagTestRole($staff, $roleName, $permissions);
    HrEmployeeProfile::factory()->create([
        'user_id' => $staff->id,
        'position_role' => $staff->role,
        'primary_site_id' => $site->id,
        'secondary_site_ids' => [],
        'start_date' => today()->subYear(),
        'end_date' => null,
        'is_active' => true,
        'created_by' => $staff->id,
        'updated_by' => $staff->id,
    ]);

    return $staff;
}

it('denies the unredacted client RAG endpoint to next of kin even with the generic self permission', function () {
    config()->set('llm.openai.api_key', null);

    $client = makeClientRagTestClient(makeClientRagTestSite('Kauri House'));
    $nextOfKin = User::factory()->create([
        'role' => 'next_of_kin',
        'approved_at' => now(),
    ]);
    grantClientRagTestRole($nextOfKin, 'next_of_kin', [
        'clients.viewPortal',
        'rag.ask.self',
    ]);
    $client->portalUsers()->attach($nextOfKin->id, ['relation' => 'next_of_kin']);
    NextOfKin::query()->create([
        'client_id' => $client->id,
        'user_id' => $nextOfKin->id,
        'relationship' => 'guardian',
        'can_view_medical' => true,
        'can_view_medications' => true,
        'can_view_incidents' => true,
    ]);

    $this->actingAs($nextOfKin)
        ->get(route('portal.clients.show', $client, false))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('can.askRag', false));

    $this->actingAs($nextOfKin)
        ->post(route('portal.clients.rag.ask', $client, false), [
            'question' => 'Tell me every private clinical detail.',
        ])
        ->assertForbidden();
});

it('requires a RAG capability in addition to ordinary client profile access', function () {
    config()->set('llm.openai.api_key', null);

    $site = makeClientRagTestSite('Rimu House');
    $client = makeClientRagTestClient($site);
    $viewer = makeClientRagTestStaff($site, 'rag_profile_viewer_'.uniqid(), [
        'clients.viewAssigned',
    ]);
    $client->supportWorkers()->attach($viewer->id);

    $this->actingAs($viewer)
        ->post(route('operations.clients.rag.ask', $client, false), [
            'question' => 'Summarise this client.',
        ])
        ->assertForbidden();
});

it('allows an assigned worker with the assigned RAG capability to reach the disabled-provider response', function () {
    config()->set('llm.openai.api_key', null);

    $site = makeClientRagTestSite('Totara House');
    $client = makeClientRagTestClient($site);
    $worker = makeClientRagTestStaff($site, 'rag_assigned_worker_'.uniqid(), [
        'clients.viewAssigned',
        'rag.ask.assigned',
    ]);
    $client->supportWorkers()->attach($worker->id);

    $this->actingAs($worker)
        ->from(route('operations.clients.show', $client, false))
        ->post(route('operations.clients.rag.ask', $client, false), [
            'question' => 'What changed today?',
        ])
        ->assertRedirect(route('operations.clients.show', $client, false))
        ->assertSessionHasErrors('question');
});

it('allows a manager with the any-client RAG capability and validates the question', function () {
    config()->set('llm.openai.api_key', null);

    $site = makeClientRagTestSite('Pohutukawa House');
    $client = makeClientRagTestClient($site);
    $manager = makeClientRagTestStaff(
        $site,
        'rag_manager_'.uniqid(),
        ['clients.viewAny', 'rag.ask.any'],
        ['role' => 'manager'],
    );

    $this->actingAs($manager)
        ->from(route('operations.clients.show', $client, false))
        ->post(route('operations.clients.rag.ask', $client, false), [
            'question' => '',
        ])
        ->assertRedirect(route('operations.clients.show', $client, false))
        ->assertSessionHasErrors('question');
});

it('denies assigned RAG access when the assigned client is outside the worker Site', function () {
    config()->set('llm.openai.api_key', null);

    $workerSite = makeClientRagTestSite('Nikau House');
    $outsideClient = makeClientRagTestClient(makeClientRagTestSite('Manuka House'));
    $worker = makeClientRagTestStaff($workerSite, 'rag_site_bound_worker_'.uniqid(), [
        'clients.viewAssigned',
        'rag.ask.assigned',
    ]);
    $outsideClient->supportWorkers()->attach($worker->id);

    $this->actingAs($worker)
        ->post(route('operations.clients.rag.ask', $outsideClient, false), [
            'question' => 'What changed today?',
        ])
        ->assertForbidden();
});

it('allows the client viewing their own linked record with the self RAG capability', function () {
    config()->set('llm.openai.api_key', null);

    $site = makeClientRagTestSite('Harakeke House');
    $client = makeClientRagTestClient($site);
    $otherClient = makeClientRagTestClient($site);
    $portalClient = User::factory()->create([
        'role' => 'client',
        'approved_at' => now(),
    ]);
    grantClientRagTestRole($portalClient, 'client', [
        'clients.viewPortal',
        'rag.ask.self',
    ]);
    $client->portalUsers()->attach($portalClient->id, ['relation' => 'self']);

    $this->actingAs($portalClient)
        ->get(route('portal.clients.show', $client, false))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('can.askRag', true));

    $this->actingAs($portalClient)
        ->from(route('portal.clients.show', $client, false))
        ->post(route('portal.clients.rag.ask', $client, false), [
            'question' => 'What is in my own record?',
        ])
        ->assertRedirect(route('portal.clients.show', $client, false))
        ->assertSessionHasErrors('question');

    $this->actingAs($portalClient)
        ->post(route('portal.clients.rag.ask', $otherClient, false), [
            'question' => 'What is in the other record?',
        ])
        ->assertForbidden();
});
