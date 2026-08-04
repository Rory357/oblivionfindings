<?php

use App\Models\Client;
use App\Models\ClientConsent;
use App\Models\ClientIncident;
use App\Models\ClientMedication;
use App\Models\ConsentType;
use App\Models\FamilyPortalSetting;
use App\Models\NextOfKin;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

function grantClientProfilePortalPayloadRole(
    User $user,
    string $roleName,
    array $permissionKeys,
): void {
    $role = Role::query()->firstOrCreate(
        ['name' => $roleName],
        [
            'label' => str($roleName)->headline(),
            'level' => 15,
            'type' => 'system',
        ],
    );

    foreach ($permissionKeys as $key) {
        Permission::query()->firstOrCreate(
            ['key' => $key],
            [
                'description' => $key,
                'group' => 'test',
                'module' => 'Test',
            ],
        );
    }

    $role->permissions()->sync(
        Permission::query()->whereIn('key', $permissionKeys)->pluck('id')->all(),
    );
    $user->roles()->sync([$role->id]);
}

function makeClientProfilePortalNok(
    Client $client,
    array $flags = [],
    array $permissionKeys = ['clients.viewPortal'],
): User {
    $user = User::factory()->create([
        'role' => 'next_of_kin',
        'approved_at' => now(),
    ]);

    grantClientProfilePortalPayloadRole($user, 'next_of_kin', $permissionKeys);

    $client->portalUsers()->attach($user->id, [
        'relation' => 'next_of_kin',
    ]);

    NextOfKin::query()->create([
        'user_id' => $user->id,
        'client_id' => $client->id,
        'relationship' => 'guardian',
        'can_view_medical' => (bool) ($flags['can_view_medical'] ?? false),
        'can_view_medications' => (bool) ($flags['can_view_medications'] ?? false),
        'can_view_incidents' => (bool) ($flags['can_view_incidents'] ?? false),
    ]);

    return $user;
}

/**
 * @return array{medication: ClientMedication}
 */
function seedClientProfilePortalSensitiveData(Client $client): array
{
    $client->medicalProfile()->create([
        'medical_history' => 'Private clinical history',
        'allergies' => ['penicillin'],
    ]);
    $client->conditions()->create([
        'label' => 'Epilepsy',
        'severity' => 'severe',
        'notes' => 'Follow the seizure plan.',
    ]);
    $medication = ClientMedication::factory()->create([
        'client_id' => $client->id,
        'name' => 'Private medication',
        'active' => true,
        'state' => 'active',
    ]);

    return ['medication' => $medication];
}

function grantClientProfilePortalFamilyDisclosure(
    Client $client,
    User $actor,
    array $settings = [],
): void {
    $consentType = ConsentType::factory()->create([
        'name' => 'Information Sharing with Whānau / Family',
        'category' => 'communication',
    ]);
    ClientConsent::query()->create([
        'client_id' => $client->id,
        'consent_type_id' => $consentType->id,
        'status' => 'given',
        'given_at' => now()->subMinute(),
        'expires_at' => now()->addMonth(),
        'given_by_user_id' => $actor->id,
        'given_by_relationship' => 'next_of_kin',
        'given_method' => 'portal',
        'created_by' => $actor->id,
        'updated_by' => $actor->id,
    ]);
    FamilyPortalSetting::query()->create([
        'client_id' => $client->id,
        'show_shift_schedule' => true,
        'show_respite' => true,
        'show_care_notes' => true,
        'show_care_plans' => false,
        'show_medication_status' => false,
        'show_incidents' => false,
        ...$settings,
    ]);
}

it('serves a linked client identity only through portal profile routes', function () {
    $client = Client::factory()->create();
    $portalClient = User::factory()->create([
        'role' => 'client',
        'approved_at' => now(),
    ]);
    grantClientProfilePortalPayloadRole($portalClient, 'client', ['clients.viewPortal']);
    $client->portalUsers()->attach($portalClient->id, ['relation' => 'self']);

    $this->actingAs($portalClient)
        ->get(route('portal.clients.show', $client, false))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('portal/client')
            ->where('client.id', $client->id));

    $this->actingAs($portalClient)
        ->get(route('operations.clients.show', $client, false))
        ->assertForbidden();

    $this->actingAs($portalClient)
        ->get(route('clients.show', $client, false).'?tab=documents')
        ->assertRedirect(route('portal.clients.show', $client, false).'?tab=documents');
});

it('omits medical payloads from portal client when a linked NOK has no health flags', function () {
    $client = Client::factory()->create();
    seedClientProfilePortalSensitiveData($client);
    $nok = makeClientProfilePortalNok($client);

    $this->actingAs($nok)
        ->get(route('portal.clients.show', $client, false))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('portal/client')
            ->missing('profile')
            ->missing('medications')
            ->missing('conditions')
            ->missing('emergency_contacts'));

    $this->actingAs($nok)
        ->get(route('portal.clients.health', $client, false))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('portal/health')
            ->where('medicalProfile', null)
            ->has('medications', 0)
            ->has('conditions', 0)
            ->where('permissions.can_view_medical', false)
            ->where('permissions.can_view_medications', false));
});

