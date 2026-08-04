<?php

use App\Domain\Hr\Jobs\SendAssetRemindersJob;
use App\Domain\Hr\Models\HrAsset;
use App\Domain\Hr\Models\HrAssetAssignment;
use App\Domain\Hr\Models\HrAssetMaintenanceLog;
use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Domain\Hr\Services\AssetService;
use App\Domain\Hr\Services\HrNotificationService;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Database\Seeders\SeedHrPermissionsSeeder;

beforeEach(function () {
    $this->seed(RbacSeeder::class);
    $this->seed(SeedHrPermissionsSeeder::class);

    $this->hr = User::factory()->create([
        'role' => 'hr',
        'approved_at' => now(),
    ]);
    $this->hr->roles()->syncWithoutDetaching([
        Role::query()->where('name', 'hr')->first()->id,
    ]);
    $this->site = Site::factory()->create();
    HrEmployeeProfile::factory()->create([
        'user_id' => $this->hr->id,
        'primary_site_id' => $this->site->id,
        'is_active' => true,
        'start_date' => today()->subYear(),
    ]);

    $this->holder = HrEmployeeProfile::factory()->create([
        'primary_site_id' => $this->site->id,
        'is_active' => true,
        'start_date' => today()->subYear(),
    ]);
});

function reminderAsset(array $o = []): HrAsset
{
    return HrAsset::query()->create(array_merge([
        'asset_tag' => 'AT-'.fake()->unique()->numberBetween(1000, 999999),
        'name' => 'Test Laptop',
        'category' => 'laptop',
        'status' => 'available',
    ], $o));
}

function runReminders(): void
{
    (new SendAssetRemindersJob)->handle(app(AssetService::class), app(HrNotificationService::class));
}

test('the reminder sweep notifies HR about warranty, overdue returns and overdue repairs', function () {
    // Warranty expiring in exactly 30 days.
    reminderAsset(['warranty_expiry' => now()->addDays(30)->toDateString()]);

    // Overdue return.
    $overdue = reminderAsset(['status' => 'assigned']);
    HrAssetAssignment::query()->create([
        'asset_id' => $overdue->id,
        'employee_profile_id' => $this->holder->id,
        'assigned_at' => now()->subMonths(2),
        'due_at' => now()->subDays(5),
        'assigned_by' => $this->hr->id,
    ]);

    // Overdue repair.
    $inRepair = reminderAsset(['status' => 'maintenance']);
    HrAssetMaintenanceLog::query()->create([
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

test('the application-wide sweep routes assigned alerts only to managers with complete Site access', function () {
    $otherSite = Site::factory()->create();
    $otherManager = User::factory()->create([
        'role' => 'hr',
        'approved_at' => now(),
    ]);
    $otherManager->roles()->syncWithoutDetaching([
        Role::query()->where('name', 'hr')->firstOrFail()->id,
    ]);
    HrEmployeeProfile::factory()->create([
        'user_id' => $otherManager->id,
        'primary_site_id' => $otherSite->id,
        'is_active' => true,
        'start_date' => today()->subYear(),
    ]);
    $otherHolder = HrEmployeeProfile::factory()->create([
        'primary_site_id' => $otherSite->id,
        'is_active' => true,
        'start_date' => today()->subYear(),
    ]);

    $allowedAsset = reminderAsset(['asset_tag' => 'SITE-A-OVERDUE', 'status' => 'assigned']);
    HrAssetAssignment::query()->create([
        'asset_id' => $allowedAsset->id,
        'employee_profile_id' => $this->holder->id,
        'assigned_at' => now()->subMonth(),
        'due_at' => now()->subDay(),
        'assigned_by' => $this->hr->id,
    ]);
    $otherAsset = reminderAsset(['asset_tag' => 'SITE-B-OVERDUE', 'status' => 'assigned']);
    HrAssetAssignment::query()->create([
        'asset_id' => $otherAsset->id,
        'employee_profile_id' => $otherHolder->id,
        'assigned_at' => now()->subMonth(),
        'due_at' => now()->subDay(),
        'assigned_by' => $otherManager->id,
    ]);

    runReminders();

    $siteAAssetIds = $this->hr->notifications()
        ->where('data->kind', 'overdue')
        ->pluck('data')
        ->pluck('asset_id')
        ->all();
    $siteBAssetIds = $otherManager->notifications()
        ->where('data->kind', 'overdue')
        ->pluck('data')
        ->pluck('asset_id')
        ->all();

    expect($siteAAssetIds)->toContain($allowedAsset->id)->not->toContain($otherAsset->id)
        ->and($siteBAssetIds)->toContain($otherAsset->id)->not->toContain($allowedAsset->id);

    $otherManager->permissionOverrides()->syncWithoutDetaching([
        Permission::query()->where('key', 'hr.assets.viewUnassigned')->firstOrFail()->id => ['allowed' => false],
    ]);
    reminderAsset([
        'asset_tag' => 'GLOBAL-WARRANTY',
        'warranty_expiry' => now()->addDays(7)->toDateString(),
    ]);

    runReminders();

    expect($this->hr->notifications()->where('data->kind', 'warranty')->count())->toBeGreaterThan(0)
        ->and($otherManager->notifications()->where('data->kind', 'warranty')->count())->toBe(0);
});
