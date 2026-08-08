<?php

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Models\AppSetting;
use App\Models\Client;
use App\Models\Role;
use App\Models\ServiceContext;
use App\Models\Site;
use App\Models\SiteContact;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RbacSeeder::class);
    $this->visibleSite = Site::factory()->create([
        'name' => 'Visible Site',
        'is_active' => true,
        'archived' => false,
        'archived_at' => null,
    ]);
    $this->hiddenSite = Site::factory()->create([
        'name' => 'Hidden Site',
        'is_active' => true,
        'archived' => false,
        'archived_at' => null,
    ]);
});

function siteProfileCurrentStaff(
    string $name,
    Site $site,
    string $role = 'team_lead',
    array $profileOverrides = [],
    array $userOverrides = [],
): User {
    $user = User::factory()->create([
        'name' => $name,
        'email' => str($name)->slug().'-'.str()->random(6).'@example.test',
        'role' => $role,
        'approved_at' => now(),
        ...$userOverrides,
    ]);
    $roleModel = Role::query()->where('name', $role)->first();
    if ($roleModel) {
        $user->roles()->sync([$roleModel->id]);
    }
    HrEmployeeProfile::factory()->create([
        'user_id' => $user->id,
        'primary_site_id' => $site->id,
        'secondary_site_ids' => [],
        'start_date' => today()->subYear(),
        'end_date' => null,
        'is_active' => true,
        ...$profileOverrides,
    ]);

    return $user->fresh(['roles', 'hrEmployeeProfile']);
}

function siteProfileContact(Site $site, string $name, array $overrides = []): SiteContact
{
    return SiteContact::query()->create([
        'site_id' => $site->id,
        'type' => 'site_lead',
        'name' => $name,
        'is_primary' => false,
        ...$overrides,
    ]);
}

function siteProfileUpdatePayload(Site $site, array $overrides = []): array
{
    return [
        'name' => $site->name,
        'type' => $site->type,
        'is_active' => (bool) $site->is_active,
        ...$overrides,
    ];
}

test('Site browsing is fail closed while explicit application wide access remains available', function (): void {
    $scoped = siteProfileCurrentStaff('Scoped Site lead', $this->visibleSite);
    $unassigned = User::factory()->create([
        'name' => 'Unassigned Site lead',
        'role' => 'team_lead',
        'approved_at' => now(),
    ]);
    $unassigned->roles()->sync([Role::query()->where('name', 'team_lead')->firstOrFail()->id]);
    $provider = siteProfileCurrentStaff('Provider manager', $this->visibleSite, 'provider_manager');

    $scopedResponse = $this->actingAs($scoped)->get(route('sites.index'))->assertOk();
    expect(collect($scopedResponse->inertiaProps('sites'))->pluck('id')->all())
        ->toBe([$this->visibleSite->id]);
    $this->actingAs($scoped)->get(route('sites.show', $this->hiddenSite))->assertForbidden();

    $unassignedResponse = $this->actingAs($unassigned)->get(route('sites.index'))->assertOk();
    expect(collect($unassignedResponse->inertiaProps('sites')))->toBeEmpty();
    $this->actingAs($unassigned)->get(route('sites.show', $this->visibleSite))->assertForbidden();

    expect($provider->canDo('sites.viewAll'))->toBeTrue();
    $providerResponse = $this->actingAs($provider)->get(route('sites.index'))->assertOk();
    expect(collect($providerResponse->inertiaProps('sites'))->pluck('id'))
        ->toContain($this->visibleSite->id, $this->hiddenSite->id);
    $this->actingAs($provider)->get(route('sites.show', $this->hiddenSite))->assertOk();
});

