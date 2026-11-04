<?php

/**
 * To-do #9 and #11: Edit site is the Add site pop-up in edit mode. It loads
 * the Site's details as JSON, saves through PUT /sites/{site}, never blanks a
 * list it didn't send, and keeps the Site geofence to 10–2,000 m. The old
 * /sites/create and /sites/{site}/edit pages redirect to the pop-ups.
 */

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Models\Asset;
use App\Models\AssetGeofence;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Site;
use App\Models\SiteContact;
use App\Models\SiteHoResource;
use App\Models\User;
use App\Support\SiteGeofenceLimits;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RbacSeeder::class);
    $this->site = Site::factory()->create([
        'name' => 'Aroha House',
        'type' => 'house',
        'is_active' => true,
        'archived' => false,
        'latitude' => -36.8485,
        'longitude' => 174.7633,
        'brand_colour' => '#336699',
        'notes' => 'Keep the side gate shut.',
    ]);
    $this->otherSite = Site::factory()->create(['name' => 'Far House', 'type' => 'house', 'is_active' => true, 'archived' => false]);
    $this->manager = editSiteActor(['sites.viewAny', 'sites.update', 'sites.create', 'sites.type.house.view', 'sites.type.head_office.view', 'sites.type.facility.view'], $this->site);
});

/** @param list<string> $permissions */
function editSiteActor(array $permissions, Site $site): User
{
    $user = User::factory()->create(['role' => 'team_lead', 'approved_at' => now()]);
    $role = Role::query()->create([
        'name' => 'edit_site_'.$user->id,
        'label' => 'Edit site actor',
        'level' => 40,
        'type' => 'custom',
    ]);
    $role->permissions()->sync(Permission::query()->whereIn('key', $permissions)->pluck('id')->all());
    $user->roles()->sync([$role->id]);
    HrEmployeeProfile::factory()->create([
        'user_id' => $user->id,
        'primary_site_id' => $site->id,
        'secondary_site_ids' => [],
        'start_date' => today()->subYear(),
        'end_date' => null,
        'is_active' => true,
    ]);

    return $user->fresh();
}

function editSiteStaff(string $name, Site $site, string $workPhone = '09 555 0101'): User
{
    $staff = User::factory()->create(['name' => $name, 'role' => 'support_worker', 'approved_at' => now()]);
    HrEmployeeProfile::factory()->create([
        'user_id' => $staff->id,
        'primary_site_id' => $site->id,
        'secondary_site_ids' => [],
        'start_date' => today()->subYear(),
        'end_date' => null,
        'is_active' => true,
        'position_title' => 'Support worker',
        'work_phone' => $workPhone,
        'work_email' => str($name)->slug().'.work@example.test',
    ]);

    return $staff->fresh();
}

/** What the pop-up always sends: the Basics fields it loaded. */
function editSitePayload(Site $site, array $overrides = []): array
{
    return [
        '_modal' => true,
        'name' => $site->name,
        'type' => $site->type,
        'is_active' => (bool) $site->is_active,
        ...$overrides,
    ];
}

test('the geofence radius is 10 m to 2,000 m on update, and a Site without one gets one', function (): void {
    foreach ([9, 2001] as $radius) {
        $this->actingAs($this->manager)
            ->put(route('sites.update', $this->site), editSitePayload($this->site, [
                'geofence' => ['radius_m' => $radius, 'breach_type' => 'both', 'is_active' => true],
            ]))
            ->assertSessionHasErrors('geofence.radius_m');
    }
    expect(AssetGeofence::query()->where('site_id', $this->site->id)->exists())->toBeFalse();

    $this->actingAs($this->manager)
        ->put(route('sites.update', $this->site), editSitePayload($this->site, [
            'geofence' => ['radius_m' => 10, 'breach_type' => 'exit', 'is_active' => true],
        ]))
        ->assertSessionHasNoErrors();

    $boundary = AssetGeofence::query()->where('site_id', $this->site->id)->whereNull('asset_id')->sole();
    expect((int) $boundary->shape['radius_m'])->toBe(10)
        ->and($boundary->breach_type)->toBe('exit');

    // A Site that already has one keeps it: it changes in Shared boundaries.
    $this->actingAs($this->manager)
        ->put(route('sites.update', $this->site), editSitePayload($this->site, [
            'geofence' => ['radius_m' => 2000, 'breach_type' => 'both', 'is_active' => true],
        ]))
        ->assertSessionHasNoErrors();
    expect(AssetGeofence::query()->where('site_id', $this->site->id)->count())->toBe(1)
        ->and((int) $boundary->fresh()->shape['radius_m'])->toBe(10);
});

