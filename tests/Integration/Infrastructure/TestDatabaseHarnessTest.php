<?php

use Illuminate\Support\Facades\DB;

it('boots into an isolated mysql database with the latest schema loaded', function () {
    expect(config('database.default'))->toBe('mysql')
        ->and(config('database.connections.mysql.database'))->not->toBe('oblivion_findings_codex_test')
        ->and(DB::table('migrations')->where('migration', '2026_04_06_200000_add_payroll_segment_tracking_to_timesheets')->exists())->toBeTrue();
});

it('clears persisted maintenance mode when booting the testing kernel', function () {
    expect(app()->isDownForMaintenance())->toBeFalse()
        ->and(file_exists(storage_path('framework/down')))->toBeFalse()
        ->and(file_exists(storage_path('framework/maintenance.php')))->toBeFalse();
});