test('Site Profile exposes only current visible staff and separates responsible staff from Site contacts', function (): void {
    $manager = siteProfileCurrentStaff('Site Profile manager', $this->visibleSite);
    $visibleStaff = siteProfileCurrentStaff('Visible worker', $this->visibleSite, 'support_worker');
    $hiddenStaff = siteProfileCurrentStaff('Hidden worker', $this->hiddenSite, 'support_worker');
    $formerStaff = siteProfileCurrentStaff('Former worker', $this->visibleSite, 'support_worker', [
        'is_active' => false,
        'end_date' => today()->subDay(),
    ]);
    $unapprovedStaff = siteProfileCurrentStaff(
        'Unapproved worker',
        $this->visibleSite,
        'support_worker',
        [],
        ['approved_at' => null],
    );

    $response = $this->actingAs($manager)
        ->get(route('sites.edit', $this->visibleSite))
        ->assertOk();
    $userIds = collect($response->inertiaProps('users'))->pluck('id');
    expect($userIds)
        ->toContain($manager->id, $visibleStaff->id)
        ->not->toContain($hiddenStaff->id, $formerStaff->id, $unapprovedStaff->id);

    $this->actingAs($manager)
        ->put(route('sites.update', $this->visibleSite), siteProfileUpdatePayload($this->visibleSite, [
            'name' => 'Rejected responsible staff change',
            'primary_contact_user_id' => $hiddenStaff->id,
        ]))
        ->assertSessionHasErrors('primary_contact_user_id');
    expect($this->visibleSite->fresh()->name)->toBe('Visible Site');

    $this->actingAs($manager)
        ->put(route('sites.update', $this->visibleSite), siteProfileUpdatePayload($this->visibleSite, [
            'primary_contact_user_id' => $visibleStaff->id,
        ]))
        ->assertRedirect(route('sites.show', $this->visibleSite));

    $this->actingAs($manager)
        ->get(route('sites.show', $this->visibleSite))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('site.primary_contact.id', $visibleStaff->id)
            ->where('site.primary_contact.name', 'Visible worker'));
});

test('full Site updates reject foreign contact IDs and multiple primaries without partial mutation', function (): void {
    $manager = siteProfileCurrentStaff('Atomic Site manager', $this->visibleSite);
    $visibleContact = siteProfileContact($this->visibleSite, 'Visible contact', ['is_primary' => true]);
    $hiddenContact = siteProfileContact($this->hiddenSite, 'Hidden contact');

    $this->actingAs($manager)
        ->put(route('sites.update', $this->visibleSite), siteProfileUpdatePayload($this->visibleSite, [
            'name' => 'Must roll back',
            'contacts' => [[
                'id' => $hiddenContact->id,
                'type' => 'manager',
                'name' => 'Foreign contact mutation',
                'is_primary' => true,
            ]],
        ]))
        ->assertSessionHasErrors('contacts.0.id');

    expect($this->visibleSite->fresh()->name)->toBe('Visible Site')
        ->and($visibleContact->fresh()->name)->toBe('Visible contact')
        ->and($hiddenContact->fresh()->name)->toBe('Hidden contact');

    $this->actingAs($manager)
        ->put(route('sites.update', $this->visibleSite), siteProfileUpdatePayload($this->visibleSite, [
            'name' => 'Still rolls back',
            'contacts' => [
                [
                    'id' => $visibleContact->id,
                    'type' => 'site_lead',
                    'name' => 'Visible contact',
                    'is_primary' => true,
                ],
                [
                    'type' => 'manager',
                    'name' => 'Second primary',
                    'is_primary' => true,
                ],
            ],
        ]))
        ->assertSessionHasErrors('contacts');

    expect($this->visibleSite->fresh()->name)->toBe('Visible Site')
        ->and(SiteContact::query()->where('site_id', $this->visibleSite->id)->count())->toBe(1);
});

test('inline contact management shares the canonical identity and primary rules', function (): void {
    $manager = siteProfileCurrentStaff('Contact manager', $this->visibleSite);
    $first = siteProfileContact($this->visibleSite, 'First contact', ['is_primary' => true]);
    $foreign = siteProfileContact($this->hiddenSite, 'Foreign contact');

    $this->actingAs($manager)
        ->put(route('sites.contacts.update', [$this->visibleSite, $foreign]), [
            'type' => 'manager',
            'name' => 'Wrong Site update',
            'is_primary' => true,
        ])
        ->assertNotFound();
    expect($foreign->fresh()->name)->toBe('Foreign contact');

    $this->actingAs($manager)
        ->post(route('sites.contacts.store', $this->visibleSite), [
            'type' => 'manager',
            'name' => 'New primary',
            'is_primary' => true,
        ])
        ->assertRedirect();

    expect($first->fresh()->is_primary)->toBeFalse()
        ->and(SiteContact::query()
            ->where('site_id', $this->visibleSite->id)
            ->where('is_primary', true)
            ->sole()
            ->name)->toBe('New primary');

    $this->actingAs($manager)
        ->post(route('sites.contacts.store', $this->visibleSite), [
            'type' => 'manager',
            'name' => 'new PRIMARY',
            'is_primary' => false,
        ])
        ->assertSessionHasErrors('name');
});

