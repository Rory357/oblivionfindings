<?php

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Models\AssetGeofence;
use App\Models\GeofenceZone;
use App\Models\Permission;
use App\Models\Site;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;

function operationsGeofenceUser(Site $site, array $permissionKeys): User
{
    $user = User::factory()->create(['approved_at' => now()]);
    HrEmployeeProfile::query()->create([
        'user_id' => $user->id,
        'employee_number' => 'EMP-GEOFENCE-'.$user->id,
        'work_email' => $user->email,
        'position_title' => 'Geofence Coordinator',
        'position_role' => 'operations',
        'employment_type' => 'full_time',
        'start_date' => now()->subMonth()->toDateString(),
        'is_active' => true,
        'primary_site_id' => $site->id,
        'secondary_site_ids' => [],
    ]);

    foreach ($permissionKeys as $key) {
        $permission = Permission::query()->firstOrCreate(
            ['key' => $key],
            ['description' => $key],
        );
        $user->permissionOverrides()->syncWithoutDetaching([
            $permission->id => ['allowed' => true],
        ]);
    }

    return $user;
}

function operationsSiteGeofence(Site $site, string $name): AssetGeofence
{
    return AssetGeofence::query()->create([
        'asset_id' => null,
        'site_id' => $site->id,
        'name' => $name,
        'type' => 'circle',
        'scope' => 'house',
        'shape' => [
            'center' => ['lat' => -36.8485, 'lng' => 174.7633],
            'radius_m' => 100,
        ],
        'breach_type' => 'both',
        'is_active' => true,
    ]);
}

it('shows only canonical Site geofences and never writes the retired store', function () {
    $assignedSite = Site::factory()->create();
    $otherSite = Site::factory()->create();
    $user = operationsGeofenceUser($assignedSite, [
        'evv.viewAny',
        'assets.geofences.manage',
        'sites.viewAny',
    ]);
    $ownGeofence = operationsSiteGeofence($assignedSite, 'Accessible Site boundary');
    operationsSiteGeofence($otherSite, 'Other Site boundary');
    $legacyId = 900001;
    DB::table('geofence_zones')->insert([
        'id' => $legacyId,
        'organization_id' => 1,
        'name' => 'Retired duplicate zone',
        'site_id' => $assignedSite->id,
        'latitude' => -36.85,
        'longitude' => 174.76,
        'radius_meters' => 75,
        'is_active' => true,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $this->actingAs($user)
        ->get(route('operations.geofences.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('geofences.total', 1)
            ->where('geofences.data.0.id', $ownGeofence->id)
            ->has('sites', 1)
            ->where('sites.0.id', $assignedSite->id)
            ->where('canManage', true));

    $canonicalCount = AssetGeofence::query()->count();
    $legacyCount = DB::table('geofence_zones')->count();
    $legacyPayload = [
        'site_id' => $assignedSite->id,
        'name' => 'Attempted duplicate',
        'latitude' => -36.84,
        'longitude' => 174.75,
        'radius' => 120,
        'is_active' => true,
    ];

    $this->actingAs($user)
        ->post(route('operations.geofences.store'), $legacyPayload)
        ->assertRedirect(route('sites.show', $assignedSite));
    $this->actingAs($user)
        ->put(route('operations.geofences.update', $legacyId), $legacyPayload)
        ->assertRedirect(route('operations.geofences.index'));
    $this->actingAs($user)
        ->delete(route('operations.geofences.destroy', $legacyId))
        ->assertRedirect(route('operations.geofences.index'));

    expect(AssetGeofence::query()->count())->toBe($canonicalCount)
        ->and(DB::table('geofence_zones')->count())->toBe($legacyCount)
        ->and(DB::table('geofence_zones')->where('id', $legacyId)->value('name'))
        ->toBe('Retired duplicate zone');

    $retired = new GeofenceZone;
    $retired->forceFill([
        'name' => 'Blocked model write',
        'latitude' => -36.84,
        'longitude' => 174.75,
        'radius_meters' => 100,
    ]);
    expect(fn () => $retired->save())->toThrow(LogicException::class);
});

it('denies canonical geofence and Site references outside the user Site access', function () {
    $assignedSite = Site::factory()->create();
    $otherSite = Site::factory()->create();
    $user = operationsGeofenceUser($assignedSite, [
        'evv.viewAny',
        'assets.geofences.manage',
        'sites.viewAny',
    ]);
    $otherGeofence = operationsSiteGeofence($otherSite, 'Restricted Site boundary');
    $canonicalCount = AssetGeofence::query()->count();

    $this->actingAs($user)
        ->post(route('operations.geofences.store'), ['site_id' => $otherSite->id])
        ->assertForbidden();
    $this->actingAs($user)
        ->put(route('operations.geofences.update', $otherGeofence), [])
        ->assertNotFound();
    $this->actingAs($user)
        ->delete(route('operations.geofences.destroy', $otherGeofence))
        ->assertNotFound();

    expect(AssetGeofence::query()->count())->toBe($canonicalCount)
        ->and($otherGeofence->fresh())->not->toBeNull();
});
