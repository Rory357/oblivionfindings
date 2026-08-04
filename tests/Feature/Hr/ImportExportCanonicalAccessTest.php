<?php

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Domain\Hr\Services\EmployeeImportExportService;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Database\Seeders\SeedHrPermissionsSeeder;
use Illuminate\Http\UploadedFile;

beforeEach(function (): void {
    $this->seed(RbacSeeder::class);
    $this->seed(SeedHrPermissionsSeeder::class);

    $this->site = Site::factory()->create(['name' => 'Import visible Site']);
    $this->hiddenSite = Site::factory()->create(['name' => 'Import hidden Site']);
    $this->manager = User::factory()->create([
        'name' => 'Import manager',
        'role' => 'hr',
        'approved_at' => now(),
    ]);
    $this->manager->roles()->sync([
        Role::query()->where('name', 'hr')->firstOrFail()->id,
    ]);
    importExportCanonicalProfile($this->manager, $this->site, [
        'employee_number' => 'EMP-IMPORT-MANAGER',
    ]);
});

function importExportCanonicalProfile(User $user, Site $site, array $overrides = []): HrEmployeeProfile
{
    return HrEmployeeProfile::factory()->create([
        'user_id' => $user->id,
        'employee_number' => 'EMP-IMPORT-'.$user->id,
        'primary_site_id' => $site->id,
        'is_active' => true,
        ...$overrides,
    ]);
}

function importExportCsv(array $rows): string
{
    $stream = fopen('php://temp', 'r+');
    fputcsv($stream, [
        'employee_number',
        'name',
        'email',
        'position_title',
        'position_role',
        'department',
        'primary_site_id',
        'employment_type',
        'start_date',
        'hours_per_week',
        'is_active',
    ]);
    foreach ($rows as $row) {
        fputcsv($stream, $row);
    }
    rewind($stream);
    $csv = stream_get_contents($stream);
    fclose($stream);

    return $csv;
}

test('import export register counts and exports use canonical Site visibility', function (): void {
    $visible = User::factory()->create([
        'name' => 'Visible export worker',
        'email' => 'visible-export@example.test',
        'role' => 'support_worker',
        'approved_at' => now(),
    ]);
    importExportCanonicalProfile($visible, $this->site);

    $former = User::factory()->create([
        'name' => 'Former selected worker',
        'email' => 'former-export@example.test',
        'role' => 'support_worker',
        'approved_at' => null,
    ]);
    importExportCanonicalProfile($former, $this->site, [
        'is_active' => false,
        'end_date' => now()->subDay(),
    ]);

    $hidden = User::factory()->create([
        'name' => 'Hidden export worker',
        'email' => 'hidden-export@example.test',
        'role' => 'support_worker',
        'approved_at' => now(),
    ]);
    importExportCanonicalProfile($hidden, $this->hiddenSite);

    $page = $this->actingAs($this->manager)->get('/hr/import-export')->assertOk();
    $page->assertInertia(fn ($inertia) => $inertia
        ->where('stats.exportable', 2)
        ->where('stats.profiles', 3)
        ->has('sites', 1)
        ->where('sites.0.id', $this->site->id));

    $all = $this->actingAs($this->manager)
        ->post('/hr/import-export/export')
        ->assertOk()
        ->streamedContent();
    expect($all)->toContain('visible-export@example.test')
        ->not->toContain('former-export@example.test')
        ->not->toContain('hidden-export@example.test');

    $selected = $this->actingAs($this->manager)
        ->post('/hr/import-export/export', ['ids' => [$former->id, $hidden->id]])
        ->assertOk()
        ->streamedContent();
    expect($selected)->toContain('former-export@example.test')
        ->not->toContain('hidden-export@example.test');
});

