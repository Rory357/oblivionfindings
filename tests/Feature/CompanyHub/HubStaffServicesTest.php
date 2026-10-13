<?php

use App\Domain\CompanyHub\Services\HubDirectory;
use App\Domain\CompanyHub\Services\HubServices;
use App\Domain\Hr\Models\HrDepartment;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use Database\Seeders\CompanyHubPermissionsSeeder;
use Database\Seeders\RbacSeeder;
use Database\Seeders\SeedHrPermissionsSeeder;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->seed([RbacSeeder::class, SeedHrPermissionsSeeder::class, CompanyHubPermissionsSeeder::class]);
    $this->reader = User::factory()->create(['role' => 'support_worker', 'approved_at' => now()]);
    $this->reader->roles()->attach(Role::where('name', 'support_worker')->firstOrFail());
    $this->site = ensureCanonicalHrStaffProfile($this->reader);
    foreach (['fleet.maintenance.report', 'fleet.maintenance.manage', 'fleet.manage'] as $key) {
        $permission = Permission::firstOrCreate(['key' => $key]);
        $this->reader->permissionOverrides()->syncWithoutDetaching([$permission->id => ['allowed' => false]]);
    }
});

test('staff services link to canonical self-service and global discovery includes those destinations', function () {
    $this->actingAs($this->reader)->get('/company-hub?view=services')->assertOk()->assertInertia(fn (Assert $page) => $page
        ->has('services.handoffs', 5)->where('services.handoffs.1.url', '/hr/my/leave')
        ->where('services.handoffs.2.url', '/hr/my/policies')->where('services.handoffs.3.url', '/hr/my/training')
        ->where('services.handoffs.4.url', '/hr/my/profile')->has('services.peopleContacts', 0));
    $this->get('/company-hub?search=leave')->assertOk()->assertInertia(fn (Assert $page) => $page
        ->has('searchServices', 1)->where('searchServices.0.id', 'staff:leave')->where('searchServices.0.url', '/hr/my/leave')
        ->missing('searchServices.0.form_schema')->where('services', null));
    foreach (['leave', 'policies', 'training', 'profile'] as $route) {
        $this->get('/hr/my/'.$route)->assertOk();
    }
});

test('maintenance discovery follows the reporting permission and rechecks revocation', function () {
    $service = app(HubServices::class);
    expect($service->handoffs($this->reader, 'maintenance'))->toBe([]);
    $permission = Permission::where('key', 'fleet.maintenance.report')->sole();
    $this->reader->permissionOverrides()->updateExistingPivot($permission->id, ['allowed' => true]);
    expect($service->handoffs($this->reader->fresh(), 'maintenance'))->toHaveCount(1)
        ->and($service->handoffs($this->reader->fresh(), 'maintenance')[0]['url'])->toBe('/fleet-assets/maintenance/work-orders/create');
    $this->actingAs($this->reader->fresh())->followingRedirects()->get('/fleet-assets/maintenance/work-orders/create')->assertOk();
    $this->reader->permissionOverrides()->updateExistingPivot($permission->id, ['allowed' => false]);
    expect($service->handoffs($this->reader->fresh(), 'maintenance'))->toBe([]);
    $this->actingAs($this->reader->fresh())->get('/fleet-assets/maintenance/work-orders/create')->assertForbidden();
});

test('people support contacts expose only current site-visible work profiles from active people departments', function () {
    $department = HrDepartment::create(['name' => 'People & Culture', 'code' => 'PC', 'is_active' => true]);
    $outside = Site::factory()->create(['is_active' => true, 'archived' => false]);
    foreach ([['name' => 'Visible contact', 'site' => $this->site, 'active' => true], ['name' => 'Other site contact', 'site' => $outside, 'active' => true], ['name' => 'Former contact', 'site' => $this->site, 'active' => false]] as $fixture) {
        $colleague = User::factory()->create(['name' => $fixture['name'], 'role' => 'hr', 'approved_at' => now()]);
        $colleague->roles()->attach(Role::where('name', 'hr')->firstOrFail());
        ensureCanonicalHrStaffProfile($colleague, $fixture['site'], ['preferred_name' => $fixture['name'], 'department_id' => $department->id, 'work_email' => 'work@company.test', 'bio' => 'Private HR biography', 'is_active' => $fixture['active']]);
    }
    $contacts = app(HubDirectory::class)->peopleSupportContacts($this->reader);
    expect($contacts)->toHaveCount(1)->and($contacts[0]['name'])->toBe('Visible contact')->and($contacts[0]['work_email'])->toBe('work@company.test')
        ->and($contacts[0])->not->toHaveKeys(['bio', 'date_of_birth', 'email', 'emergency_contacts']);
    $department->update(['is_active' => false]);
    expect(app(HubDirectory::class)->peopleSupportContacts($this->reader))->toBe([]);
});

test('former staff cannot use the service workspace or recover staff contacts', function () {
    $this->reader->hrEmployeeProfile()->update(['is_active' => false]);
    $this->actingAs($this->reader->fresh())->get('/company-hub?view=services')->assertForbidden();
    $this->get('/company-hub?search=leave')->assertForbidden();
});
