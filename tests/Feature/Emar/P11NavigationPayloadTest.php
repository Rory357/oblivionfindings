<?php

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Models\Permission;
use App\Models\Site;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

function p11NavigationActor(Site $site, array $keys): User
{
    $actor = User::factory()->create(['approved_at' => now()]);
    $actor->roles()->detach();
    $overrides = [];
    foreach ($keys as $key) {
        $permission = Permission::firstOrCreate(['key' => $key], ['description' => $key, 'group' => 'medications', 'module' => 'Clinical']);
        $overrides[$permission->id] = ['allowed' => true];
    }
    $actor->permissionOverrides()->sync($overrides);
    HrEmployeeProfile::factory()->create(['user_id' => $actor->id, 'primary_site_id' => $site->id, 'secondary_site_ids' => [], 'is_active' => true, 'start_date' => now()->subYear(), 'end_date' => null]);

    return $actor->fresh();
}

it('exposes the existing house-alert capability and admits its own-house Settings without granting global settings or PIN resets', function () {
    $site = Site::factory()->create(['type' => 'house']);
    $other = Site::factory()->create(['type' => 'house']);
    $actor = p11NavigationActor($site, ['medications.alerts.manage_house']);
    $before = $actor->permissionOverrides()->count();
    $this->actingAs($actor)->get('/emar/settings')->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('emar/Settings')->where('can.medications.alertsManageHouse', true)->where('can.medications.witnessPinReset', false)
        ->where('can.medications.view', false)->where('can.medications.settingsManage', false)
        ->where('settingsAccess', false)->where('alertAccess.view', true)->where('alertAccess.manage_org', false)
        ->where('alertAccess.house_ids', [$site->id])->where('sites.0.id', $site->id)->has('sites', 1)->where('witnessPin.can_reset', false));
    $this->put('/emar/settings/changes', ['view' => 'alerts', 'changes' => [['group' => 'delivery', 'key' => 'pin_unattended', 'from' => 'no', 'value' => 'yes']]])->assertForbidden();
    $this->put('/emar/settings/changes', ['view' => 'alerts', 'changes' => [['group' => 'alertExtra', 'key' => 'stock', 'site_id' => $other->id, 'from' => '[]', 'value' => '[]']]])->assertForbidden();
    expect($actor->permissionOverrides()->count())->toBe($before)->and($actor->roles()->count())->toBe(0);
});

it('keeps the existing PIN-only Settings entry read-only for policy and house alerts', function () {
    $site = Site::factory()->create(['type' => 'house']);
    $actor = p11NavigationActor($site, ['medications.witness_pin.reset']);
    $this->actingAs($actor)->get('/emar/settings')->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('emar/Settings')->where('can.medications.witnessPinReset', true)->where('can.medications.alertsManageHouse', false)
        ->where('settingsAccess', false)->where('witnessPin.can_reset', true)->where('alertAccess.view', false));
});

it('denies Settings when neither reading nor management capability exists', function () {
    $actor = p11NavigationActor(Site::factory()->create(), []);
    $this->actingAs($actor)->get('/emar/settings')->assertForbidden();
});
