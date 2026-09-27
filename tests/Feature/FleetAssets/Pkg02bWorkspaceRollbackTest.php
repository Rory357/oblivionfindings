<?php

use App\Models\Asset;
use App\Models\Site;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

test('PKG-02B migrations roll back only while empty and never discard vehicle records', function () {
    expect(app()->environment())->toBe('testing');
    expect(DB::connection()->getDatabaseName())->toMatch('/^oblivion_findings_(?:pkg01_2375_test|pkg02b_5b0a_test|pkg02b_final_test|codex_test)_'.preg_quote((string) getmypid(), '/').'$/');
    // DDL runs only in this process-owned disposable schema, outside the
    // RefreshDatabase transaction. No shared or browser database is touched.
    while (DB::transactionLevel() > 0) {
        DB::rollBack();
    }
    // Every PKG-02B migration after I1, in the order they run; a rollback
    // undoes them in reverse, as `migrate:rollback` would.
    $paths = glob(database_path('migrations/2026_09_23_*_pkg02b_*.php')) ?: [];
    $paths[] = database_path('migrations/2026_09_26_000100_pkg02b_appointment_command_receipts.php');
    sort($paths);
    expect(count($paths))->toBeGreaterThanOrEqual(8);
    $migrations = array_map(fn (string $path) => require $path, $paths);

    foreach (array_reverse($migrations) as $migration) {
        $migration->down();
    }
    expect(Schema::hasTable('fleet_vehicle_reminders'))->toBeFalse()
        ->and(Schema::hasTable('asset_document_sets'))->toBeFalse()
        ->and(Schema::hasTable('fleet_obligation_reminders'))->toBeFalse()
        ->and(Schema::hasTable('fleet_vehicle_mileage_feeds'))->toBeFalse()
        ->and(Schema::hasTable('fleet_vehicle_geofence_assignments'))->toBeFalse()
        ->and(Schema::hasColumn('asset_documents', 'document_set_id'))->toBeFalse()
        ->and(Schema::hasColumn('fleet_service_schedules', 'interval_months'))->toBeFalse()
        ->and(Schema::hasColumn('assets', 'vehicle_profile_version'))->toBeFalse();
    foreach ($migrations as $migration) {
        $migration->up();
    }
    expect(Schema::hasTable('fleet_vehicle_reminder_events'))->toBeTrue()
        ->and(Schema::hasTable('fleet_vehicle_mileage_feed_events'))->toBeTrue()
        ->and(Schema::hasColumn('assets', 'profile_photo_document_id'))->toBeTrue();

    DB::beginTransaction();
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
    expect(fn () => $migrations[0]->down())->toThrow(RuntimeException::class, 'PKG-02B vehicle records exist');
    expect(Schema::hasTable('fleet_vehicle_reminders'))->toBeTrue()
        ->and(DB::table('fleet_vehicle_reminders')->where('id', $reminderId)->value('title'))->toBe('Synthetic rollback reminder');
});