test('Add site takes a 10 m geofence and refuses 9 m', function (): void {
    $this->actingAs($this->manager)->post('/sites', [
        'name' => 'Tiny Fence House', 'type' => 'house', 'is_active' => true,
        'latitude' => -36.85, 'longitude' => 174.76,
        'geofence' => ['radius_m' => 9],
    ])->assertSessionHasErrors('geofence.radius_m');

    $this->actingAs($this->manager)->post('/sites', [
        'name' => 'Tiny Fence House', 'type' => 'house', 'is_active' => true,
        'latitude' => -36.85, 'longitude' => 174.76,
        'geofence' => ['radius_m' => 10, 'breach_type' => 'both', 'is_active' => true],
    ])->assertSessionHasNoErrors();

    $site = Site::query()->where('name', 'Tiny Fence House')->sole();
    expect((int) AssetGeofence::query()->where('site_id', $site->id)->sole()->shape['radius_m'])->toBe(10);
});

test('the Site geofence endpoints keep circles to 10 m to 2,000 m', function (): void {
    $mapper = editSiteActor(['sites.viewAny', 'sites.update', 'sites.type.house.view', 'assets.geofences.manage'], $this->site);

    foreach ([5, 2001] as $radius) {
        $this->actingAs($mapper)
            ->post(route('sites.geofence.store', $this->site), [
                'geometry' => ['type' => 'circle', 'center' => ['lat' => -36.85, 'lng' => 174.76], 'radius_m' => $radius],
            ])
            ->assertSessionHasErrors('geometry.radius_m');
    }
});

test('editing saves staff-linked and outside contacts', function (): void {
    $staff = editSiteStaff('Wiremu Kaa', $this->site);

    $this->actingAs($this->manager)
        ->put(route('sites.update', $this->site), editSitePayload($this->site, [
            'collections' => ['contacts'],
            'contacts' => [
                ['type' => 'site_lead', 'user_id' => $staff->id, 'is_primary' => true],
                ['type' => 'maintenance', 'name' => 'Pipe Pros', 'role' => 'Plumber', 'phone' => '0800 PIPES'],
            ],
        ]))
        ->assertSessionHasNoErrors()
        ->assertRedirect();

    $contacts = SiteContact::query()->where('site_id', $this->site->id)->orderBy('id')->get();
    expect($contacts)->toHaveCount(2)
        ->and($contacts[0]->user_id)->toBe($staff->id)
        ->and($contacts[0]->name)->toBe('Wiremu Kaa')
        ->and($contacts[0]->phone)->toBe('09 555 0101')
        ->and($contacts[0]->email)->toBe('wiremu-kaa.work@example.test')
        ->and($contacts[1]->user_id)->toBeNull()
        ->and($contacts[1]->name)->toBe('Pipe Pros')
        ->and($contacts[1]->phone)->toBe('0800 PIPES');

    // Staff from a Site the editor can't see are refused…
    $hidden = editSiteStaff('Hidden Worker', $this->otherSite);
    $this->actingAs($this->manager)
        ->put(route('sites.update', $this->site), editSitePayload($this->site, [
            'name' => 'Must not save',
            'contacts' => [['id' => $contacts[0]->id, 'type' => 'site_lead', 'user_id' => $hidden->id]],
        ]))
        ->assertSessionHasErrors('contacts.0.user_id');
    expect($this->site->fresh()->name)->toBe('Aroha House');

    // …but a contact already linked to them can still be saved unchanged.
    $contacts[0]->forceFill(['user_id' => $hidden->id])->save();
    $this->actingAs($this->manager)
        ->put(route('sites.update', $this->site), editSitePayload($this->site, [
            'contacts' => [
                ['id' => $contacts[0]->id, 'type' => 'site_lead', 'user_id' => $hidden->id, 'is_primary' => true],
                ['id' => $contacts[1]->id, 'type' => 'maintenance', 'name' => 'Pipe Pros', 'phone' => '0800 PIPES'],
            ],
        ]))
        ->assertSessionHasNoErrors();
    expect($contacts[0]->fresh()->user_id)->toBe($hidden->id);
});

