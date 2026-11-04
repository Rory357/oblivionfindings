<?php

/**
 * To-do #10: Site contacts can be someone who works here. A linked contact
 * shows the staff record's WORK details (job title, work phone, work email)
 * and keeps them current; outside contacts stay typed in; staff the viewer
 * can't see are refused.
 */

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Site;
use App\Models\SiteContact;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RbacSeeder::class);
    $this->homeSite = Site::factory()->create(['name' => 'Aroha House', 'type' => 'house', 'is_active' => true, 'archived' => false]);
    $this->otherSite = Site::factory()->create(['name' => 'Far Away House', 'type' => 'house', 'is_active' => true, 'archived' => false]);
});

/** @param list<string> $permissions */
function contactLinkActor(array $permissions, Site $site): User
{
    $user = User::factory()->create(['role' => 'team_lead', 'approved_at' => now()]);
    $role = Role::query()->create([
        'name' => 'contact_link_'.$user->id,
        'label' => 'Contact link actor',
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

function contactLinkStaff(string $name, Site $site, array $profile = [], array $user = []): User
{
    $staff = User::factory()->create([
        'name' => $name,
        'email' => str($name)->slug().'.signin@example.test',
        'cellphone' => '022 000 PERSONAL',
        'role' => 'support_worker',
        'approved_at' => now(),
        ...$user,
    ]);
    HrEmployeeProfile::factory()->create([
        'user_id' => $staff->id,
        'primary_site_id' => $site->id,
        'secondary_site_ids' => [],
        'start_date' => today()->subYear(),
        'end_date' => null,
        'is_active' => true,
        'position_title' => 'House lead',
        'work_phone' => '09 555 0101',
        'work_email' => str($name)->slug().'.work@example.test',
        'personal_phone' => '021 999 PRIVATE',
        'personal_email' => str($name)->slug().'.home@example.test',
        ...$profile,
    ]);

    return $staff->fresh();
}

function contactLinkPermissions(): array
{
    return [
        'sites.viewAny', 'sites.create', 'sites.update',
        'sites.type.head_office.view', 'sites.type.house.view', 'sites.type.facility.view',
    ];
}

test('Add site saves a staff-linked contact from the staff record and an outside contact as typed', function (): void {
    $actor = contactLinkActor(contactLinkPermissions(), $this->homeSite);
    $staff = contactLinkStaff('Aroha Ngata', $this->homeSite);

    $this->actingAs($actor)->post('/sites', [
        '_modal' => true,
        'name' => 'Kowhai House',
        'type' => 'house',
        'is_active' => true,
        'contacts' => [
            // Whatever the form says for a linked contact is ignored.
            ['type' => 'site_lead', 'user_id' => $staff->id, 'name' => 'Typed over', 'phone' => '000', 'email' => 'typed@example.test', 'is_primary' => true],
            ['type' => 'family', 'name' => 'Mere Parata', 'role' => 'Mother', 'phone' => '021 777 888', 'email' => 'mere@example.test'],
        ],
    ])->assertSessionHasNoErrors()->assertRedirect();

    $site = Site::query()->where('name', 'Kowhai House')->firstOrFail();
    $linked = SiteContact::query()->where('site_id', $site->id)->where('type', 'site_lead')->firstOrFail();
    $outside = SiteContact::query()->where('site_id', $site->id)->where('type', 'family')->firstOrFail();

    expect($linked->user_id)->toBe($staff->id)
        ->and($linked->name)->toBe('Aroha Ngata')
        ->and($linked->role)->toBe('House lead')
        ->and($linked->phone)->toBe('09 555 0101')
        ->and($linked->email)->toBe('aroha-ngata.work@example.test')
        ->and($linked->is_primary)->toBeTrue()
        // The stored fallback is the same work details, never personal ones.
        ->and($linked->getRawOriginal('phone'))->toBe('09 555 0101')
        ->and($linked->getRawOriginal('email'))->toBe('aroha-ngata.work@example.test')
        ->and($outside->user_id)->toBeNull()
        ->and($outside->name)->toBe('Mere Parata')
        ->and($outside->role)->toBe('Mother')
        ->and($outside->phone)->toBe('021 777 888');

    $stored = SiteContact::query()->where('site_id', $site->id)->get()->map->getAttributes()->toJson();
    expect($stored)->not->toContain('PERSONAL')
        ->not->toContain('PRIVATE')
        ->not->toContain('signin@')
        ->not->toContain('.home@');
});

test('a linked contact with no work phone shows no phone rather than a personal one', function (): void {
    $actor = contactLinkActor(contactLinkPermissions(), $this->homeSite);
    $staff = contactLinkStaff('Tama Rewi', $this->homeSite, ['work_phone' => null, 'work_email' => null]);

    $this->actingAs($actor)->post('/sites', [
        '_modal' => true,
        'name' => 'Rata House',
        'type' => 'house',
        'is_active' => true,
        'contacts' => [['type' => 'manager', 'user_id' => $staff->id]],
    ])->assertSessionHasNoErrors();

    $contact = SiteContact::query()->where('user_id', $staff->id)->firstOrFail();
    expect($contact->phone)->toBeNull()
        ->and($contact->email)->toBeNull()
        ->and($contact->name)->toBe('Tama Rewi');
});

test('a phone change on the staff record shows on the site', function (): void {
    $viewer = contactLinkActor(contactLinkPermissions(), $this->homeSite);
    $staff = contactLinkStaff('Hemi Walker', $this->homeSite);
    $contact = SiteContact::query()->create([
        'site_id' => $this->homeSite->id,
        'user_id' => $staff->id,
        'type' => 'site_lead',
        'name' => 'Hemi Walker',
        'role' => 'House lead',
        'phone' => '09 555 0101',
        'email' => 'hemi-walker.work@example.test',
        'is_primary' => true,
    ]);

    HrEmployeeProfile::query()->where('user_id', $staff->id)->update([
        'work_phone' => '09 555 0202',
        'position_title' => 'Senior house lead',
    ]);

    // The Overview's named contacts and the Contacts tab both read it live.
    $this->actingAs($viewer)
        ->get(route('sites.show', $this->homeSite))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('site.site_lead_contact.phone', '09 555 0202')
            ->where('site.site_lead_contact.role', 'Senior house lead'));

    $this->actingAs($viewer)
        ->get(route('sites.show', $this->homeSite), $this->inertiaPartialHeaders('sites/show', 'contactsData'))
        ->assertOk()
        ->assertJsonPath('props.contactsData.items.0.id', $contact->id)
        ->assertJsonPath('props.contactsData.items.0.user_id', $staff->id)
        ->assertJsonPath('props.contactsData.items.0.phone', '09 555 0202');

    // If the staff record is deleted, the last saved details show again.
    $staff->delete();
    $contact->refresh();
    expect($contact->user_id)->toBeNull()
        ->and($contact->phone)->toBe('09 555 0101')
        ->and($contact->name)->toBe('Hemi Walker');
});

test('staff the viewer cannot see are refused as contacts', function (): void {
    $actor = contactLinkActor(contactLinkPermissions(), $this->homeSite);
    $hidden = contactLinkStaff('Hidden Worker', $this->otherSite);
    $former = contactLinkStaff('Former Worker', $this->homeSite, ['is_active' => false, 'end_date' => today()->subDay()]);

    $this->actingAs($actor)->post('/sites', [
        '_modal' => true,
        'name' => 'Refused House',
        'type' => 'house',
        'is_active' => true,
        'contacts' => [
            ['type' => 'site_lead', 'user_id' => $hidden->id],
            ['type' => 'manager', 'user_id' => $former->id],
        ],
    ])->assertSessionHasErrors(['contacts.0.user_id', 'contacts.1.user_id']);
    $this->assertDatabaseMissing('sites', ['name' => 'Refused House']);

    // The profile's Contacts tab refuses them too, and its staff list leaves them out.
    $this->actingAs($actor)
        ->post(route('sites.contacts.store', $this->homeSite), ['type' => 'manager', 'user_id' => $hidden->id])
        ->assertSessionHasErrors('user_id');
    expect(SiteContact::query()->where('user_id', $hidden->id)->exists())->toBeFalse();

    $ids = collect($this->actingAs($actor)->getJson(route('sites.contacts.staff', $this->homeSite))
        ->assertOk()
        ->json('staff'))->pluck('id');
    expect($ids)->not->toContain($hidden->id, $former->id);
});

test('the profile Contacts tab adds and switches contacts between staff and outside', function (): void {
    $actor = contactLinkActor(contactLinkPermissions(), $this->homeSite);
    $staff = contactLinkStaff('Ana Lee', $this->homeSite);

    $options = $this->actingAs($actor)->getJson(route('sites.contacts.staff', $this->homeSite))->assertOk()->json('staff');
    $option = collect($options)->firstWhere('id', $staff->id);
    expect($option)->toBe([
        'id' => $staff->id,
        'name' => 'Ana Lee',
        'role' => 'House lead',
        'phone' => '09 555 0101',
        'email' => 'ana-lee.work@example.test',
    ]);

    $this->actingAs($actor)
        ->post(route('sites.contacts.store', $this->homeSite), ['type' => 'clinical', 'user_id' => $staff->id, 'notes' => 'Weekdays'])
        ->assertSessionHasNoErrors();
    $contact = SiteContact::query()->where('site_id', $this->homeSite->id)->where('type', 'clinical')->firstOrFail();
    expect($contact->user_id)->toBe($staff->id)->and($contact->phone)->toBe('09 555 0101');

    $this->actingAs($actor)
        ->put(route('sites.contacts.update', [$this->homeSite, $contact]), [
            'type' => 'clinical', 'user_id' => '', 'name' => 'Dr Sione Tau', 'phone' => '07 123 4567',
        ])
        ->assertSessionHasNoErrors();
    $contact->refresh();
    expect($contact->user_id)->toBeNull()
        ->and($contact->name)->toBe('Dr Sione Tau')
        ->and($contact->phone)->toBe('07 123 4567');
});

test('an outside contact still needs a name', function (): void {
    $actor = contactLinkActor(contactLinkPermissions(), $this->homeSite);

    $this->actingAs($actor)->post('/sites', [
        'name' => 'Nameless Contact House',
        'type' => 'house',
        'is_active' => true,
        'contacts' => [['type' => 'family', 'phone' => '021 1']],
    ])->assertSessionHasErrors('contacts.0.name');
});
