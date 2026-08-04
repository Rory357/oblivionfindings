<?php

use App\Domain\Hr\Models\HrComplianceRequirement;
use App\Domain\Hr\Models\HrDevelopmentGoal;
use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Domain\Hr\Models\HrKudos;
use App\Domain\Hr\Models\HrStaffComplianceStatus;
use App\Http\Controllers\Hr\DirectoryController;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Database\Seeders\SeedHrPermissionsSeeder;
use Illuminate\Support\Facades\Storage;

/** @return list<string> */
function directoryPrivacyLegacyPartitionKeys(): array
{
    return [
        'ten'.'ant_id',
        'organi'.'zation_id',
    ];
}

beforeEach(function () {
    $this->seed(RbacSeeder::class);
    $this->seed(SeedHrPermissionsSeeder::class);

    $this->allowedSite = Site::factory()->create(['name' => 'Allowed Directory Site']);
    $this->hiddenSite = Site::factory()->create(['name' => 'Hidden Directory Site']);
    $this->viewer = directoryPrivacyUser('Directory Viewer', $this->allowedSite);
});

function directoryPrivacyUser(
    string $name,
    ?Site $site,
    array $profileOverrides = [],
    array $userOverrides = [],
): User {
    $user = User::factory()->create([
        'name' => $name,
        'email' => str($name)->slug().'-'.str()->random(6).'@private.example.test',
        'role' => 'support_worker',
        'approved_at' => now(),
        ...$userOverrides,
    ]);
    $user->roles()->syncWithoutDetaching([
        Role::query()->where('name', $user->role === 'hr' ? 'hr' : 'support_worker')->firstOrFail()->id,
    ]);
    HrEmployeeProfile::query()->create([
        'user_id' => $user->id,
        'employee_number' => 'EMP-DIR-'.$user->id,
        'work_email' => str($name)->slug().'@work.example.test',
        'work_phone' => '09 555 '.str_pad((string) $user->id, 4, '0', STR_PAD_LEFT),
        'position_title' => 'Support Worker',
        'position_role' => 'support_worker',
        'employment_type' => 'full_time',
        'start_date' => now()->subYear()->toDateString(),
        'end_date' => null,
        'is_active' => true,
        'primary_site_id' => $site?->id,
        'secondary_site_ids' => [],
        ...$profileOverrides,
    ]);

    return $user;
}

function grantDirectoryPermission(User $user, string $permission): void
{
    $user->permissionOverrides()->syncWithoutDetaching([
        Permission::query()->where('key', $permission)->firstOrFail()->id => ['allowed' => true],
    ]);
    $user->unsetRelation('permissionOverrides');
}

test('directory direct cards expose only current approved staff at viewer accessible Sites', function () {
    $allowed = directoryPrivacyUser('Allowed Current Directory Person', $this->allowedSite);
    $hidden = directoryPrivacyUser('Hidden Directory Person', $this->hiddenSite);
    $ended = directoryPrivacyUser('Ended Directory Person', $this->allowedSite, [
        'end_date' => now()->subDay()->toDateString(),
    ]);
    $future = directoryPrivacyUser('Future Directory Person', $this->allowedSite, [
        'start_date' => now()->addDay()->toDateString(),
    ]);
    $inactive = directoryPrivacyUser('Inactive Directory Person', $this->allowedSite, [
        'is_active' => false,
    ]);
    $unapproved = directoryPrivacyUser('Unapproved Directory Person', $this->allowedSite, [], [
        'approved_at' => null,
    ]);
    $missingProvenance = directoryPrivacyUser('Unproven Directory Person', null);
    $client = directoryPrivacyUser('Client Directory Person', $this->allowedSite, [], [
        'role' => 'client',
    ]);

    $this->actingAs($this->viewer)
        ->getJson('/hr/directory/'.$allowed->hrEmployeeProfile->id)
        ->assertOk();

    foreach ([$hidden, $ended, $future, $inactive, $unapproved, $missingProvenance, $client] as $concealed) {
        $this->actingAs($this->viewer)
            ->getJson('/hr/directory/'.$concealed->hrEmployeeProfile->id)
            ->assertNotFound();
    }

    $this->actingAs($this->viewer)
        ->getJson('/hr/directory/99999999')
        ->assertNotFound();
});

