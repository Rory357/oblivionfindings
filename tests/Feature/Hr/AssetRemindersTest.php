<?php

use App\Domain\Hr\Jobs\SendAssetRemindersJob;
use App\Domain\Hr\Models\HrAsset;
use App\Domain\Hr\Models\HrAssetAssignment;
use App\Domain\Hr\Models\HrAssetMaintenanceLog;
use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Domain\Hr\Services\AssetService;
use App\Domain\Hr\Services\HrNotificationService;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Database\Seeders\SeedHrPermissionsSeeder;

beforeEach(function () {
    $this->seed(RbacSeeder::class);
    $this->seed(SeedHrPermissionsSeeder::class);

    $this->hr = User::factory()->create([
        'organization_id' => 1,
        'role' => 'hr',
        'approved_at' => now(),
    ]);
    $this->hr->roles()->syncWithoutDetaching([
        Role::query()->where('name', 'hr')->first()->id,
    ]);

    $this->holder = HrEmployeeProfile::factory()->create([
        'tenant_id' => 1,
        'is_active' => true,
    ]);
});

function reminderAsset(array $o = []): HrAsset
{
    return HrAsset::query()->create(array_merge([
        'tenant_id' => 1,
        'asset_tag' => 'AT-'.fake()->unique()->numberBetween(1000, 999999),
        'name' => 'Test Laptop',
        'category' => 'laptop',
        'status' => 'available',
    ], $o));
}

function runReminders(): void
{
    (new SendAssetRemindersJob(1))->handle(app(AssetService::class), app(HrNotificationService::class));
}

test('the reminder sweep notifies HR about warranty, overdue returns and overdue repairs', function () {
    // Warranty expiring in exactly 30 days.
    reminderAsset(['warranty_expiry' => now()->addDays(30)->toDateString()]);

    // Overdue return.
    $overdue = reminderAsset(['status' => 'assigned']);
    HrAssetAssignment::query()->create([
        'tenant_id' => 1,
        'asset_id' => $overdue->id,
        'employee_profile_id' => $this->holder->id,
        'assigned_at' => now()->subMonths(2),
        'due_at' => now()->subDays(5),
        'assigned_by' => $this->hr->id,
    ]);

    // Overdue repair.
    $inRepair = reminderAsset(['status' => 'maintenance']);
    HrAssetMaintenanceLog::query()->create([
        'tenant_id' => 1,
        'asset_id' => $inRepair->id,
        'type' => 'repair',
        'vendor' => 'iFix Repairs',
        'sent_at' => now()->subDays(14)->toDateString(),
        'expected_back_at' => now()->subDays(3)->toDateString(),
    ]);

    runReminders();

    $kinds = $this->hr->notifications()->pluck('data')->map(fn ($d) => $d['kind'] ?? null);
    expect($kinds)->toContain('warranty');
    expect($kinds)->toContain('overdue');
    expect($kinds)->toContain('maintenance');
});

test('the reminder sweep is idempotent for once-scoped warranty alerts', function () {
    reminderAsset(['warranty_expiry' => now()->addDays(14)->toDateString()]);

    runReminders();
    runReminders();

    $count = $this->hr->notifications()
        ->where('data->kind', 'warranty')
        ->count();

    expect($count)->toBe(1);
});

test('offboarding an employee flags the equipment they still hold to HR', function () {
    $held = reminderAsset(['status' => 'assigned']);
    HrAssetAssignment::query()->create([
        'tenant_id' => 1,
        'asset_id' => $held->id,
        'employee_profile_id' => $this->holder->id,
        'assigned_at' => now()->subMonth(),
        'assigned_by' => $this->hr->id,
    ]);

    // Deactivating the profile (offboarding) fires the observer immediately.
    $this->holder->update(['is_active' => false]);

    $leaverAlerts = $this->hr->notifications()
        ->where('data->kind', 'leaver')
        ->where('data->asset_id', $held->id)
        ->count();

    expect($leaverAlerts)->toBe(1);
});