test('client linking candidates and mutations require explicit Client permissions', function (): void {
    $scopedManager = siteProfileCurrentStaff('Scoped client manager', $this->visibleSite);
    $provider = siteProfileCurrentStaff('Application client manager', $this->visibleSite, 'provider_manager');
    $unassigned = Client::factory()->create([
        'first_name' => 'Unassigned',
        'last_name' => 'Person',
        'site_id' => null,
    ]);
    $assigned = Client::factory()->create([
        'first_name' => 'Already',
        'last_name' => 'Assigned',
        'site_id' => $this->hiddenSite->id,
    ]);

    $scopedResponse = $this->actingAs($scopedManager)
        ->get(
            route('sites.show', $this->visibleSite),
            $this->inertiaPartialHeaders('sites/show', 'clientsData'),
        )
        ->assertOk();
    expect($scopedResponse->json('props.clientsData.available'))->toBeEmpty()
        ->and($scopedResponse->json('props.clientsData.can_place_existing'))->toBeFalse()
        ->and($scopedResponse->json('props.clientsData.can_create'))->toBeFalse();

    $this->actingAs($scopedManager)
        ->post(route('sites.clients.link', $this->visibleSite), ['client_id' => $unassigned->id])
        ->assertForbidden();
    expect($unassigned->fresh()->site_id)->toBeNull();

    $providerResponse = $this->actingAs($provider)
        ->get(
            route('sites.show', $this->visibleSite),
            $this->inertiaPartialHeaders('sites/show', 'clientsData'),
        )
        ->assertOk();
    expect(collect($providerResponse->json('props.clientsData.available'))->pluck('id'))
        ->toContain($unassigned->id)
        ->not->toContain($assigned->id)
        ->and($providerResponse->json('props.clientsData.can_place_existing'))->toBeTrue()
        ->and($providerResponse->json('props.clientsData.can_create'))->toBeTrue();

    $this->actingAs($provider)
        ->post(route('sites.clients.link', $this->visibleSite), ['client_id' => $assigned->id])
        ->assertSessionHasErrors('client_id');
    expect($assigned->fresh()->site_id)->toBe($this->hiddenSite->id);

    $this->actingAs($provider)
        ->post(route('sites.clients.link', $this->visibleSite), ['client_id' => $unassigned->id])
        ->assertRedirect();
    expect($unassigned->fresh()->site_id)->toBe($this->visibleSite->id);
});

test('Site quick-create accepts only active service contexts valid for that Site', function (): void {
    $provider = siteProfileCurrentStaff('Site client creator', $this->visibleSite, 'provider_manager');
    $foreignDefault = ServiceContext::query()->create([
        'type' => 'residential',
        'name' => 'Hidden Site residential service',
        'site_id' => $this->hiddenSite->id,
        'is_active' => true,
    ]);
    $inactiveLocal = ServiceContext::query()->create([
        'type' => 'home_support',
        'name' => 'Retired local service',
        'site_id' => $this->visibleSite->id,
        'is_active' => false,
    ]);
    AppSetting::query()->updateOrCreate(
        ['key' => 'service_context.default_id'],
        ['value' => $foreignDefault->id],
    );

    $this->actingAs($provider)
        ->post(route('clients.store'), [
            '_modal' => true,
            'site_id' => $this->visibleSite->id,
            'first_name' => 'Canonical',
            'last_name' => 'Client',
            'status' => 'onboarding',
        ])
        ->assertRedirect();
    $this->assertDatabaseHas('clients', [
        'first_name' => 'Canonical',
        'last_name' => 'Client',
        'site_id' => $this->visibleSite->id,
        'service_context_id' => null,
    ]);

    $this->actingAs($provider)
        ->post(route('clients.store'), [
            '_modal' => true,
            'site_id' => $this->visibleSite->id,
            'first_name' => 'Rejected',
            'last_name' => 'Context',
            'status' => 'onboarding',
            'service_context_id' => $inactiveLocal->id,
        ])
        ->assertSessionHasErrors('service_context_id');
    $this->assertDatabaseMissing('clients', [
        'first_name' => 'Rejected',
        'last_name' => 'Context',
    ]);
});