test('employee import creates only visible Site profiles and conceals hidden identities', function (): void {
    $hidden = User::factory()->create([
        'name' => 'Hidden original name',
        'email' => 'hidden-import@example.test',
        'role' => 'support_worker',
        'approved_at' => now(),
    ]);
    $hiddenProfile = importExportCanonicalProfile($hidden, $this->hiddenSite, [
        'position_title' => 'Hidden original position',
    ]);
    $client = User::factory()->create([
        'name' => 'Existing client',
        'email' => 'client-import@example.test',
        'role' => 'client',
    ]);

    $csv = importExportCsv([
        ['EMP-NEW', 'New visible worker', 'new-import@example.test', 'Coordinator', 'coordinator', 'Operations', $this->site->id, 'full_time', '2026-07-01', '40', '1'],
        ['EMP-HIDDEN', 'Hidden changed name', $hidden->email, 'Changed position', 'support_worker', 'Operations', $this->site->id, 'full_time', '2026-07-01', '40', '1'],
        ['EMP-HIDDEN-SITE', 'Hidden Site worker', 'hidden-site-new@example.test', 'Worker', 'support_worker', 'Operations', $this->hiddenSite->id, 'full_time', '2026-07-01', '40', '1'],
        ['EMP-CLIENT', 'Client changed name', $client->email, 'Worker', 'support_worker', 'Operations', $this->site->id, 'full_time', '2026-07-01', '40', '1'],
    ]);

    $response = $this->actingAs($this->manager)
        ->post('/hr/import-export/import', [
            'file' => UploadedFile::fake()->createWithContent('employees.csv', $csv),
        ])
        ->assertSessionHas('importResult');
    $result = $response->getSession()->get('importResult');
    expect($result)->toBeArray()
        ->and($result['created'])->toBe(1)
        ->and($result['updated'])->toBe(0)
        ->and($result['errors'])->toHaveCount(3);

    $created = User::query()->where('email', 'new-import@example.test')->firstOrFail();
    $createdProfile = HrEmployeeProfile::query()->where('user_id', $created->id)->firstOrFail();
    expect($created->role)->toBe('staff')
        ->and($created->approved_at)->toBeNull()
        ->and($createdProfile->primary_site_id)->toBe($this->site->id)
        ->and($hidden->fresh()->name)->toBe('Hidden original name')
        ->and($hiddenProfile->fresh()->position_title)->toBe('Hidden original position')
        ->and($client->fresh()->name)->toBe('Existing client')
        ->and(User::query()->where('email', 'hidden-site-new@example.test')->exists())->toBeFalse();
});

test('employee import updates visible profiles and the template requires Site provenance', function (): void {
    $employee = User::factory()->create([
        'name' => 'Original import worker',
        'email' => 'update-import@example.test',
        'role' => 'support_worker',
        'approved_at' => now(),
    ]);
    $profile = importExportCanonicalProfile($employee, $this->site, [
        'position_title' => 'Original role',
    ]);
    $csv = importExportCsv([
        ['EMP-UPDATED', 'Updated import worker', $employee->email, 'Updated role', 'team_lead', 'Services', $this->site->id, 'part_time', '2026-07-02', '24', '1'],
    ]);

    $this->actingAs($this->manager)
        ->post('/hr/import-export/import', [
            'file' => UploadedFile::fake()->createWithContent('employees.csv', $csv),
        ])
        ->assertSessionHas('importResult', fn (array $result): bool => $result['created'] === 0
            && $result['updated'] === 1
            && $result['errors'] === []);

    expect($employee->fresh()->name)->toBe('Updated import worker')
        ->and($profile->fresh()->position_title)->toBe('Updated role')
        ->and($profile->fresh()->hours_per_week)->toBe('24.00')
        ->and($profile->fresh()->primary_site_id)->toBe($this->site->id);

    $template = $this->actingAs($this->manager)
        ->get('/hr/import-export/template')
        ->assertOk()
        ->streamedContent();
    expect(str_getcsv(trim($template)))->toContain('primary_site_id');

    $result = app(EmployeeImportExportService::class)->importFromCsv(
        "name,email,email,position_role,primary_site_id\nDuplicate,duplicate@example.test,duplicate@example.test,support_worker,{$this->site->id}\n",
        $this->manager,
    );
    expect($result['created'])->toBe(0)
        ->and($result['errors'])->toBe(['CSV headers must be unique.']);
});