test('an edit never blanks lists or details it did not send', function (): void {
    $this->site->update(['primary_contact_user_id' => editSiteStaff('Lead Person', $this->site)->id]);
    $contact = SiteContact::query()->create(['site_id' => $this->site->id, 'type' => 'emergency', 'name' => 'After hours', 'phone' => '0800 111']);
    $asset = Asset::factory()->create(['site_id' => $this->site->id]);

    $this->actingAs($this->manager)
        ->put(route('sites.update', $this->site), editSitePayload($this->site, ['name' => 'Aroha House (renamed)']))
        ->assertSessionHasNoErrors();

    $site = $this->site->fresh();
    expect($site->name)->toBe('Aroha House (renamed)')
        ->and($site->brand_colour)->toBe('#336699')
        ->and($site->notes)->toBe('Keep the side gate shut.')
        ->and($site->primary_contact_user_id)->not->toBeNull()
        ->and($contact->fresh())->not->toBeNull()
        ->and($asset->fresh()->site_id)->toBe($this->site->id);

    // A list named in `collections` but sent empty (a multipart form drops
    // empty arrays) is cleared.
    $this->actingAs($this->manager)
        ->post(route('sites.update', $this->site), editSitePayload($this->site, [
            '_method' => 'put',
            'collections' => ['contacts'],
        ]))
        ->assertSessionHasNoErrors();
    expect(SiteContact::query()->where('site_id', $this->site->id)->exists())->toBeFalse()
        ->and($asset->fresh()->site_id)->toBe($this->site->id);
});

test('removing a resource in Edit site deactivates it instead of deleting it', function (): void {
    $office = Site::factory()->create(['type' => 'head_office', 'is_active' => true, 'archived' => false]);
    $editor = editSiteActor(['sites.viewAny', 'sites.update', 'sites.type.head_office.view'], $office);
    $keep = SiteHoResource::query()->create(['site_id' => $office->id, 'name' => 'Board room', 'resource_type' => 'meeting_room', 'is_active' => true]);
    $drop = SiteHoResource::query()->create(['site_id' => $office->id, 'name' => 'Old office', 'resource_type' => 'other', 'is_active' => true]);

    $this->actingAs($editor)
        ->put(route('sites.update', $office), editSitePayload($office, [
            'resources' => [['id' => $keep->id, 'name' => 'Board room', 'resource_type' => 'meeting_room', 'capacity' => 10]],
        ]))
        ->assertSessionHasNoErrors();

    expect($keep->fresh()->is_active)->toBeTruthy()
        ->and((int) $keep->fresh()->capacity)->toBe(10)
        ->and($drop->fresh())->not->toBeNull()
        ->and($drop->fresh()->is_active)->toBeFalsy();
});

test('a cleared weekly food budget is saved as cleared', function (): void {
    $this->site->update(['weekly_food_budget_cents' => 18000]);

    $this->actingAs($this->manager)
        ->put(route('sites.update', $this->site), editSitePayload($this->site, ['weekly_food_budget' => '']))
        ->assertSessionHasNoErrors();

    expect($this->site->fresh()->weekly_food_budget_cents)->toBeNull();
});