test('directory routes use bounded numeric IDs and manual canonical lookup instead of implicit model binding', function () {
    $routes = file_get_contents(base_path('routes/hr.php'));

    expect(substr_count($routes, "->whereNumber('profile')"))->toBeGreaterThanOrEqual(3);
    foreach (['show', 'photo', 'uploadPhoto'] as $method) {
        $profileParameter = (new ReflectionMethod(DirectoryController::class, $method))
            ->getParameters()[1];
        expect($profileParameter->getType()?->getName())->toBe('string');
    }
});

test('hidden missing noncurrent malformed and oversized directory IDs have identical concealment responses', function () {
    config(['app.debug' => false]);
    $hidden = directoryPrivacyUser('Hidden Parity Directory Person', $this->hiddenSite);
    $ended = directoryPrivacyUser('Ended Parity Directory Person', $this->allowedSite, [
        'end_date' => now()->subDay()->toDateString(),
    ]);

    $responses = [
        $this->actingAs($this->viewer)->getJson('/hr/directory/'.$hidden->hrEmployeeProfile->id),
        $this->actingAs($this->viewer)->getJson('/hr/directory/'.$ended->hrEmployeeProfile->id),
        $this->actingAs($this->viewer)->getJson('/hr/directory/99999999'),
        $this->actingAs($this->viewer)->getJson('/hr/directory/not-a-number'),
        $this->actingAs($this->viewer)->getJson('/hr/directory/'.str_repeat('9', 80)),
    ];

    $shapes = collect($responses)->map(fn ($response) => [
        'status' => $response->status(),
        'body' => $response->json(),
    ]);

    expect($shapes->unique()->values()->all())->toBe([
        ['status' => 404, 'body' => ['message' => '']],
    ]);
});

test('directory viewers must themselves be current approved staff with canonical Site provenance', function () {
    $target = directoryPrivacyUser('Visible Directory Target', $this->allowedSite);
    $profileless = User::factory()->create([
        'role' => 'support_worker',
        'approved_at' => now(),
    ]);
    $ended = directoryPrivacyUser('Ended Directory Viewer', $this->allowedSite, [
        'end_date' => now()->subDay()->toDateString(),
    ]);
    $external = directoryPrivacyUser('External Directory Viewer', $this->allowedSite, [], [
        'role' => 'client',
    ]);

    foreach ([$profileless, $ended, $external] as $concealedViewer) {
        $this->actingAs($concealedViewer)
            ->getJson('/hr/directory/'.$target->hrEmployeeProfile->id)
            ->assertNotFound();
    }
});

test('ordinary directory cards expose work contact but no personal performance or storage data', function () {
    $target = directoryPrivacyUser('Private Directory Target', $this->allowedSite, [
        'work_email' => 'directory.work@example.test',
        'work_phone' => '09 555 1212',
        'personal_email' => 'personal-target@example.test',
        'personal_phone' => '021 555 1212',
        'emergency_contacts' => [['name' => 'Private Emergency Person', 'phone' => '021 111 2222']],
        'restricted_notes' => 'Private restricted note',
        'notes' => 'Private HR note',
    ]);
    HrDevelopmentGoal::query()->create([
        'employee_user_id' => $target->id,
        'title' => 'Private development goal',
        'status' => 'in_progress',
        'created_by' => $this->viewer->id,
    ]);

    $response = $this->actingAs($this->viewer)
        ->getJson('/hr/directory/'.$target->hrEmployeeProfile->id)
        ->assertOk()
        ->assertJsonPath('employee.email', 'directory.work@example.test')
        ->assertJsonPath('employee.work_phone', '09 555 1212')
        ->assertJsonPath('employee.personal_email', null)
        ->assertJsonPath('employee.personal_phone', null)
        ->assertJsonPath('goals', null)
        ->assertJsonPath('complianceSummary', null);

    expect($response->json('employee'))
        ->not->toHaveKeys([
            ...directoryPrivacyLegacyPartitionKeys(),
            'emergency_contacts',
            'notes',
            'restricted_notes',
            'date_of_birth',
            'home_address',
            'bank_account',
            'ird_number',
            'licence_number',
            'vetting',
            'disclosures',
        ]);
    expect($response->getContent())
        ->not->toContain(
            $target->email,
            'personal-target@example.test',
            '021 555 1212',
            'Private Emergency Person',
            'Private restricted note',
            'Private HR note',
            'Private development goal',
        );
});

