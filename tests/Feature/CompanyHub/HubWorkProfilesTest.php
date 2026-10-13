<?php

use App\Domain\CompanyHub\Services\HubDirectory;
use App\Models\AuditLog;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use Database\Seeders\CompanyHubPermissionsSeeder;
use Database\Seeders\RbacSeeder;
use Database\Seeders\SeedHrPermissionsSeeder;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->seed([RbacSeeder::class, SeedHrPermissionsSeeder::class, CompanyHubPermissionsSeeder::class]);
    $this->reader = User::factory()->create(['role' => 'support_worker', 'approved_at' => now()]);
    $this->reader->roles()->attach(Role::where('name', 'support_worker')->firstOrFail());
    $this->site = ensureCanonicalHrStaffProfile($this->reader, overrides: ['bio' => 'Private HR biography', 'work_email' => 'work@company.test']);
    $this->person = $this->reader->hrEmployeeProfile()->sole();
    $this->payload = ['expected_version' => 0, 'about' => ' I welcome new colleagues. ', 'help_with' => [' Orientation ', 'orientation', 'Accessible documents']];
    $this->url = '/company-hub/people/'.$this->person->id;
});

test('staff deliberately share and remove their own introduction without exposing or changing private HR biography', function () {
    $this->actingAs($this->reader)->get($this->url)->assertOk()->assertInertia(fn (Assert $page) => $page
        ->where('selectedPerson.can_edit_profile', true)->where('selectedPerson.shared_profile.about', '')
        ->where('selectedPerson.shared_profile.version', 0)->missing('selectedPerson.bio')->missing('selectedPerson.date_of_birth'));
    $this->putJson($this->url.'/profile', $this->payload)->assertOk()->assertJsonPath('person.shared_profile.about', 'I welcome new colleagues.')
        ->assertJsonPath('person.shared_profile.help_with', ['Orientation', 'Accessible documents'])->assertJsonPath('person.shared_profile.version', 1);
    expect($this->person->fresh()->bio)->toBe('Private HR biography');
    $audit = AuditLog::where('action', 'company_hub.work_profile_updated')->sole();
    expect($audit->meta)->toMatchArray(['version' => 1, 'has_about' => true, 'skills_count' => 2])->not->toHaveKeys(['about', 'help_with', 'bio']);
    $this->get($this->url)->assertOk()->assertInertia(fn (Assert $page) => $page->where('selectedPerson.shared_profile.version', 1)->where('selectedPerson.work_email', 'work@company.test'));
    $this->putJson($this->url.'/profile', ['expected_version' => 1, 'about' => null, 'help_with' => []])->assertOk()->assertJsonPath('person.shared_profile.about', '')->assertJsonPath('person.shared_profile.help_with', [])->assertJsonPath('person.shared_profile.version', 2);
    expect($this->person->fresh()->bio)->toBe('Private HR biography');
});

test('shared skills are discoverable by visible colleagues through directory and global search', function () {
    $this->actingAs($this->reader)->putJson($this->url.'/profile', $this->payload)->assertOk();
    $colleague = User::factory()->create(['role' => 'support_worker', 'approved_at' => now()]);
    $colleague->roles()->attach(Role::where('name', 'support_worker')->firstOrFail());
    ensureCanonicalHrStaffProfile($colleague, $this->site);
    $this->actingAs($colleague)->get($this->url)->assertOk()->assertInertia(fn (Assert $page) => $page->where('selectedPerson.can_edit_profile', false)->where('selectedPerson.shared_profile.about', 'I welcome new colleagues.')->missing('selectedPerson.bio'));
    $this->get('/company-hub?search=Accessible%20documents')->assertOk()->assertInertia(fn (Assert $page) => $page->has('people', 1)->where('people.0.id', $this->person->id));
    expect(app(HubDirectory::class)->people($colleague, 'Private HR biography'))->toBe([]);
    $this->putJson($this->url.'/profile', [...$this->payload, 'expected_version' => 1])->assertNotFound();
});

test('even an editor cannot modify someone else’s shared profile and an old account tab cannot change the newly signed in owner', function () {
    $editor = User::factory()->create(['role' => 'admin', 'approved_at' => now()]);
    $editor->roles()->attach(Role::where('name', 'admin')->firstOrFail());
    ensureCanonicalHrStaffProfile($editor, $this->site);
    $this->actingAs($editor)->putJson($this->url.'/profile', $this->payload)->assertNotFound();
    expect(DB::table('hub_work_profiles')->count())->toBe(0);
    $this->actingAs($this->reader)->putJson($this->url.'/profile', [...$this->payload, 'user_id' => $editor->id, 'bio' => 'forged', 'date_of_birth' => '2000-01-01'])->assertUnprocessable()->assertJsonValidationErrors(['user_id', 'bio', 'date_of_birth']);
});

