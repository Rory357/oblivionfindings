<?php

use App\Domain\Hr\Models\HrAsset;
use App\Domain\Hr\Models\HrAssetAssignment;
use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Database\Seeders\SeedHrPermissionsSeeder;

beforeEach(function () {
    $this->seed(RbacSeeder::class);
    $this->seed(SeedHrPermissionsSeeder::class);

    $this->allowedSite = Site::factory()->create();
    $this->hiddenSite = Site::factory()->create();

    $this->viewer = User::factory()->create([
        'role' => 'hr',
        'approved_at' => now(),
    ]);
    $this->viewer->roles()->syncWithoutDetaching([
        Role::query()->where('name', 'hr')->firstOrFail()->id,
    ]);
    HrEmployeeProfile::factory()->create([
        'user_id' => $this->viewer->id,
        'primary_site_id' => $this->allowedSite->id,
        'is_active' => true,
        'start_date' => today()->subYear(),
    ]);

    $this->allowedEmployee = User::factory()->create(['approved_at' => now()]);
    $this->allowedProfile = HrEmployeeProfile::factory()->create([
        'user_id' => $this->allowedEmployee->id,
        'primary_site_id' => $this->allowedSite->id,
        'is_active' => true,
        'start_date' => today()->subYear(),
    ]);
    $this->hiddenEmployee = User::factory()->create(['approved_at' => now()]);
    $this->hiddenProfile = HrEmployeeProfile::factory()->create([
        'user_id' => $this->hiddenEmployee->id,
        'primary_site_id' => $this->hiddenSite->id,
        'is_active' => true,
        'start_date' => today()->subYear(),
    ]);
});

function canonicalHrAsset(string $tag, string $status = 'available'): HrAsset
{
    return HrAsset::query()->create([
        'asset_tag' => $tag,
        'name' => "Equipment {$tag}",
        'category' => 'uniform',
        'status' => $status,
        'qr_token' => 'qr-'.$tag,
    ]);
}

function assignCanonicalHrAsset(HrAsset $asset, HrEmployeeProfile $profile, User $actor): HrAssetAssignment
{
    return HrAssetAssignment::query()->create([
        'asset_id' => $asset->id,
        'employee_profile_id' => $profile->id,
        'assigned_at' => now()->subWeek(),
        'assigned_by' => $actor->id,
    ]);
}

function setCanonicalAssetPermission(User $user, string $key, bool $allowed): void
{
    $user->permissionOverrides()->syncWithoutDetaching([
        Permission::query()->where('key', $key)->firstOrFail()->id => ['allowed' => $allowed],
    ]);
}

test('the hub counts lists and staff picker follow canonical Site provenance', function () {
    $allowed = canonicalHrAsset('ALLOW-001', 'assigned');
    assignCanonicalHrAsset($allowed, $this->allowedProfile, $this->viewer);

    $hidden = canonicalHrAsset('HIDDEN-001', 'assigned');
    assignCanonicalHrAsset($hidden, $this->hiddenProfile, $this->viewer);

    $mixed = canonicalHrAsset('MIXED-001', 'assigned');
    assignCanonicalHrAsset($mixed, $this->allowedProfile, $this->viewer);
    assignCanonicalHrAsset($mixed, $this->hiddenProfile, $this->viewer);

    canonicalHrAsset('STOCK-001');
    $inactive = HrEmployeeProfile::factory()->create([
        'primary_site_id' => $this->allowedSite->id,
        'is_active' => false,
        'end_date' => today()->subDay(),
    ]);

    $this->actingAs($this->viewer)
        ->get('/hr/assets')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('hero.total', 2)
            ->where('hero.assigned', 1)
            ->where('hero.available', 1)
            ->where('hero.site_count', 1)
            ->where('inventory', fn ($rows): bool => collect($rows)->pluck('tag')->sort()->values()->all() === [
                'ALLOW-001',
                'STOCK-001',
            ])
            ->where('staff', fn ($rows): bool => collect($rows)->pluck('id')->contains($this->allowedProfile->id)
                && ! collect($rows)->pluck('id')->contains($this->hiddenProfile->id)
                && ! collect($rows)->pluck('id')->contains($inactive->id)));
});

