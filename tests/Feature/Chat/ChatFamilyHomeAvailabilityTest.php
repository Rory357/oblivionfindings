<?php

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Models\Client;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use Illuminate\Support\Facades\DB;

it('discloses explicit off duty only for currently authorised named family contacts', function () {
    config(['chat.shared_experience' => true]);
    $site = Site::factory()->create();
    $worker = User::factory()->create(['role' => 'staff', 'approved_at' => now()]);
    ensureCanonicalHrStaffProfile($worker, $site);
    $role = Role::create(['name' => 'off_duty_cover', 'label' => 'Care team', 'level' => 50, 'type' => 'custom']);
    $permission = Permission::firstOrCreate(['key' => 'family_portal.manage'], ['description' => 'Manage family portal', 'group' => 'operations', 'module' => 'Operations']);
    $role->permissions()->attach($permission->id);
    $worker->roles()->attach($role->id);
    $client = Client::factory()->create(['site_id' => $site->id, 'key_worker_id' => $worker->id]);
    $relative = User::factory()->create(['role' => 'next_of_kin', 'approved_at' => now()]);
    $familyRole = Role::firstOrCreate(['name' => 'next_of_kin'], ['label' => 'Next of Kin', 'level' => 1, 'type' => 'system']);
    $relative->roles()->syncWithoutDetaching([$familyRole->id]);
    $client->portalUsers()->attach($relative->id, ['relation' => 'next_of_kin']);
    DB::table('chat_preferences')->insert(['user_id' => $worker->id, 'availability' => 'off_duty', 'last_seen_at' => now()->subHour(), 'created_at' => now(), 'updated_at' => now()]);
    $route = route('chat.family-home', $client);
    $this->actingAs($relative)->withHeader('X-Chat-Actor', (string) $relative->id)
        ->getJson($route)->assertOk()->assertJsonPath('contacts.0.id', $worker->id)
        ->assertJsonPath('contacts.0.off_duty', true)->assertJsonPath('contacts.0.available', false);
    DB::table('chat_preferences')->where('user_id', $worker->id)->update(['availability' => 'dnd']);
    $this->getJson($route)->assertOk()->assertJsonPath('contacts.0.off_duty', false);
    HrEmployeeProfile::where('user_id', $worker->id)->update(['end_date' => today()->subDay()]);
    $this->getJson($route)->assertOk()->assertJsonCount(0, 'contacts');
    $client->portalUsers()->detach($relative->id);
    $this->getJson($route)->assertForbidden();
});
