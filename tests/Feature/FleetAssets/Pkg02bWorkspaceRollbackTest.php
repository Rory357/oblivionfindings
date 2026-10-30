<?php

use App\Models\Asset;
use App\Models\Site;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

test('PKG-02B workspace rollback never discards recorded vehicle evidence', function () {
    expect(app()->environment())->toBe('testing');
    expect(DB::connection()->getDatabaseName())->toMatch('/^oblivion_findings_(?:pkg01_2375_test|pkg02b_5b0a_test|pkg02b_final_test|codex_test)_'.preg_quote((string) getmypid(), '/').'$/');
    // Keep the current integrated schema and the test transaction intact.
    // Later Finance history is intentionally irreversible; an old package's
    // empty-schema round trip is no longer a supported integrated rollback.
    $migration = require database_path('migrations/2026_09_23_000100_pkg02b_vehicle_workspace_records.php');
    $site = Site::factory()->create();
    $owner = User::factory()->create();
    $asset = Asset::factory()->create(['site_id' => $site->id, 'category' => 'vehicle']);
    $reminderId = DB::table('fleet_vehicle_reminders')->insertGetId([
        'asset_id' => $asset->id, 'title' => 'Synthetic rollback reminder', 'action_text' => 'Keep this record',
        'source_type' => 'vehicle', 'due_at' => now()->addDay(), 'repeat_months' => 0, 'owner_user_id' => $owner->id,
        'state' => 'scheduled', 'lock_version' => 1, 'created_by_user_id' => $owner->id, 'request_key' => 'rollback-reminder',
        'created_at' => now(), 'updated_at' => now(),
    ]);

    // The first package migration refuses while its records exist, whatever runs after it.
    expect(fn () => $migration->down())->toThrow(RuntimeException::class, 'PKG-02B vehicle records exist');
    expect(Schema::hasTable('fleet_vehicle_reminders'))->toBeTrue()
        ->and(Schema::hasTable('asset_document_sets'))->toBeTrue()
        ->and(Schema::hasColumn('assets', 'vehicle_profile_version'))->toBeTrue()
        ->and(DB::table('fleet_vehicle_reminders')->where('id', $reminderId)->value('title'))->toBe('Synthetic rollback reminder');
});

test('integrated Finance history prevents rollback even before any review is recorded', function () {
    $migration = require database_path('migrations/2026_09_27_000100_complete_finance_review_workflow.php');
    expect(DB::table('fleet_finance_review_requests')->exists())->toBeFalse();

    expect(fn () => $migration->down())->toThrow(RuntimeException::class, 'Finance approval receipts, evidence and review history are retained');

    expect(Schema::hasTable('fleet_finance_review_requests'))->toBeTrue()
        ->and(Schema::hasTable('fleet_finance_review_notices'))->toBeTrue()
        ->and(Schema::hasTable('fin_bill_approval_receipts'))->toBeTrue()
        ->and(Schema::hasTable('fleet_vehicle_geofence_assignments'))->toBeTrue();
});