test('directory sensitive compliance and performance fields require their own narrow permissions', function () {
    $target = directoryPrivacyUser('Scoped Directory Target', $this->allowedSite, [
        'personal_email' => 'scoped.personal@example.test',
        'personal_phone' => '021 400 500',
    ]);
    $requirement = HrComplianceRequirement::query()->create([
        'code' => 'DIR-COMPLIANCE',
        'name' => 'Directory compliance requirement',
        'category' => 'training',
        'check_type' => 'manual',
        'hard_stop' => false,
        'is_active' => true,
        'created_by' => $this->viewer->id,
    ]);
    HrStaffComplianceStatus::query()->create([
        'user_id' => $target->id,
        'requirement_id' => $requirement->id,
        'status' => 'expired',
        'notes' => 'Secret compliance evidence note',
    ]);
    HrDevelopmentGoal::query()->create([
        'employee_user_id' => $target->id,
        'title' => 'Permission bounded goal',
        'status' => 'in_progress',
        'progress_percent' => 20,
        'created_by' => $this->viewer->id,
    ]);

    grantDirectoryPermission($this->viewer, 'hr.compliance.view');
    $compliance = $this->actingAs($this->viewer)
        ->getJson('/hr/directory/'.$target->hrEmployeeProfile->id)
        ->assertOk()
        ->assertJsonPath('complianceSummary.expired', 1)
        ->assertJsonPath('complianceSummary.total', 1)
        ->assertJsonPath('employee.personal_email', null)
        ->assertJsonPath('employee.personal_phone', null)
        ->assertJsonPath('goals', null);
    expect($compliance->getContent())
        ->not->toContain('Secret compliance evidence note', 'Permission bounded goal', 'scoped.personal@example.test');

    grantDirectoryPermission($this->viewer, 'hr.goals.view');
    $performance = $this->actingAs($this->viewer)
        ->getJson('/hr/directory/'.$target->hrEmployeeProfile->id)
        ->assertOk()
        ->assertJsonPath('goals.0.title', 'Permission bounded goal')
        ->assertJsonPath('employee.personal_email', null);

    grantDirectoryPermission($this->viewer, 'hr.employees.viewRestricted');
    $this->actingAs($this->viewer)
        ->getJson('/hr/directory/'.$target->hrEmployeeProfile->id)
        ->assertOk()
        ->assertJsonPath('employee.personal_email', 'scoped.personal@example.test')
        ->assertJsonPath('employee.personal_phone', '021 400 500');
});

