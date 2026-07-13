<?php

use App\Models\Client;
use App\Models\NextOfKin;
use App\Models\Permission;
use App\Models\Role;
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

it('denies the unredacted client RAG endpoint to next of kin even with the generic self permission', function () {
    config()->set('llm.openai.api_key', null);

    $client = Client::factory()->create(['organization_id' => 1]);
    $nextOfKin = User::factory()->create([
        'organization_id' => 1,
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

    $client = Client::factory()->create(['organization_id' => 1]);
    $viewer = User::factory()->create(['organization_id' => 1]);
    grantClientRagTestRole($viewer, 'rag_profile_viewer_'.$viewer->id, [
        'clients.viewAny',
    ]);

    $this->actingAs($viewer)
        ->post(route('operations.clients.rag.ask', $client, false), [
            'question' => 'Summarise this client.',
        ])
        ->assertForbidden();
});

it('allows an assigned worker with the assigned RAG capability to reach the disabled-provider response', function () {
    config()->set('llm.openai.api_key', null);

    $client = Client::factory()->create(['organization_id' => 1]);
    $worker = User::factory()->create([
        'organization_id' => 1,
        'role' => 'support_worker',
    ]);
    grantClientRagTestRole($worker, 'support_worker', [
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

it('allows the client viewing their own linked record with the self RAG capability', function () {
    config()->set('llm.openai.api_key', null);

    $client = Client::factory()->create(['organization_id' => 1]);
    $portalClient = User::factory()->create([
        'organization_id' => 1,
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
});
