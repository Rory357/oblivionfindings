<?php

use App\Models\Client;
use App\Models\Permission;
use App\Models\Shift;
use App\Models\ShiftEligibilityOverride;
use App\Models\Site;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('publishing shift with override reason creates immutable audit trail', function () {
    $site = Site::factory()->create([
        'is_active' => true,
        'archived' => false,
        'archived_at' => null,
    ]);

    $client = Client::factory()->create(['site_id' => $site->id]);

    $manager = User::factory()->create([
        'role' => 'coordinator',
        'approved_at' => now(),
    ]);
    \App\Domain\Hr\Models\HrEmployeeProfile::factory()->create([
        'user_id' => $manager->id,
        'primary_site_id' => $site->id,
        'is_active' => true,
        'start_date' => now()->subYear()->toDateString(),
    ]);

    foreach (['shifts.manageAny', 'shifts.update', 'shifts.viewAny'] as $permKey) {
        $perm = Permission::query()->firstOrCreate(
            ['key' => $permKey],
            ['description' => $permKey, 'group' => 'shifts', 'module' => 'Shifts']
        );
        $manager->permissionOverrides()->attach($perm, ['allowed' => true]);
    }

    $staff = User::factory()->create([
        'role' => 'support_worker',
        'approved_at' => now(),
    ]);
    \App\Domain\Hr\Models\HrEmployeeProfile::factory()->create([
        'user_id' => $staff->id,
        'primary_site_id' => $site->id,
        'is_active' => true,
        'start_date' => now()->subYear()->toDateString(),
    ]);

    $shift = Shift::factory()->create([
        'site_id' => $site->id,
        'client_id' => $client->id,
        'user_id' => $staff->id,
        'status' => 'draft',
        'starts_at' => now()->addDays(1)->setTime(8, 0),
        'ends_at' => now()->addDays(1)->setTime(16, 0),
    ]);

    // Publish shift with explicit override reason
    $response = $this->actingAs($manager)->patch(route('operations.shifts.publishShift', $shift), [
        'override_reason' => 'Emergency coverage approved by coordinator',
    ]);
    $response->assertRedirect();

    $shift->refresh();
    expect($shift->status)->toBe('scheduled')
        ->and($shift->published_at)->not->toBeNull();

    // Verify immutable ShiftEligibilityOverride record was created
    $override = ShiftEligibilityOverride::where('shift_id', $shift->id)->first();
    expect($override)->not->toBeNull()
        ->and($override->user_id)->toBe($staff->id)
        ->and($override->overridden_by)->toBe($manager->id)
        ->and($override->override_reason)->toBe('Emergency coverage approved by coordinator');

    // Verify immutability: updates must throw LogicException
    expect(fn () => $override->update(['override_reason' => 'tampered']))
        ->toThrow(\LogicException::class);
});