it('uses the NOK medical flag independently from medication access on both portal surfaces', function () {
    $client = Client::factory()->create();
    seedClientProfilePortalSensitiveData($client);
    $nok = makeClientProfilePortalNok($client, [
        'can_view_medical' => true,
        'can_view_medications' => false,
    ]);
    grantClientProfilePortalFamilyDisclosure($client, $nok);

    $this->actingAs($nok)
        ->get(route('portal.clients.show', $client, false))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('portal/client')
            ->where('profile.medical_history', 'Private clinical history')
            ->has('conditions', 1)
            ->where('conditions.0.label', 'Epilepsy')
            ->has('emergency_contacts', 0)
            ->missing('medications'));

    $this->actingAs($nok)
        ->get(route('portal.clients.health', $client, false))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('portal/health')
            ->where('medicalProfile.medical_history', 'Private clinical history')
            ->has('conditions', 1)
            ->has('medications', 0)
            ->where('permissions.can_view_medical', true)
            ->where('permissions.can_view_medications', false));
});

it('uses the NOK medication flag independently from medical access on both portal surfaces', function () {
    $client = Client::factory()->create();
    $sensitive = seedClientProfilePortalSensitiveData($client);
    $nok = makeClientProfilePortalNok($client, [
        'can_view_medical' => false,
        'can_view_medications' => true,
    ]);
    grantClientProfilePortalFamilyDisclosure($client, $nok, [
        'show_medication_status' => true,
    ]);

    $this->actingAs($nok)
        ->get(route('portal.clients.show', $client, false))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('portal/client')
            ->missing('profile')
            ->missing('conditions')
            ->missing('emergency_contacts')
            ->has('medications', 1)
            ->where('medications.0.id', $sensitive['medication']->id));

    $this->actingAs($nok)
        ->get(route('portal.clients.health', $client, false))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('portal/health')
            ->where('medicalProfile', null)
            ->has('conditions', 0)
            ->has('medications', 1)
            ->where('medications.0.id', $sensitive['medication']->id)
            ->where('permissions.can_view_medical', false)
            ->where('permissions.can_view_medications', true));
});

it('hides portal-visible incidents when the linked NOK lacks portal incident permission', function () {
    $client = Client::factory()->create();
    $nok = makeClientProfilePortalNok($client);
    ClientIncident::factory()->reviewed()->create([
        'client_id' => $client->id,
        'portal_visible' => true,
    ]);

    $this->actingAs($nok)
        ->get(route('portal.clients.show', $client, false))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('portal/client')
            ->has('incidents', 0)
            ->where('can.viewIncidents', false));
});

it('shows only portal-visible incidents when the linked NOK has portal incident permission', function () {
    $client = Client::factory()->create();
    $nok = makeClientProfilePortalNok(
        $client,
        flags: ['can_view_incidents' => true],
        permissionKeys: ['clients.viewPortal', 'incidents.view.portal'],
    );
    grantClientProfilePortalFamilyDisclosure($client, $nok, [
        'show_incidents' => true,
    ]);
    $visibleIncident = ClientIncident::factory()->reviewed()->create([
        'client_id' => $client->id,
        'portal_visible' => true,
    ]);
    ClientIncident::factory()->reviewed()->create([
        'client_id' => $client->id,
        'portal_visible' => false,
    ]);

    $this->actingAs($nok)
        ->get(route('portal.clients.show', $client, false))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('portal/client')
            ->has('incidents', 1)
            ->where('incidents.0.id', $visibleIncident->id)
            ->where('can.viewIncidents', true));
});

it('forbids a portal identity without an exact link to the requested client', function () {
    $client = Client::factory()->create();
    $nok = User::factory()->create([
        'role' => 'next_of_kin',
        'approved_at' => now(),
    ]);
    grantClientProfilePortalPayloadRole($nok, 'next_of_kin', ['clients.viewPortal']);

    $this->actingAs($nok)
        ->get(route('portal.clients.show', $client, false))
        ->assertForbidden();

    $this->actingAs($nok)
        ->get(route('portal.clients.health', $client, false))
        ->assertForbidden();
});

it('uses exact client linkage rather than Site as the portal direct-object boundary', function () {
    $firstSite = Site::factory()->create();
    $otherSite = Site::factory()->create();
    $firstLinkedClient = Client::factory()->create(['site_id' => $firstSite->id]);
    $otherSiteLinkedClient = Client::factory()->create(['site_id' => $otherSite->id]);
    $unlinkedClient = Client::factory()->create(['site_id' => $otherSite->id]);
    $nok = makeClientProfilePortalNok($firstLinkedClient);
    $otherSiteLinkedClient->portalUsers()->attach($nok->id, [
        'relation' => 'next_of_kin',
    ]);

    $this->actingAs($nok)
        ->get(route('portal.clients.show', $otherSiteLinkedClient, false))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('portal/client')
            ->missing('profile')
            ->missing('medications')
            ->missing('conditions')
            ->missing('emergency_contacts'));

    $this->actingAs($nok)
        ->get(route('portal.clients.health', $otherSiteLinkedClient, false))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('portal/health')
            ->where('medicalProfile', null)
            ->has('medications', 0)
            ->has('conditions', 0)
            ->where('permissions.can_view_medical', false)
            ->where('permissions.can_view_medications', false));

    expect(NextOfKin::query()->where('user_id', $nok->id)->sole()->client_id)
        ->toBe($firstLinkedClient->id);

    $this->actingAs($nok)
        ->get(route('portal.clients.show', $unlinkedClient, false))
        ->assertForbidden();

    $this->actingAs($nok)
        ->get(route('portal.clients.health', $unlinkedClient, false))
        ->assertForbidden();
});