test('directory reporting lines and recognition use the same visible current staff boundary', function () {
    $allowedManager = directoryPrivacyUser('Allowed Directory Manager', $this->allowedSite);
    $target = directoryPrivacyUser('Directory Reporting Target', $this->allowedSite, [
        'manager_user_id' => $allowedManager->id,
    ]);
    $allowedReport = directoryPrivacyUser('Allowed Current Report', $this->allowedSite, [
        'manager_user_id' => $target->id,
    ]);
    directoryPrivacyUser('Ended Directory Report', $this->allowedSite, [
        'manager_user_id' => $target->id,
        'end_date' => now()->subDay()->toDateString(),
    ]);
    directoryPrivacyUser('Hidden Directory Report', $this->hiddenSite, [
        'manager_user_id' => $target->id,
    ]);
    $allowedSender = directoryPrivacyUser('Allowed Kudos Sender', $this->allowedSite);
    $hiddenSender = directoryPrivacyUser('Hidden Kudos Sender', $this->hiddenSite);

    foreach ([
        [$allowedSender, 'Visible directory recognition'],
        [$hiddenSender, 'Hidden directory recognition'],
    ] as [$sender, $message]) {
        HrKudos::query()->create([
            'from_user_id' => $sender->id,
            'to_user_id' => $target->id,
            'category' => 'teamwork',
            'message' => $message,
            'is_public' => true,
        ]);
    }

    $withoutOrgChart = $this->actingAs($this->viewer)
        ->getJson('/hr/directory/'.$target->hrEmployeeProfile->id)
        ->assertOk()
        ->assertJsonPath('manager', null)
        ->assertJsonCount(0, 'directReports')
        ->assertJsonCount(1, 'kudosReceived')
        ->assertJsonPath('kudosReceived.0.from_name', 'Allowed Kudos Sender')
        ->assertJsonPath('kudosCount', 1);

    expect($withoutOrgChart->getContent())
        ->not->toContain('Allowed Directory Manager', 'Allowed Current Report', 'Ended Directory Report', 'Hidden Directory Report', 'Hidden directory recognition');

    grantDirectoryPermission($this->viewer, 'hr.orgchart.view');
    $withOrgChart = $this->actingAs($this->viewer)
        ->getJson('/hr/directory/'.$target->hrEmployeeProfile->id)
        ->assertOk()
        ->assertJsonPath('manager.name', 'Allowed Directory Manager')
        ->assertJsonCount(1, 'directReports')
        ->assertJsonPath('directReports.0.id', $allowedReport->hrEmployeeProfile->id)
        ->assertJsonCount(1, 'kudosReceived')
        ->assertJsonPath('kudosReceived.0.from_name', 'Allowed Kudos Sender')
        ->assertJsonPath('kudosCount', 1);

    expect($withOrgChart->getContent())
        ->not->toContain('Ended Directory Report', 'Hidden Directory Report', 'Hidden directory recognition');

    $recognitionPermission = Permission::query()->where('key', 'hr.recognition.view')->firstOrFail();
    $this->viewer->permissionOverrides()->syncWithoutDetaching([
        $recognitionPermission->id => ['allowed' => false],
    ]);
    $this->viewer->unsetRelation('permissionOverrides');

    $this->actingAs($this->viewer)
        ->getJson('/hr/directory/'.$target->hrEmployeeProfile->id)
        ->assertOk()
        ->assertJsonCount(0, 'kudosReceived')
        ->assertJsonPath('kudosCount', 0);
});

test('an allowed secondary Site permits the card without exposing its hidden primary Site', function () {
    $target = directoryPrivacyUser('Multi Site Directory Person', $this->hiddenSite, [
        'secondary_site_ids' => [$this->allowedSite->id],
    ]);

    $response = $this->actingAs($this->viewer)
        ->getJson('/hr/directory/'.$target->hrEmployeeProfile->id)
        ->assertOk()
        ->assertJsonPath('employee.site', null);

    expect($response->getContent())->not->toContain('Hidden Directory Site');
});

