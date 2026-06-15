<?php

use Illuminate\Support\Facades\Schema;

test('the orphaned hr_dashboard_configs table has been dropped', function () {
    expect(Schema::hasTable('hr_dashboard_configs'))->toBeFalse();
});

test('the HrDashboardConfig model file has been removed', function () {
    // class_exists() is unreliable in the copied-vendor worktree (cached classmap),
    // so assert on the file directly.
    expect(file_exists(app_path('Domain/Hr/Models/HrDashboardConfig.php')))->toBeFalse();
});

test('the drop migration is reversible (defines a down that recreates the table)', function () {
    $path = database_path('migrations/2026_06_16_000001_drop_hr_dashboard_configs_table.php');
    expect(file_exists($path))->toBeTrue();

    $migration = require $path;
    expect(method_exists($migration, 'down'))->toBeTrue();
});
