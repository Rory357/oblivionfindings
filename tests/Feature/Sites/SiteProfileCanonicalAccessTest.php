<?php

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Models\AppSetting;
use App\Models\AuditLog;
use App\Models\Client;
use App\Models\Permission;
use App\Models\Role;
use App\Models\ServiceContext;
use App\Models\Site;
use App\Models\SiteContact;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RbacSeeder::class);
    $this->visibleSite = Site::factory()->create([
        'name' => 'Visible Site',
        'type' => 'house',
        'is_active' => true,
        'archived' => false,
        'archived_at' => null,
    ]);
    $this->hiddenSite = Site::factory()->create([
        'name' => 'Hidden Site',
        'type' => 'facility',
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

/**
 * @param  list<string>  $permissionKeys
 * @param  list<int>  $secondarySiteIds
 */
function siteRbacBoundaryActor(
    array $permissionKeys,
    ?Site $primarySite = null,
    array $secondarySiteIds = [],
): User {
    $user = User::factory()->create([
        'role' => 'team_lead',
        'approved_at' => now(),
    ]);
    $role = Role::query()->create([
        'name' => 'site_rbac_boundary_'.$user->id,
        'label' => 'Site RBAC boundary actor',
        'level' => 40,
        'type' => 'custom',
    ]);
    $role->permissions()->sync(
        Permission::query()->whereIn('key', $permissionKeys)->pluck('id')->all(),
    );
    $user->roles()->sync([$role->id]);

    if ($primarySite) {
        HrEmployeeProfile::factory()->create([
            'user_id' => $user->id,
            'primary_site_id' => $primarySite->id,
            'secondary_site_ids' => $secondarySiteIds,
            'start_date' => today()->subYear(),
            'end_date' => null,
            'is_active' => true,
        ]);
    }

    return $user->fresh(['roles', 'hrEmployeeProfile']);
}

test('Site browsing is fail closed while explicit application wide access remains available', function (): void {
    $scoped = siteProfileCurrentStaff('Scoped Site lead', $this->visibleSite);
    $unassigned = User::factory()->create([
        'name' => 'Unassigned Site lead',
        'role' => 'team_lead',
        'approved_at' => now(),
    ]);
    $unassigned->roles()->sync([Role::query()->where('name', 'team_lead')->firstOrFail()->id]);
    $siteB = siteProfileCurrentStaff('Hidden Site lead', $this->hiddenSite);
    $secondary = siteProfileCurrentStaff('Secondary Site lead', $this->visibleSite, 'team_lead', [
        'secondary_site_ids' => [$this->hiddenSite->id],
    ]);
    $provider = siteProfileCurrentStaff('Provider manager', $this->visibleSite, 'provider_manager');
    $admin = User::factory()->create([
        'name' => 'Application Site administrator',
        'role' => 'admin',
        'approved_at' => now(),
    ]);
    $admin->roles()->sync([Role::query()->where('name', 'admin')->firstOrFail()->id]);

    $scopedResponse = $this->actingAs($scoped)->get(route('sites.index'))->assertOk();
    expect(collect($scopedResponse->inertiaProps('sites'))->pluck('id')->all())
        ->toBe([$this->visibleSite->id]);
    $this->actingAs($scoped)->get(route('sites.show', $this->hiddenSite))->assertNotFound();

    $unassignedResponse = $this->actingAs($unassigned)->get(route('sites.index'))->assertOk();
    expect(collect($unassignedResponse->inertiaProps('sites')))->toBeEmpty();
    $this->actingAs($unassigned)->get(route('sites.show', $this->visibleSite))->assertNotFound();

    $siteBResponse = $this->actingAs($siteB)->get(route('sites.index'))->assertOk();
    expect(collect($siteBResponse->inertiaProps('sites'))->pluck('id')->all())
        ->toBe([$this->hiddenSite->id]);
    $this->actingAs($siteB)->get(route('sites.show', $this->hiddenSite))->assertOk();
    $this->actingAs($siteB)->get(route('sites.show', $this->visibleSite))->assertNotFound();

    $secondaryResponse = $this->actingAs($secondary)->get(route('sites.index'))->assertOk();
    expect(collect($secondaryResponse->inertiaProps('sites'))->pluck('id'))
        ->toContain($this->visibleSite->id, $this->hiddenSite->id);
    $this->actingAs($secondary)->get(route('sites.show', $this->hiddenSite))->assertOk();

    expect($provider->canDo('sites.viewAll'))->toBeTrue();
    $providerResponse = $this->actingAs($provider)->get(route('sites.index'))->assertOk();
    expect(collect($providerResponse->inertiaProps('sites'))->pluck('id'))
        ->toContain($this->visibleSite->id, $this->hiddenSite->id);
    $this->actingAs($provider)->get(route('sites.show', $this->hiddenSite))->assertOk();

    expect($admin->canDo('sites.viewAll'))->toBeTrue();
    $adminResponse = $this->actingAs($admin)->get(route('sites.index'))->assertOk();
    expect(collect($adminResponse->inertiaProps('sites'))->pluck('id'))
        ->toContain($this->visibleSite->id, $this->hiddenSite->id);
    $this->actingAs($admin)->get(route('sites.show', $this->hiddenSite))->assertOk();
});

test('foreign and unassigned direct Site routes are concealed before validation or mutation', function (): void {
    config(['app.debug' => false]);
    $permissions = ['sites.viewAny', 'sites.update', 'sites.archive'];
    $scoped = siteRbacBoundaryActor($permissions, $this->visibleSite);
    $unassigned = siteRbacBoundaryActor($permissions);
    $before = $this->hiddenSite->only([
        'name',
        'phone',
        'address_line_1',
        'emergency_plan_location',
        'is_active',
        'archived',
        'archived_at',
    ]);
    $auditCount = AuditLog::query()->where('action', 'like', 'site.%')->count();

    $directResponses = [
        $this->actingAs($scoped)->getJson(route('sites.show', $this->hiddenSite)),
        $this->actingAs($unassigned)->getJson(route('sites.show', $this->visibleSite)),
        $this->actingAs($scoped)->getJson(route('sites.show', ['site' => 99999999])),
    ];
    foreach ($directResponses as $response) {
        $response->assertNotFound();
    }
    expect(collect($directResponses)->map(fn ($response) => [
        'status' => $response->status(),
        'body' => $response->json(),
    ])->unique(fn (array $result) => json_encode($result, JSON_THROW_ON_ERROR))->count())->toBe(1);

    $routes = [
        ['GET', 'sites.edit', []],
        // Deliberately invalid: object concealment must precede FormRequest validation.
        ['PUT', 'sites.update', []],
        ['PATCH', 'sites.contact-info.update', ['phone' => 'PRIVATE-FOREIGN-PHONE']],
        ['PATCH', 'sites.location.update', ['address_line_1' => 'PRIVATE FOREIGN ADDRESS']],
        ['PATCH', 'sites.safety.update', ['emergency_plan_location' => 'PRIVATE FOREIGN PLAN']],
        ['PATCH', 'sites.active.update', ['is_active' => false]],
        ['PATCH', 'sites.archive', []],
        ['PATCH', 'sites.unarchive', []],
        ['POST', 'sites.onboarding.step', [
            'step' => 'assets',
            'data' => ['assets' => [[
                'name' => 'PRIVATE FOREIGN ASSET',
                'quantity' => 1,
            ]]],
        ]],
    ];

    foreach ($routes as [$method, $routeName, $payload]) {
        $this->actingAs($scoped)
            ->json($method, route($routeName, $this->hiddenSite), $payload)
            ->assertNotFound();
        $this->actingAs($unassigned)
            ->json($method, route($routeName, $this->visibleSite), $payload)
            ->assertNotFound();
    }

    expect($this->hiddenSite->fresh()->only(array_keys($before)))->toBe($before)
        ->and($this->visibleSite->fresh()->archived)->toBeFalse()
        ->and(AuditLog::query()->where('action', 'like', 'site.%')->count())->toBe($auditCount);
    $this->assertDatabaseMissing('assets', ['name' => 'PRIVATE FOREIGN ASSET']);
});

test('the exact global Site permission broadens scope but never replaces the action capability', function (): void {
    $globalReader = siteRbacBoundaryActor([
        'sites.viewAny',
        'sites.viewAll',
    ]);

    $index = $this->actingAs($globalReader)->get(route('sites.index'))->assertOk();
    expect(collect($index->inertiaProps('sites'))->pluck('id'))
        ->toContain($this->visibleSite->id, $this->hiddenSite->id);
    $this->actingAs($globalReader)->get(route('sites.show', $this->hiddenSite))->assertOk();

    $this->actingAs($globalReader)
        ->patchJson(route('sites.active.update', $this->hiddenSite), ['is_active' => false])
        ->assertForbidden();
    $this->actingAs($globalReader)
        ->postJson(route('sites.bulk.archive'), ['ids' => [$this->hiddenSite->id]])
        ->assertForbidden();
    expect($this->hiddenSite->fresh()->is_active)->toBeTrue();
});

test('bulk archive preflights the complete target set before any write or audit', function (): void {
    config(['app.debug' => false]);
    $scoped = siteRbacBoundaryActor([
        'sites.viewAny',
        'sites.archive',
    ], $this->visibleSite);
    $unassigned = siteRbacBoundaryActor(['sites.archive']);
    $auditCount = AuditLog::query()->where('action', 'site.archive')->count();

    $foreignResponse = $this->actingAs($scoped)
        ->postJson(route('sites.bulk.archive'), [
            'ids' => [$this->visibleSite->id, $this->hiddenSite->id],
        ]);
    $foreignResponse->assertNotFound();
    expect($this->visibleSite->fresh()->archived)->toBeFalse()
        ->and($this->hiddenSite->fresh()->archived)->toBeFalse()
        ->and(AuditLog::query()->where('action', 'site.archive')->count())->toBe($auditCount);

    $missingResponse = $this->actingAs($scoped)
        ->postJson(route('sites.bulk.archive'), [
            'ids' => [$this->visibleSite->id, 99999999],
        ]);
    $missingResponse->assertNotFound();
    expect($this->visibleSite->fresh()->archived)->toBeFalse()
        ->and(AuditLog::query()->where('action', 'site.archive')->count())->toBe($auditCount);

    $unassignedResponse = $this->actingAs($unassigned)
        ->postJson(route('sites.bulk.archive'), ['ids' => [$this->visibleSite->id]]);
    $unassignedResponse->assertNotFound();
    expect(collect([$foreignResponse, $missingResponse, $unassignedResponse])
        ->map(fn ($response) => [
            'status' => $response->status(),
            'body' => $response->json(),
        ])
        ->unique(fn (array $result) => json_encode($result, JSON_THROW_ON_ERROR))
        ->count())->toBe(1)
        ->and($this->visibleSite->fresh()->archived)->toBeFalse()
        ->and(AuditLog::query()->where('action', 'site.archive')->count())->toBe($auditCount);

    $this->actingAs($scoped)
        ->postJson(route('sites.bulk.archive'), [
            'ids' => [$this->visibleSite->id, $this->visibleSite->id],
        ])
        ->assertUnprocessable();
    expect($this->visibleSite->fresh()->archived)->toBeFalse()
        ->and(AuditLog::query()->where('action', 'site.archive')->count())->toBe($auditCount);

    $this->actingAs($scoped)
        ->post(route('sites.bulk.archive'), ['ids' => [$this->visibleSite->id]])
        ->assertRedirect();
    expect($this->visibleSite->fresh()->archived)->toBeTrue()
        ->and(AuditLog::query()->where('action', 'site.archive')->count())->toBe($auditCount + 1);

    $globalArchiver = siteRbacBoundaryActor([
        'sites.archive',
        'sites.viewAll',
    ]);
    $this->actingAs($globalArchiver)
        ->post(route('sites.bulk.archive'), ['ids' => [$this->hiddenSite->id]])
        ->assertRedirect();
    expect($this->hiddenSite->fresh()->archived)->toBeTrue();
});

test('bulk archive locks the target set and rolls back every write when a later audit fails', function (): void {
    $globalArchiver = siteRbacBoundaryActor([
        'sites.archive',
        'sites.viewAll',
    ]);
    $first = Site::factory()->create([
        'name' => 'First rollback Site',
        'type' => 'house',
        'is_active' => true,
        'archived' => false,
        'archived_at' => null,
    ]);
    $second = Site::factory()->create([
        'name' => 'Second rollback Site',
        'type' => 'facility',
        'is_active' => true,
        'archived' => false,
        'archived_at' => null,
    ]);
    $targetIds = [$first->id, $second->id];
    $auditCount = AuditLog::query()
        ->where('auditable_type', (new Site)->getMorphClass())
        ->whereIn('auditable_id', $targetIds)
        ->count();

    $queries = [];
    DB::listen(function (QueryExecuted $query) use (&$queries): void {
        $queries[] = strtolower(str_replace(['`', '"'], '', $query->sql));
    });

    $auditAttempts = 0;
    $eventName = 'eloquent.creating: '.AuditLog::class;
    Event::listen($eventName, static function (AuditLog $audit) use (&$auditAttempts): void {
        if ($audit->action !== 'site.archive') {
            return;
        }

        $auditAttempts++;
        if ($auditAttempts === 2) {
            throw new RuntimeException('Simulated second Site archive audit failure.');
        }
    });
    $caught = null;

    $this->withoutExceptionHandling();
    try {
        $this->actingAs($globalArchiver)
            ->post(route('sites.bulk.archive'), ['ids' => [$second->id, $first->id]]);
    } catch (RuntimeException $exception) {
        $caught = $exception;
    } finally {
        $this->withExceptionHandling();
        Event::forget($eventName);
    }

    expect($caught?->getMessage())->toBe('Simulated second Site archive audit failure.')
        ->and($auditAttempts)->toBe(2)
        ->and($first->fresh()->archived)->toBeFalse()
        ->and($first->fresh()->is_active)->toBeTrue()
        ->and($second->fresh()->archived)->toBeFalse()
        ->and($second->fresh()->is_active)->toBeTrue()
        ->and(AuditLog::query()
            ->where('auditable_type', (new Site)->getMorphClass())
            ->whereIn('auditable_id', $targetIds)
            ->count())->toBe($auditCount)
        ->and(collect($queries)->contains(
            fn (string $sql): bool => str_contains($sql, 'from sites')
                && str_contains($sql, 'for update'),
        ))->toBeTrue();
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
        // Keep the fixture inside the presenter's bounded first-100 picker even
        // when this file follows another client-heavy CI batch.
        'first_name' => 'AAA Unassigned',
        'last_name' => 'Person',
        'site_id' => null,
        'status' => 'active',
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
