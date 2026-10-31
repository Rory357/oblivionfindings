<?php

use App\Domain\It\ItModuleNavigation;
use App\Domain\It\Presenters\ItTicketContextPresenter;
use App\Domain\SecurityDevices\Models\Device;
use App\Domain\SecurityDevices\Models\DeviceAssignment;
use App\Models\ItTicket;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;

function remoteSupportActor(Site $site, array $keys): User
{
    $role = Role::query()->create(['name' => 'remote-support-'.str()->uuid(), 'label' => 'Remote support test', 'level' => 10, 'type' => 'custom']);
    $user = User::factory()->create(['approved_at' => now(), 'role' => $role->name]);
    $user->roles()->attach($role);
    foreach ($keys as $key) {
        $permission = Permission::query()->firstOrCreate(['key' => $key], ['description' => $key, 'group' => 'it', 'module' => 'Operations']);
        $role->permissions()->syncWithoutDetaching([$permission->id]);
    }
    ensureCanonicalHrStaffProfile($user, $site);

    return $user->fresh();
}

function remoteSupportComputer(Site $site, string $category = 'endpoint'): Device
{
    $device = Device::factory()->create(['domain' => 'it_infrastructure', 'category' => $category, 'name' => 'Reception PC', 'config' => ['secret' => 'never-project-this']]);
    DeviceAssignment::query()->create(['device_id' => $device->id, 'assignable_type' => 'site', 'assignable_id' => $site->id, 'assignment_type' => 'permanent', 'assigned_at' => now()->subMinute()]);

    return $device;
}

beforeEach(function () {
    Http::preventStrayRequests();
    Notification::fake();
    $this->site = Site::factory()->create(['is_active' => true, 'archived' => false, 'archived_at' => null]);
    $this->keys = ['it.view', 'it.manage', 'securityDevices.viewAny', 'securityDevices.devices.view'];
    $this->actor = remoteSupportActor($this->site, $this->keys);
    $this->computer = remoteSupportComputer($this->site);
});

test('remote support lists only canonical computers in device scope and conceals direct object guesses', function () {
    $elsewhere = remoteSupportComputer(Site::factory()->create(['is_active' => true, 'archived' => false]));
    $switch = remoteSupportComputer($this->site, 'switch');
    $response = $this->actingAs($this->actor)->get('/it/remote-support?device='.$this->computer->id);
    $response->assertOk()->assertInertia(fn ($p) => $p->component('it/remote-support/index')
        ->has('computers.data', 1)->where('computers.data.0.id', $this->computer->id)
        ->where('selected.id', $this->computer->id)->missing('selected.config')
        ->where('availability.remote_support', false)->where('availability.obli', false)
        ->where('availability.script_execution', false));
    expect($response->headers->get('Cache-Control'))->toContain('no-store');
    $response->assertDontSee('never-project-this');
    $this->get('/it/remote-support?device='.$elsewhere->id)->assertNotFound();
    $this->get('/it/remote-support?device='.$switch->id)->assertNotFound();
});

test('remote support requires both technician and device permissions in navigation and direct routes', function () {
    foreach (['it.manage', 'securityDevices.viewAny', 'securityDevices.devices.view'] as $missing) {
        $viewer = remoteSupportActor($this->site, array_values(array_diff($this->keys, [$missing])));
        $this->actingAs($viewer)->get('/it/remote-support')->assertForbidden();
        expect(collect(ItModuleNavigation::forUser($viewer))->pluck('items')->flatten(1)->where('href', '/it/remote-support'))->toHaveCount(0);
    }
    expect(collect(ItModuleNavigation::forUser($this->actor))->pluck('items')->flatten(1)->where('href', '/it/remote-support'))->toHaveCount(1);
});

test('ticket shortcut validates both current ticket access and the affected computer link', function () {
    $ticket = ItTicket::factory()->create(['site_id' => $this->site->id, 'is_sensitive' => false, 'status' => 'open', 'work_type' => 'incident', 'merged_into_ticket_id' => null]);
    $url = '/it/remote-support?device='.$this->computer->id.'&ticket='.$ticket->id;
    $this->actingAs($this->actor)->get($url)->assertNotFound();
    $ticket->links()->create(['relationship' => 'affected_device', 'linkable_type' => $this->computer->getMorphClass(), 'linkable_id' => $this->computer->id]);
    $this->get($url)->assertOk()->assertInertia(fn ($p) => $p->where('ticket.id', $ticket->id));
    $context = app(ItTicketContextPresenter::class)->present($ticket->fresh(), $this->actor);
    expect($context['devices'][0]['remote_support_href'])->toContain('/it/remote-support?device='.$this->computer->id);
    $ticket->forceFill(['is_sensitive' => true])->save();
    $this->get($url)->assertNotFound();
});

test('remote support search is bounded and flags cannot expose unfinished execution', function () {
    config()->set('endpoint_management.remote_support.enabled', true);
    $this->actingAs($this->actor)->get('/it/remote-support?q=does-not-exist')->assertOk()
        ->assertInertia(fn ($p) => $p->has('computers.data', 0)->where('availability.remote_support', false)->where('availability.obli', false)->where('availability.script_execution', false));
    $this->getJson('/it/remote-support?device=not-a-device')->assertUnprocessable();
    Http::assertNothingSent();
});