test('hidden and mixed assets are concealed across direct mutation QR bulk and export paths', function () {
    $visible = canonicalHrAsset('VISIBLE-001', 'assigned');
    assignCanonicalHrAsset($visible, $this->allowedProfile, $this->viewer);
    $hidden = canonicalHrAsset('SECRET-FORMULA-001', 'assigned');
    $hiddenAssignment = assignCanonicalHrAsset($hidden, $this->hiddenProfile, $this->viewer);
    $mixed = canonicalHrAsset('MIXED-SECRET-001', 'assigned');
    assignCanonicalHrAsset($mixed, $this->allowedProfile, $this->viewer);
    assignCanonicalHrAsset($mixed, $this->hiddenProfile, $this->viewer);

    $this->actingAs($this->viewer)->get("/hr/assets/{$hidden->id}")->assertNotFound();
    $this->actingAs($this->viewer)
        ->post("/hr/assets/{$hidden->id}/maintenance", ['type' => 'repair'])
        ->assertNotFound();
    $this->actingAs($this->viewer)
        ->get("/hr/assets/qr/{$hidden->qr_token}")
        ->assertNotFound();
    $this->actingAs($this->viewer)
        ->post("/hr/assets/assignments/{$hiddenAssignment->id}/return", [
            'returned_at' => today()->toDateString(),
        ])
        ->assertNotFound();

    $this->actingAs($this->viewer)
        ->post('/hr/assets/bulk', [
            'action' => 'retire',
            'ids' => [$visible->id, $hidden->id],
        ])
        ->assertNotFound();
    expect($visible->fresh()->status)->toBe('assigned')
        ->and($hidden->fresh()->status)->toBe('assigned')
        ->and($hiddenAssignment->fresh()->returned_at)->toBeNull();

    $csv = $this->actingAs($this->viewer)->get('/hr/assets/export');
    $csv->assertOk();
    expect($csv->streamedContent())
        ->toContain('VISIBLE-001')
        ->not->toContain('SECRET-FORMULA-001')
        ->not->toContain('MIXED-SECRET-001');
});

test('unassigned stock and all-Sites visibility require their explicit permissions', function () {
    $stock = canonicalHrAsset('CENTRAL-STOCK-001');
    $hidden = canonicalHrAsset('ALL-SITES-001', 'assigned');
    assignCanonicalHrAsset($hidden, $this->hiddenProfile, $this->viewer);

    setCanonicalAssetPermission($this->viewer, 'hr.assets.viewUnassigned', false);
    $this->actingAs($this->viewer->fresh())
        ->get('/hr/assets')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('inventory', [])
            ->where('hero.total', 0));

    setCanonicalAssetPermission($this->viewer, 'hr.assets.viewAllSites', true);
    $this->actingAs($this->viewer->fresh())
        ->get('/hr/assets')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('inventory', fn ($rows): bool => collect($rows)->pluck('tag')->all() === ['ALL-SITES-001'])
            ->where('hero.site_count', 2));

    $this->actingAs($this->viewer->fresh())
        ->get("/hr/assets/{$stock->id}")
        ->assertNotFound();
});

test('assignment targets use current approved staff and hidden IDs share the not-found path', function () {
    $asset = canonicalHrAsset('ASSIGN-001');

    $hidden = $this->actingAs($this->viewer)
        ->post("/hr/assets/{$asset->id}/assign", [
            'employee_profile_id' => $this->hiddenProfile->id,
            'assigned_at' => today()->toDateString(),
        ]);
    $missing = $this->actingAs($this->viewer)
        ->post("/hr/assets/{$asset->id}/assign", [
            'employee_profile_id' => 99999999,
            'assigned_at' => today()->toDateString(),
        ]);

    $hidden->assertNotFound();
    $missing->assertNotFound();
    expect($hidden->getContent())->toBe($missing->getContent())
        ->and($asset->fresh()->status)->toBe('available')
        ->and(HrAssetAssignment::query()->where('asset_id', $asset->id)->exists())->toBeFalse();
});
