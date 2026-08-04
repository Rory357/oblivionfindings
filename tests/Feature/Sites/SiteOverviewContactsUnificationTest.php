<?php

use App\Models\Role;
use App\Models\Site;
use App\Models\SiteContact;
use App\Models\User;
use App\Services\Sites\SiteReadinessService;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RbacSeeder::class);
});

function sitesOverviewContactsUser(string $roleName = 'admin'): User
{
    $user = User::factory()->create([
        'role' => $roleName,
        'approved_at' => now(),
    ]);

    $role = Role::query()->where('name', $roleName)->first();
    if ($role) {
        $user->roles()->syncWithoutDetaching([$role->id]);
    }

    return $user;
}

test('site contact relations return the highest priority typed contacts', function () {
    $site = Site::factory()->create();
    $olderManager = SiteContact::create([
        'site_id' => $site->id,
        'type' => 'manager',
        'name' => 'Older Manager',
        'phone' => '021 111 1111',
        'is_primary' => false,
    ]);
    $primaryManager = SiteContact::create([
        'site_id' => $site->id,
        'type' => 'manager',
        'name' => 'Primary Manager',
        'phone' => '021 222 2222',
        'is_primary' => true,
    ]);
    $siteLead = SiteContact::create([
        'site_id' => $site->id,
        'type' => 'site_lead',
        'name' => 'Site Lead',
    ]);
    $emergency = SiteContact::create([
        'site_id' => $site->id,
        'type' => 'emergency',
        'name' => 'After Hours',
    ]);

    $loaded = Site::with([
        'managerContact',
        'siteLeadContact',
        'afterHoursContact',
        'primarySiteContact',
    ])->findOrFail($site->id);

    expect($loaded->managerContact?->id)->toBe($primaryManager->id)
        ->and($loaded->managerContact?->id)->not->toBe($olderManager->id)
        ->and($loaded->siteLeadContact?->id)->toBe($siteLead->id)
        ->and($loaded->afterHoursContact?->id)->toBe($emergency->id)
        ->and($loaded->primarySiteContact?->id)->toBe($primaryManager->id);
});

test('contact info endpoint only updates the site phone and email fields', function () {
    $user = sitesOverviewContactsUser();
    $site = Site::factory()->create([
        'phone' => null,
        'email' => null,
    ]);
    $legacyManagerName = implode('_', ['manager', 'name']);

    $this->actingAs($user)
        ->patch(route('sites.contact-info.update', $site), [
            'phone' => '09 555 0100',
            'email' => 'house@example.org.nz',
            $legacyManagerName => 'Ignored Manager',
        ])
        ->assertRedirect();

    $site->refresh();

    expect($site->phone)->toBe('09 555 0100')
        ->and($site->email)->toBe('house@example.org.nz')
        ->and(Schema::hasColumn('sites', $legacyManagerName))->toBeFalse();
});

test('show page exposes derived contact relations on the site payload', function () {
    $user = sitesOverviewContactsUser();
    $site = Site::factory()->create();

    SiteContact::create([
        'site_id' => $site->id,
        'type' => 'manager',
        'name' => 'Alice Manager',
        'phone' => '021 222 3333',
        'is_primary' => true,
    ]);
    SiteContact::create([
        'site_id' => $site->id,
        'type' => 'site_lead',
        'name' => 'Taylor Lead',
    ]);
    SiteContact::create([
        'site_id' => $site->id,
        'type' => 'emergency',
        'name' => 'On Call',
        'phone' => '0800 111 222',
    ]);

    $this->actingAs($user)
        ->get(route('sites.show', $site))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('sites/show')
            ->where('site.manager_contact.name', 'Alice Manager')
            ->where('site.site_lead_contact.name', 'Taylor Lead')
            ->where('site.after_hours_contact.name', 'On Call')
            ->where('site.primary_site_contact.name', 'Alice Manager')
        );
});

test('readiness counts manager and emergency contacts instead of removed scalar columns', function () {
    $site = Site::factory()->create([
        'phone' => '09 555 0100',
        'email' => 'house@example.org.nz',
        'emergency_plan_location' => 'Kitchen folder',
        'medication_storage_location' => 'Office cabinet',
        'primary_contact_user_id' => null,
        'is_active' => true,
    ]);

    SiteContact::create([
        'site_id' => $site->id,
        'type' => 'manager',
        'name' => 'Alice Manager',
    ]);
    SiteContact::create([
        'site_id' => $site->id,
        'type' => 'emergency',
        'name' => 'On Call',
        'phone' => '0800 111 222',
    ]);

    $critical = collect(app(SiteReadinessService::class)->evaluate($site)['critical'])->keyBy('key');

    expect($critical['site_lead']['done'])->toBeTrue()
        ->and($critical['after_hours']['done'])->toBeTrue()
        ->and($critical['emergency_contact']['done'])->toBeTrue();
});