test('stale creation and update versions are rejected without losing the saved profile', function () {
    $this->actingAs($this->reader)->putJson($this->url.'/profile', $this->payload)->assertOk();
    $this->putJson($this->url.'/profile', [...$this->payload, 'about' => 'Stale first window'])->assertConflict();
    $this->putJson($this->url.'/profile', [...$this->payload, 'expected_version' => 1, 'about' => 'Second version'])->assertOk()->assertJsonPath('person.shared_profile.version', 2);
    $this->putJson($this->url.'/profile', [...$this->payload, 'expected_version' => 1, 'about' => 'Stale second window'])->assertConflict();
    expect(DB::table('hub_work_profiles')->sole()->about)->toBe('Second version')->and(DB::table('hub_work_profiles')->sole()->version)->toBe(2);
    $this->putJson($this->url.'/profile', [...$this->payload, 'expected_version' => 2, 'help_with' => array_fill(0, 11, 'A skill')])->assertUnprocessable();
    $this->putJson($this->url.'/profile', [...$this->payload, 'expected_version' => 2, 'about' => str_repeat('a', 1601)])->assertUnprocessable();
    $this->putJson($this->url.'/profile', [...$this->payload, 'expected_version' => 2, 'help_with' => [' ']])->assertUnprocessable();
});

test('direct profiles search and updates recheck current staff site and hub access', function () {
    $this->actingAs($this->reader)->putJson($this->url.'/profile', $this->payload)->assertOk();
    $viewer = User::factory()->create(['role' => 'support_worker', 'approved_at' => now()]);
    $viewer->roles()->attach(Role::where('name', 'support_worker')->firstOrFail());
    ensureCanonicalHrStaffProfile($viewer, $this->site);
    $this->actingAs($viewer)->get($this->url)->assertOk();
    $outside = Site::factory()->create(['is_active' => true, 'archived' => false]);
    $this->person->update(['primary_site_id' => $outside->id]);
    $this->actingAs($viewer->fresh())->get($this->url)->assertNotFound();
    expect(app(HubDirectory::class)->people($viewer->fresh(), 'Accessible documents'))->toBe([]);
    $this->person->update(['primary_site_id' => $this->site->id, 'is_active' => false]);
    $this->get($this->url)->assertNotFound();
    $this->actingAs($this->reader->fresh())->putJson($this->url.'/profile', [...$this->payload, 'expected_version' => 1])->assertForbidden();
    $this->person->update(['is_active' => true]);
    $permission = Permission::where('key', 'company_hub.view')->sole();
    $this->reader->permissionOverrides()->syncWithoutDetaching([$permission->id => ['allowed' => false]]);
    $this->actingAs($this->reader->fresh())->get($this->url)->assertForbidden();
    $this->putJson($this->url.'/profile', [...$this->payload, 'expected_version' => 1])->assertForbidden();
});

test('reporting links never reveal outside site manager identities or bypass the reporting permission', function () {
    $manager = User::factory()->create(['role' => 'support_worker', 'approved_at' => now()]);
    $manager->roles()->attach(Role::where('name', 'support_worker')->firstOrFail());
    ensureCanonicalHrStaffProfile($manager, $this->site);
    $this->person->update(['manager_user_id' => $manager->id]);
    $permission = Permission::where('key', 'hr.orgchart.view')->sole();
    $this->reader->permissionOverrides()->syncWithoutDetaching([$permission->id => ['allowed' => false]]);
    expect(app(HubDirectory::class)->person($this->reader->fresh(), $this->person->id)['manager_id'])->toBeNull();
    $this->reader->permissionOverrides()->updateExistingPivot($permission->id, ['allowed' => true]);
    expect(app(HubDirectory::class)->person($this->reader->fresh(), $this->person->id)['manager_id'])->toBe($manager->hrEmployeeProfile()->sole()->id);
    $manager->hrEmployeeProfile()->update(['primary_site_id' => Site::factory()->create(['is_active' => true, 'archived' => false])->id]);
    expect(app(HubDirectory::class)->person($this->reader->fresh(), $this->person->id)['manager_id'])->toBeNull();
});