test('directory photos are served through an authorised URL without exposing storage paths', function () {
    Storage::fake('private');
    Storage::fake('public');

    $manager = directoryPrivacyUser('Photo URL Manager', $this->allowedSite);
    $target = directoryPrivacyUser('Photo URL Target', $this->allowedSite, [
        'manager_user_id' => $manager->id,
    ]);
    $report = directoryPrivacyUser('Photo URL Report', $this->allowedSite, [
        'manager_user_id' => $target->id,
    ]);
    $paths = [
        $manager->hrEmployeeProfile->id => 'manager-photo.jpg',
        $target->hrEmployeeProfile->id => 'target-photo.jpg',
        $report->hrEmployeeProfile->id => 'report-photo.jpg',
    ];
    foreach ($paths as $profileId => $filename) {
        $path = "hr/photos/{$profileId}/{$filename}";
        HrEmployeeProfile::query()->whereKey($profileId)->update(['profile_photo_path' => $path]);
    }
    $targetPath = 'hr/photos/'.$target->hrEmployeeProfile->id.'/'.$paths[$target->hrEmployeeProfile->id];
    Storage::disk('private')->put($targetPath, 'photo-'.$target->hrEmployeeProfile->id);
    grantDirectoryPermission($this->viewer, 'hr.orgchart.view');

    $response = $this->actingAs($this->viewer)
        ->getJson('/hr/directory/'.$target->hrEmployeeProfile->id)
        ->assertOk()
        ->assertJsonPath('employee.profile_photo_url', route('hr.directory.photo', $target->hrEmployeeProfile->id));

    expect($response->json('employee'))->not->toHaveKey('profile_photo_path')
        ->and($response->json('manager'))->not->toHaveKey('profile_photo_path')
        ->and($response->json('directReports.0'))->not->toHaveKey('profile_photo_path')
        ->and($response->json('manager'))->not->toHaveKey('profile_photo_url')
        ->and($response->json('directReports.0'))->not->toHaveKey('profile_photo_url')
        ->and($response->getContent())->not->toContain('hr/photos/', 'target-photo.jpg');

    $this->actingAs($this->viewer)
        ->get(route('hr.directory.photo', $target->hrEmployeeProfile->id))
        ->assertOk()
        ->assertStreamedContent('photo-'.$target->hrEmployeeProfile->id);
});

test('directory photo reads conceal hidden missing noncurrent and malformed profile IDs identically', function () {
    Storage::fake('private');
    config(['app.debug' => false]);
    Storage::fake('public');

    $hidden = directoryPrivacyUser('Hidden Photo Read', $this->hiddenSite);
    $ended = directoryPrivacyUser('Ended Photo Read', $this->allowedSite, [
        'end_date' => now()->subDay()->toDateString(),
    ]);
    $responses = [
        $this->actingAs($this->viewer)->getJson('/hr/directory/'.$hidden->hrEmployeeProfile->id.'/photo'),
        $this->actingAs($this->viewer)->getJson('/hr/directory/'.$ended->hrEmployeeProfile->id.'/photo'),
        $this->actingAs($this->viewer)->getJson('/hr/directory/99999999/photo'),
        $this->actingAs($this->viewer)->getJson('/hr/directory/not-a-number/photo'),
        $this->actingAs($this->viewer)->getJson('/hr/directory/'.str_repeat('9', 80).'/photo'),
    ];

    expect(collect($responses)->map(fn ($response) => [
        'status' => $response->status(),
        'body' => $response->json(),
    ])->unique()->values()->all())->toBe([
        ['status' => 404, 'body' => ['message' => '']],
    ]);
});

test('protected photo reads retain legacy public compatibility until the migration runs', function () {
    Storage::fake('private');
    Storage::fake('public');

    $target = directoryPrivacyUser('Legacy Photo Target', $this->allowedSite);
    $path = 'hr/photos/'.$target->hrEmployeeProfile->id.'/legacy.jpg';
    $target->hrEmployeeProfile->update(['profile_photo_path' => $path]);
    Storage::disk('public')->put($path, 'legacy-photo-bytes');

    $this->actingAs($this->viewer)
        ->get(route('hr.directory.photo', $target->hrEmployeeProfile->id))
        ->assertOk()
        ->assertStreamedContent('legacy-photo-bytes');

    Storage::disk('private')->assertMissing($path);
    Storage::disk('public')->assertExists($path);
});