test('the pop-up save returns to the page it came from', function (): void {
    $this->actingAs($this->manager)
        ->from(route('sites.index'))
        ->put(route('sites.update', $this->site), editSitePayload($this->site))
        ->assertRedirect(route('sites.index'));

    $payload = editSitePayload($this->site);
    unset($payload['_modal']);
    $this->actingAs($this->manager)
        ->from(route('sites.index'))
        ->put(route('sites.update', $this->site), $payload)
        ->assertRedirect(route('sites.show', $this->site));
});

test('edit details load the saved Site, live lists and only pickable staff', function (): void {
    $staff = editSiteStaff('Visible Worker', $this->site);
    $hidden = editSiteStaff('Hidden Worker', $this->otherSite);
    SiteContact::query()->create(['site_id' => $this->site->id, 'user_id' => $staff->id, 'type' => 'site_lead', 'name' => 'Visible Worker']);
    SiteContact::query()->create(['site_id' => $this->site->id, 'type' => 'family', 'name' => 'Mere Parata', 'phone' => '021 777']);
    $this->site->update(['weekly_food_budget_cents' => 18025]);

    $json = $this->actingAs($this->manager)
        ->getJson(route('sites.edit-details', $this->site))
        ->assertOk()
        ->json();

    expect($json['site']['name'])->toBe('Aroha House')
        ->and($json['site']['brand_colour'])->toBe('#336699')
        ->and($json['site']['weekly_food_budget'])->toBe('180.25')
        ->and(collect($json['site']['contacts'])->pluck('user_id')->all())->toBe([$staff->id, null])
        ->and($json['site']['contacts'][0]['phone'])->toBe('09 555 0101')
        ->and($json['links']['shift_coverage'])->toContain('tab=shift_coverage')
        ->and(collect($json['reference']['users'])->pluck('id'))->toContain($staff->id)
        ->and(collect($json['reference']['users'])->pluck('id'))->not->toContain($hidden->id)
        ->and(array_keys($json['reference']['users'][0]))->toBe(['id', 'name', 'role', 'phone', 'email']);

    $this->actingAs($this->manager)
        ->getJson(route('sites.edit-details', $this->otherSite))
        ->assertNotFound();
});

test('old edit and add links open the pop-ups', function (): void {
    $this->actingAs($this->manager)
        ->get("/sites/{$this->site->id}/edit")
        ->assertRedirect(route('sites.show', ['site' => $this->site, 'edit' => 1]));

    $this->actingAs($this->manager)
        ->get("/sites/{$this->site->id}/edit?step=location")
        ->assertRedirect(route('sites.show', ['site' => $this->site, 'edit' => 1, 'step' => 'location']));

    $this->actingAs($this->manager)
        ->get("/sites/{$this->site->id}/edit?step=nonsense")
        ->assertRedirect(route('sites.show', ['site' => $this->site, 'edit' => 1]));

    $this->actingAs($this->manager)
        ->get('/sites/create')
        ->assertRedirect(route('sites.index', ['add' => 1]));

    // Still guarded: no access to the Site is a 404, no create permission a 403.
    $this->actingAs($this->manager)
        ->get("/sites/{$this->otherSite->id}/edit")
        ->assertNotFound();
    $reader = editSiteActor(['sites.viewAny', 'sites.type.house.view'], $this->site);
    $this->actingAs($reader)->get('/sites/create')->assertForbidden();
});

test('the browser and server geofence limits match', function (): void {
    $ts = file_get_contents(resource_path('js/lib/site-geofence.ts'));

    foreach ([
        'SITE_GEOFENCE_MIN_RADIUS_M' => SiteGeofenceLimits::MIN_RADIUS_M,
        'SITE_GEOFENCE_MAX_RADIUS_M' => SiteGeofenceLimits::MAX_RADIUS_M,
        'SITE_GEOFENCE_DEFAULT_RADIUS_M' => SiteGeofenceLimits::DEFAULT_RADIUS_M,
    ] as $name => $value) {
        expect($ts)->toMatch('/export const '.$name.' = '.$value.';/');
    }
    expect([SiteGeofenceLimits::MIN_RADIUS_M, SiteGeofenceLimits::MAX_RADIUS_M])->toBe([10, 2000]);
});
