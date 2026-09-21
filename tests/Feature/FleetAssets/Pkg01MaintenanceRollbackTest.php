<?php

use App\Models\Asset;
use App\Models\FleetChecklistRun;
use App\Models\FleetChecklistTemplate;
use App\Models\FleetWorkOrder;
use App\Models\Site;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

test('Designer review: empty package rolls back and every down preserves standalone and corrected sources', function () {
    expect(app()->environment())->toBe('testing');
    expect(DB::connection()->getDatabaseName())->toMatch('/^oblivion_findings_(?:pkg01_2375_test|codex_test)_'.preg_quote((string) getmypid(), '/').'$/');
    // DDL is tested only in this process-owned disposable schema, outside the
    // RefreshDatabase transaction. No shared or browser database is touched.
    while (DB::transactionLevel() > 0) DB::rollBack();
    $names = ['2026_09_20_000100_pkg01_maintenance_workflow.php',
        '2026_09_20_000110_pkg01_booking_impacts.php',
        '2026_09_20_000120_pkg01_configuration_history.php',
        '2026_09_20_000130_pkg01_report_corrections.php'];
    $migrations = array_map(fn ($name) => require database_path('migrations/'.$name), $names);
    foreach (array_reverse($migrations) as $migration) $migration->down();
    expect(Schema::hasTable('fleet_maintenance_reports'))->toBeFalse()
        ->and(Schema::hasColumn('fleet_checklist_runs', 'presented_template_json'))->toBeFalse()
        ->and(Schema::hasColumn('fleet_work_orders', 'version'))->toBeFalse();
    foreach ($migrations as $migration) $migration->up();
    expect(Schema::hasColumn('fleet_maintenance_reports', 'corrects_report_id'))->toBeTrue();

    DB::beginTransaction();
    $site = Site::factory()->create();
    $actor = User::factory()->create();
    $asset = Asset::factory()->create(['site_id' => $site->id, 'category' => 'vehicle']);
    $template = FleetChecklistTemplate::create(['name' => 'Synthetic rollback source', 'type' => 'custom',
        'is_active' => true, 'items' => [['id' => 'q1', 'label' => 'Original question', 'type' => 'checkbox']]]);
    $run = FleetChecklistRun::create(['template_id' => $template->id, 'asset_id' => $asset->id,
        'user_id' => $actor->id, 'passed' => false, 'responses' => ['q1' => ['result' => 'unknown']],
        'presented_template_json' => ['items' => $template->items], 'outcome' => 'needs_assessment',
        'completed_at' => now(), 'submitted_at' => now(), 'request_key' => 'designer-rollback-check', 'request_fingerprint' => str_repeat('1', 64)]);
    foreach (array_reverse($migrations) as $migration) {
        expect(fn () => $migration->down())->toThrow(RuntimeException::class, 'standalone checks');
        expect(Schema::hasColumn('fleet_checklist_runs', 'presented_template_json'))->toBeTrue()
            ->and(Schema::hasColumn('fleet_maintenance_reports', 'corrects_report_id'))->toBeTrue()
            ->and($run->fresh()->presented_template_json['items'][0]['label'])->toBe('Original question');
    }
    DB::table('fleet_checklist_runs')->where('id', $run->id)->delete();
    $order = FleetWorkOrder::create(['asset_id' => $asset->id, 'reported_by_user_id' => $actor->id,
        'title' => 'Synthetic rollback report', 'category' => 'vehicle', 'status' => 'open', 'priority' => 'medium']);
    $source = ['work_order_id' => $order->id, 'asset_id' => $asset->id, 'site_id' => $site->id,
        'submitted_by_user_id' => $actor->id, 'submitted_at' => now(),
        'request_fingerprint' => str_repeat('2', 64), 'title' => 'Original source'];
    $original = DB::table('fleet_maintenance_reports')->insertGetId([...$source, 'request_key' => 'original-report']);
    $correction = DB::table('fleet_maintenance_reports')->insertGetId([...$source,
        'request_key' => 'correction-report', 'corrects_report_id' => $original, 'title' => 'Correction']);
    foreach (array_reverse($migrations) as $migration) {
        expect(fn () => $migration->down())->toThrow(RuntimeException::class, 'provenance');
        expect((int) DB::table('fleet_maintenance_reports')->where('id', $correction)->value('corrects_report_id'))->toBe($original);
    }
});
