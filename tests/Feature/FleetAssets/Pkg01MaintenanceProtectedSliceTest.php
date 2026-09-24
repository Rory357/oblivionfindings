<?php

use App\Domain\Finance\Models\FinFinancialEvent;
use App\Domain\Finance\Models\FinBill;
use App\Domain\Finance\Jobs\ProcessFinancialEventJob;
use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Models\Asset;
use App\Models\FleetChecklistRun;
use App\Models\FleetChecklistTemplate;
use App\Models\FleetWorkOrder;
use App\Models\Permission;
use App\Models\Site;
use App\Models\User;
use App\Services\Fleet\MaintenanceCheckService;
use App\Services\Fleet\MaintenanceFingerprint;
use App\Services\Fleet\MaintenanceReportService;
use App\Services\Fleet\MaintenanceTransitionService;
use App\Services\Fleet\MaintenanceRestrictionService;
use App\Services\Fleet\MaintenanceEffectDispatcher;
use App\Services\Fleet\MaintenanceLocalTime;
use App\Services\Sites\Calendar\Providers\MaintenanceWindowObligationProvider;
use App\Services\Fleet\MaintenanceFinanceService;
use App\Services\Fleet\MaintenanceConfigurationService;
use App\Services\Tasks\Providers\FleetMaintenanceProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Storage;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Inertia\Testing\AssertableInertia as Assert;
use Illuminate\Support\Str;
use Illuminate\Support\Carbon;
use Symfony\Component\Process\Process;
use Tests\Support\CommittedFixtureCleanup;

// Tests that commit fixtures for worker processes register
// CommittedFixtureCleanup so those rows never reach later tests.
uses(RefreshDatabase::class);

test('queue and related-work search retain identity while undated work stays outside All Tasks', function () {
    $site = pkg01Site();
    $otherSite = pkg01Site();
    $manager = pkg01StaffAt($site, ['fleet.maintenance.manage', 'fleet.viewAny']);
    $asset = Asset::factory()->create(['site_id' => $site->id, 'category' => 'vehicle', 'name' => 'Synthetic test van']);
    $otherAsset = Asset::factory()->create(['site_id' => $otherSite->id, 'category' => 'vehicle']);
    $order = FleetWorkOrder::create([
        'asset_id' => $asset->id, 'reported_by_user_id' => $manager->id,
        'assigned_to_user_id' => $manager->id, 'title' => 'Synthetic mirror noise',
        'category' => 'vehicle', 'priority' => 'medium', 'status' => 'open', 'due_at' => null,
    ]);
    FleetWorkOrder::create([
        'asset_id' => $otherAsset->id, 'reported_by_user_id' => $manager->id,
        'title' => 'Foreign mirror noise', 'category' => 'vehicle',
        'priority' => 'medium', 'status' => 'open',
    ]);

    $this->actingAs($manager)->get('/fleet-assets/maintenance/work-orders?view=mine')
        ->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('fleet-assets/maintenance/work-orders/index')
            ->where('work_orders.data.0.id', $order->id)
            ->where('work_orders.meta.total', 1)
            ->where('stats.mine', 1)
            ->etc());
    $this->actingAs($manager)->get('/fleet-assets/maintenance/work-orders?site_id='.$otherSite->id)
        ->assertNotFound();
    $this->actingAs($manager)->getJson('/fleet-assets/maintenance/work-orders/options/search?type=work_orders&asset_id='.$asset->id.'&q=mirror')
        ->assertOk()->assertJsonPath('results.0.id', $order->id)->assertJsonCount(1, 'results');
    $items = app(FleetMaintenanceProvider::class)->authorizedTasks($manager);
    expect(collect($items)->pluck('id')->contains('fleet_work_order-'.$order->id))->toBeFalse();
    $this->actingAs($manager)->get('/fleet-assets/maintenance/work-orders/'.$order->id)
        ->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('task_link', null)
            ->where('task_scope_message', 'Set a target date to make this work eligible for All Tasks within seven days.')
            ->etc());
    $order->update(['due_at' => now()->addDays(3)]);
    expect(collect(app(FleetMaintenanceProvider::class)->authorizedTasks($manager))->pluck('id')
        ->contains('fleet_work_order-'.$order->id))->toBeTrue();
    $order->update(['status' => 'completed']);
    expect(collect(app(FleetMaintenanceProvider::class)->authorizedTasks($manager))->pluck('id')
        ->contains('fleet_work_order-'.$order->id))->toBeFalse();
    expect(collect(app(FleetMaintenanceProvider::class)->authorizedTasks($manager, ['include_done' => true]))
        ->pluck('id')->contains('fleet_work_order-'.$order->id))->toBeTrue();
    $this->actingAs($manager)->get('/fleet-assets/maintenance/work-orders/'.$order->id)
        ->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('task_link', '/tasks?sources=fleet_maintenance&q='.rawurlencode($order->reference_number).'&done=1')
            ->etc());
});

test('Finance projection conceals a bill whose canonical asset or site has changed', function () {
    $site = pkg01Site();
    $otherSite = pkg01Site();
    $manager = pkg01StaffAt($site, ['fleet.maintenance.manage', 'finance.ap.view']);
    $asset = Asset::factory()->create(['site_id' => $site->id, 'category' => 'vehicle']);
    $otherAsset = Asset::factory()->create(['site_id' => $site->id, 'category' => 'vehicle']);
    $order = FleetWorkOrder::create([
        'asset_id' => $asset->id, 'reported_by_user_id' => $manager->id,
        'assigned_to_user_id' => $manager->id, 'title' => 'Synthetic service',
        'category' => 'vehicle', 'priority' => 'medium', 'status' => 'open',
    ]);
    $bill = FinBill::factory()->create([
        'site_id' => $site->id, 'asset_id' => $asset->id,
        'bill_number' => 'BILL-PKG01-SYNTHETIC', 'total_amount' => 321.45,
    ]);
    $this->actingAs($manager)->getJson('/fleet-assets/maintenance/work-orders/options/search?type=finance_bills&asset_id='.$asset->id.'&q=PKG01')
        ->assertOk()->assertJsonPath('results.0.bill_number', 'BILL-PKG01-SYNTHETIC');
    $this->actingAs($manager)->getJson('/fleet-assets/maintenance/work-orders/options/search?type=finance_bills&asset_id='.$otherAsset->id.'&q=PKG01')
        ->assertOk()->assertJsonCount(0, 'results');
    $finance = app(MaintenanceFinanceService::class);
    $finance->linkBill($manager, $order->id, $bill->id);
    expect($finance->projection($manager, $order->id)[0]['reference'])
        ->toBe('BILL-PKG01-SYNTHETIC');

    $bill->update(['asset_id' => $otherAsset->id]);
    expect($finance->projection($manager, $order->id))
        ->toBe([['status' => 'reconciliation_required']]);
    $bill->update(['asset_id' => $asset->id, 'site_id' => $otherSite->id]);
    expect($finance->projection($manager, $order->id))
        ->toBe([['status' => 'reconciliation_required']]);
    $bill->update(['site_id' => $site->id]);
    $bill->delete();
    expect($finance->projection($manager, $order->id))
        ->toBe([['status' => 'reconciliation_required']]);
});

test('Auckland local minutes reject daylight gaps and require an offset for repeated minutes', function () {
    expect(MaintenanceLocalTime::toUtc('2026-09-20T00:00'))->toBe('2026-09-19 12:00:00')
        ->and(MaintenanceLocalTime::toUtc('2026-09-20T12:34'))->toBe('2026-09-20 00:34:00')
        ->and(MaintenanceLocalTime::toUtc('2026-09-30T23:50'))->toBe('2026-09-30 10:50:00')
        ->and(MaintenanceLocalTime::toUtc('2026-10-01T00:10'))->toBe('2026-09-30 11:10:00');
    expect(fn () => MaintenanceLocalTime::toUtc('2026-09-27T02:30'))
        ->toThrow(ValidationException::class);
    expect(fn () => MaintenanceLocalTime::toUtc('2026-04-05T02:30'))
        ->toThrow(ValidationException::class);
    expect(MaintenanceLocalTime::toUtc('2026-04-05T02:30', '+13:00'))
        ->toBe('2026-04-04 13:30:00');
    expect(MaintenanceLocalTime::toUtc('2026-04-05T02:30', '+12:00'))
        ->toBe('2026-04-04 14:30:00');
});

function pkg01StaffAt(Site $site, array $permissions = []): User
{
    $user = User::factory()->create(['approved_at' => now()]);
    HrEmployeeProfile::factory()->create([
        'user_id' => $user->id,
        'primary_site_id' => $site->id,
        'is_active' => true,
    ]);
    foreach ($permissions as $key) {
        $permission = Permission::query()->firstOrCreate(
            ['key' => $key],
            ['description' => $key, 'group' => 'fleet', 'module' => 'Resources'],
        );
        $user->permissionOverrides()->attach($permission, ['allowed' => true]);
    }

    return $user;
}

function pkg01Site(): Site
{
    return Site::factory()->create(['is_active' => true, 'archived' => false, 'archived_at' => null]);
}

test('approved configuration versions route, rule and reviewer decisions without default grants', function () {
    $site = pkg01Site();
    $otherSite = pkg01Site();
    $operator = pkg01StaffAt($site, ['fleet.maintenance.configure']);
    $coordinator = pkg01StaffAt($site);
    $backup = pkg01StaffAt($site);
    $reviewer = pkg01StaffAt($site, ['fleet.maintenance.release']);
    Asset::factory()->create(['site_id' => $site->id, 'category' => 'vehicle']);
    $service = app(MaintenanceConfigurationService::class);
    $route = ['kind' => 'route', 'site_id' => $site->id,
        'coordinator_user_id' => $coordinator->id, 'backup_user_id' => $backup->id,
        'approval_reference' => 'SYNTHETIC-APPROVAL-001'];
    expect($service->validate($operator, $route)['kind'])->toBe('route')
        ->and(DB::table('fleet_maintenance_site_routes')->where('site_id', $site->id)->exists())->toBeFalse();
    expect($service->apply($operator, $route)['version'])->toBe(1);
    $route['approval_reference'] = 'SYNTHETIC-APPROVAL-002';
    expect($service->apply($operator, $route)['version'])->toBe(2)
        ->and(DB::table('fleet_maintenance_site_route_history')->where('site_id', $site->id)->count())->toBe(2);

    $template = FleetChecklistTemplate::create([
        'name' => 'Configured synthetic check', 'type' => 'custom', 'is_active' => true,
        'items' => [['id' => 'tyres', 'label' => 'Tyres', 'type' => 'select',
            'options' => ['pass', 'fail', 'na'], 'required' => true]],
    ]);
    $policy = ['kind' => 'policy', 'site_id' => $site->id, 'asset_category' => 'vehicle',
        'rule_kind' => 'check', 'approval_reference' => 'SYNTHETIC-APPROVAL-003',
        'rules' => ['template_id' => $template->id,
            'template_sha256' => MaintenanceFingerprint::of($template->items),
            'questions' => [['id' => 'tyres', 'pass_values' => ['pass'], 'allow_na' => false,
                'evidence_required' => true]]]];
    expect($service->apply($operator, $policy)['version'])->toBe(1)
        ->and(app(\App\Services\Fleet\MaintenancePolicyService::class)
            ->current($site->id, 'vehicle', 'check'))->not->toBeNull();
    $grant = ['kind' => 'reviewer', 'site_id' => $site->id,
        'asset_category' => 'vehicle', 'user_id' => $reviewer->id,
        'decision' => 'grant', 'approval_reference' => 'SYNTHETIC-APPROVAL-004'];
    expect($service->apply($operator, $grant)['version'])->toBe(1);
    $grant['decision'] = 'revoke';
    $grant['approval_reference'] = 'SYNTHETIC-APPROVAL-005';
    expect($service->apply($operator, $grant)['version'])->toBe(2)
        ->and(DB::table('fleet_maintenance_reviewer_grants')->where('user_id', $reviewer->id)->count())->toBe(2)
        ->and(DB::table('fleet_maintenance_configuration_events')->where('site_id', $site->id)->count())->toBe(5);
    $route['site_id'] = $otherSite->id;
    expect(fn () => $service->apply($operator, $route))->toThrow(\Symfony\Component\HttpKernel\Exception\NotFoundHttpException::class);
    $schedule = new Process([PHP_BINARY, 'artisan', 'schedule:list'], base_path(), [
        'APP_ENV' => 'testing', 'DB_CONNECTION' => 'mysql',
        'DB_DATABASE' => DB::connection()->getDatabaseName(),
        'FLEET_MAINTENANCE_EFFECTS_ENABLED' => 'true',
    ]);
    $schedule->run();
    expect($schedule->isSuccessful())->toBeTrue()
        ->and($schedule->getOutput())->toContain('maintenance:dispatch-effects');
});

test('custody receipt access follows the latest exact recipient and approved site', function () {
    $site = pkg01Site();
    $otherSite = pkg01Site();
    $manager = pkg01StaffAt($site, ['fleet.maintenance.manage']);
    $first = pkg01StaffAt($site);
    $second = pkg01StaffAt($site);
    $wrongSite = pkg01StaffAt($otherSite);
    $asset = Asset::factory()->create(['site_id' => $site->id, 'category' => 'vehicle']);
    $order = FleetWorkOrder::create([
        'asset_id' => $asset->id, 'reported_by_user_id' => $manager->id,
        'assigned_to_user_id' => $manager->id, 'title' => 'Synthetic custody selection',
        'category' => 'vehicle', 'priority' => 'medium', 'status' => 'completed',
    ]);
    $service = app(MaintenanceTransitionService::class);
    $service->execute($manager, $order->id, 'propose_custody', 0,
        'pkg01-custody-first', ['target_user_id' => $first->id]);
    $this->actingAs($first)->get('/fleet-assets/maintenance/work-orders/'.$order->id)
        ->assertOk()->assertInertia(fn (Assert $page) => $page->where('can.custody', true)
            ->where('attachments', [])->etc());
    $this->actingAs($wrongSite)->get('/fleet-assets/maintenance/work-orders/'.$order->id)->assertNotFound();
    $service->execute($manager, $order->id, 'propose_custody', 1,
        'pkg01-custody-second', ['target_user_id' => $second->id]);
    $this->actingAs($first)->get('/fleet-assets/maintenance/work-orders/'.$order->id)->assertNotFound();
    $this->actingAs($second)->get('/fleet-assets/maintenance/work-orders/'.$order->id)->assertOk();
    expect(fn () => $service->execute($first, $order->id, 'acknowledge_custody', 2,
        'pkg01-first-stale-receipt', ['received' => true]))->toThrow(HttpException::class);
    $this->actingAs($second)->put('/fleet-assets/maintenance/work-orders/'.$order->id, [
        'operation' => 'acknowledge_custody', 'version' => 2,
        'request_key' => 'pkg01-second-receipt', 'received' => true,
    ])->assertRedirect();
    expect($order->fresh()->version)->toBe(3);
});

test('a restriction flags existing bookings once and retains an owned follow-up', function () {
    $site = pkg01Site();
    $otherSite = pkg01Site();
    $manager = pkg01StaffAt($site, ['fleet.maintenance.manage']);
    $otherManager = pkg01StaffAt($otherSite, ['fleet.maintenance.manage']);
    $asset = Asset::factory()->create(['site_id' => $site->id, 'category' => 'vehicle']);
    $booking = \App\Models\FleetVehicleBooking::create([
        'asset_id' => $asset->id, 'user_id' => $manager->id,
        'purpose' => 'Synthetic planned trip', 'starts_at' => now()->addDay(),
        'ends_at' => now()->addDay()->addHour(), 'status' => 'approved',
    ]);
    $order = FleetWorkOrder::create([
        'asset_id' => $asset->id, 'reported_by_user_id' => $manager->id,
        'assigned_to_user_id' => $manager->id, 'title' => 'Synthetic booking hold',
        'category' => 'vehicle', 'priority' => 'high', 'status' => 'open',
    ]);
    $rules = ['allowed_kinds' => ['safety']];
    $policyId = DB::table('fleet_maintenance_policy_versions')->insertGetId([
        'site_id' => $site->id, 'asset_category' => 'vehicle', 'rule_kind' => 'hold',
        'version' => 1, 'rules_json' => json_encode($rules, JSON_THROW_ON_ERROR),
        'content_sha256' => MaintenanceFingerprint::of($rules),
        'approved_by_user_id' => $manager->id, 'approved_at' => now(), 'created_at' => now(),
    ]);
    DB::table('fleet_maintenance_policy_assignments')->insert([
        'site_id' => $site->id, 'asset_category' => 'vehicle', 'rule_kind' => 'hold',
        'policy_version_id' => $policyId, 'assigned_by_user_id' => $manager->id,
        'assigned_at' => now(), 'created_at' => now(), 'updated_at' => now(),
    ]);
    $service = app(MaintenanceTransitionService::class);
    $service->execute($manager, $order->id, 'place_restriction', 0,
        'pkg01-booking-impact-hold', ['restriction_kind' => 'safety']);
    $service->execute($manager, $order->id, 'place_restriction', 0,
        'pkg01-booking-impact-hold', ['restriction_kind' => 'safety']);
    $impact = DB::table('fleet_maintenance_booking_impacts')->where('booking_id', $booking->id)->first();
    expect($impact)->not->toBeNull()
        ->and(DB::table('fleet_maintenance_booking_impacts')->where('booking_id', $booking->id)->count())->toBe(1)
        ->and($impact->followup_state)->toBe('needs_review')
        ->and((int) $impact->owner_user_id)->toBe($manager->id)
        ->and($booking->fresh()->status)->toBe('approved');
    $this->actingAs($manager)->get('/fleet-assets/maintenance/work-orders/'.$order->id)
        ->assertOk()->assertInertia(fn (Assert $page) => $page->where('booking_impacts.0.booking_id', $booking->id)->etc());
    $this->actingAs($otherManager)->get('/fleet-assets/maintenance/work-orders/'.$order->id)->assertNotFound();
    $this->actingAs($manager)->put('/fleet-assets/maintenance/work-orders/'.$order->id, [
        'operation' => 'review_booking_impact', 'version' => 1,
        'request_key' => 'pkg01-booking-impact-review', 'booking_impact_id' => $impact->id,
        'review_note' => 'Contacted booking owner; trip remains subject to release.',
    ])->assertRedirect();
    expect(DB::table('fleet_maintenance_booking_impacts')->where('id', $impact->id)->value('followup_state'))
        ->toBe('reviewed');
});

test('report identity preserves both submissions and requires approved site routing', function () {
    $site = pkg01Site();
    $reporter = pkg01StaffAt($site, ['fleet.maintenance.report']);
    $coordinator = pkg01StaffAt($site, ['fleet.maintenance.manage']);
    $backup = pkg01StaffAt($site, ['fleet.maintenance.manage']);
    $asset = Asset::factory()->create(['site_id' => $site->id, 'category' => 'vehicle']);
    $data = [
        'asset_id' => $asset->id,
        'title' => 'Brake warning',
        'description' => 'Warning appeared after departure',
        'priority' => 'low',
        'estimated_start_date' => '2026-09-21',
        'estimated_end_date' => '2026-09-23',
        'request_key' => 'pkg01-report-one',
    ];

    expect(fn () => app(MaintenanceReportService::class)->submit($reporter, $data))
        ->toThrow(ValidationException::class);
    expect(FleetWorkOrder::count())->toBe(0);

    DB::table('fleet_maintenance_site_routes')->insert([
        'site_id' => $site->id,
        'coordinator_user_id' => $coordinator->id,
        'backup_user_id' => $backup->id,
        'approved_by_user_id' => $coordinator->id,
        'approved_at' => now(),
        'version' => 1,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $first = app(MaintenanceReportService::class)->submit($reporter, $data);
    expect($first->assigned_to_user_id)->toBe($coordinator->id)
        ->and($first->reference_number)->not->toBeNull();
    $calendar = app(MaintenanceWindowObligationProvider::class);
    $window = $calendar->obligations([$site->id], Carbon::parse('2026-09-20'), Carbon::parse('2026-09-25'));
    expect($window)->toHaveCount(1)
        ->and($window[0]->source)->toBe('asset')
        ->and($window[0]->link)->toBe("/fleet-assets/maintenance/work-orders/{$first->id}")
        ->and($calendar->obligations([pkg01Site()->id], Carbon::parse('2026-09-20'), Carbon::parse('2026-09-25')))->toBe([]);
    $replay = app(MaintenanceReportService::class)->submit($reporter, $data);
    expect($replay->id)->toBe($first->id)
        ->and(FleetWorkOrder::count())->toBe(1)
        ->and(DB::table('fleet_maintenance_reports')->count())->toBe(1);

    expect(fn () => app(MaintenanceReportService::class)->submit($reporter, [...$data, 'title' => 'Changed title']))
        ->toThrow(HttpException::class);
    $second = app(MaintenanceReportService::class)->submit($coordinator, [
        ...$data,
        'request_key' => 'pkg01-report-two',
        'existing_work_order_id' => $first->id,
    ]);
    expect($second->id)->toBe($first->id)
        ->and(DB::table('fleet_maintenance_reports')->where('work_order_id', $first->id)->count())->toBe(2);
    $originalReport = DB::table('fleet_maintenance_reports')->where('work_order_id', $first->id)
        ->orderBy('id')->first();
    $this->actingAs($coordinator)->post('/fleet-assets/maintenance/work-orders', [
        ...$data, 'title' => 'Brake warning corrected',
        'description' => 'New observation corrected the original condition report.',
        'request_key' => 'pkg01-report-correction', 'existing_work_order_id' => $first->id,
        'corrects_report_id' => $originalReport->id,
    ])->assertRedirect();
    $correction = DB::table('fleet_maintenance_reports')->where('request_key', 'pkg01-report-correction')->first();
    expect((int) $correction->corrects_report_id)->toBe((int) $originalReport->id)
        ->and($correction->work_order_id)->toBe($first->id)
        ->and(DB::table('fleet_maintenance_reports')->where('id', $originalReport->id)->value('title'))
        ->toBe('Brake warning');
});

test('report-only HTTP entry and search stay narrow while selected recipient can accept', function () {
    Storage::fake('private');
    $site = pkg01Site();
    $otherSite = pkg01Site();
    $reporter = pkg01StaffAt($site, ['fleet.maintenance.report']);
    $manager = pkg01StaffAt($site, ['fleet.maintenance.manage', 'fleet.viewAny']);
    $backup = pkg01StaffAt($site, ['fleet.maintenance.manage']);
    $recipient = pkg01StaffAt($site);
    $unrelated = pkg01StaffAt($site);
    $asset = Asset::factory()->create(['site_id' => $site->id, 'category' => 'vehicle', 'name' => 'Blue fleet van']);
    $foreign = Asset::factory()->create(['site_id' => $otherSite->id, 'category' => 'vehicle', 'name' => 'Blue foreign van']);
    DB::table('fleet_maintenance_site_routes')->insert([
        'site_id' => $site->id, 'coordinator_user_id' => $manager->id,
        'backup_user_id' => $backup->id, 'approved_by_user_id' => $manager->id,
        'approved_at' => now(), 'version' => 1, 'created_at' => now(), 'updated_at' => now(),
    ]);

    $this->actingAs($reporter)->get('/fleet-assets/maintenance/work-orders/create')->assertOk();
    $this->actingAs($reporter)->getJson('/fleet-assets/maintenance/work-orders/options/search?type=assets&q=Blue')
        ->assertOk()->assertJsonFragment(['name' => 'Blue fleet van'])
        ->assertDontSee('Blue foreign van');
    $this->actingAs($reporter)->getJson('/fleet-assets/maintenance/work-orders/options/search?type=users&q=manager')
        ->assertForbidden();
    $this->actingAs($reporter)->get('/fleet-assets/maintenance/work-orders')->assertForbidden();
    $this->actingAs($reporter)->post('/fleet-assets/maintenance/work-orders', [
        'asset_id' => $asset->id, 'title' => 'Blue van concern',
        'description' => 'Brake lamp remained lit',
        'priority' => 'medium', 'request_key' => 'pkg01-http-report-one',
        'observed_local' => '2026-09-20T12:34',
        'files' => [['file' => UploadedFile::fake()->image('brake.jpg'),
            'category' => 'Condition photo', 'description' => 'Brake warning lamp']],
    ])->assertRedirect();
    $order = FleetWorkOrder::query()->where('title', 'Blue van concern')->firstOrFail();
    $report = DB::table('fleet_maintenance_reports')->where('work_order_id', $order->id)->first();
    expect($report->observed_at)->toBe('2026-09-20 00:34:00');
    $evidence = DB::table('fleet_maintenance_attachments')->where('report_id', $report->id)->first();
    expect($evidence->category)->toBe('Condition photo')
        ->and($evidence->description)->toBe('Brake warning lamp');
    Storage::disk('private')->assertExists($evidence->path);

    $this->actingAs($manager)->put("/fleet-assets/maintenance/work-orders/{$order->id}", [
        'operation' => 'propose_handover', 'version' => 0,
        'request_key' => 'pkg01-http-propose', 'target_user_id' => $recipient->id,
    ])->assertRedirect();
    $this->actingAs($unrelated)->get("/fleet-assets/maintenance/work-orders/{$order->id}")->assertNotFound();
    $this->actingAs($unrelated)->put("/fleet-assets/maintenance/work-orders/{$order->id}", [
        'operation' => 'accept_handover', 'version' => 1, 'request_key' => 'pkg01-http-unrelated',
    ])->assertForbidden();
    $this->actingAs($recipient)->get("/fleet-assets/maintenance/work-orders/{$order->id}")->assertOk();
    $this->actingAs($recipient)->put("/fleet-assets/maintenance/work-orders/{$order->id}", [
        'operation' => 'accept_handover', 'version' => 1, 'request_key' => 'pkg01-http-accept',
    ])->assertRedirect();
    expect($order->fresh()->assigned_to_user_id)->toBe($recipient->id);
    $this->actingAs($manager)->put("/fleet-assets/maintenance/work-orders/{$order->id}", [
        'operation' => 'propose_handover', 'version' => 2,
        'request_key' => 'pkg01-http-propose-backup', 'target_user_id' => $backup->id,
    ])->assertRedirect();
    $this->actingAs($manager)->put("/fleet-assets/maintenance/work-orders/{$order->id}", [
        'operation' => 'propose_handover', 'version' => 3,
        'request_key' => 'pkg01-http-propose-other', 'target_user_id' => $unrelated->id,
    ])->assertRedirect();
    $this->actingAs($backup)->put("/fleet-assets/maintenance/work-orders/{$order->id}", [
        'operation' => 'accept_handover', 'version' => 4, 'request_key' => 'pkg01-http-superseded',
    ])->assertForbidden();
    $unrelated->hrEmployeeProfile->update(['is_active' => false]);
    $this->actingAs($unrelated)->put("/fleet-assets/maintenance/work-orders/{$order->id}", [
        'operation' => 'accept_handover', 'version' => 4, 'request_key' => 'pkg01-http-inactive',
    ])->assertNotFound();
    expect($order->fresh()->assigned_to_user_id)->toBe($recipient->id);
    $this->actingAs($reporter)->post('/fleet-assets/maintenance/work-orders', [
        'asset_id' => $foreign->id, 'title' => 'Foreign', 'priority' => 'low',
        'request_key' => 'pkg01-http-foreign',
    ])->assertNotFound();
});

test('checks keep unknown provenance and only an unchanged exact approved version can pass', function () {
    $site = pkg01Site();
    $manager = pkg01StaffAt($site, ['fleet.maintenance.manage', 'fleet.viewAny']);
    $asset = Asset::factory()->create(['site_id' => $site->id, 'category' => 'vehicle']);
    $template = FleetChecklistTemplate::create([
        'name' => 'Synthetic tyre check', 'type' => 'custom',
        'items' => [['id' => 'tyres', 'label' => 'Tyres', 'required' => true]],
        'is_active' => true,
    ]);
    $base = [
        'asset_id' => $asset->id,
        'template_id' => $template->id,
        'check_kind' => 'check',
        'answers' => ['tyres' => ['result' => 'pass']],
        'request_key' => 'pkg01-check-unconfigured',
    ];

    $unknown = app(MaintenanceCheckService::class)->submit($manager, $base);
    expect($unknown->outcome)->toBe('needs_assessment')
        ->and($unknown->passed)->toBeFalse()
        ->and($unknown->rule_version_id)->toBeNull()
        ->and($unknown->presented_template_json['items'][0]['label'])->toBe('Tyres');

    $rules = [
        'template_id' => $template->id,
        'template_sha256' => MaintenanceFingerprint::of($template->items),
        'questions' => [['id' => 'tyres', 'required' => true, 'pass_values' => ['pass'], 'allow_na' => false]],
    ];
    $policyId = DB::table('fleet_maintenance_policy_versions')->insertGetId([
        'site_id' => $site->id,
        'asset_category' => 'vehicle',
        'rule_kind' => 'check',
        'version' => 1,
        'rules_json' => json_encode($rules, JSON_THROW_ON_ERROR),
        'content_sha256' => MaintenanceFingerprint::of($rules),
        'approved_by_user_id' => $manager->id,
        'approved_at' => now(),
        'created_at' => now(),
    ]);
    DB::table('fleet_maintenance_policy_assignments')->insert([
        'site_id' => $site->id, 'asset_category' => 'vehicle', 'rule_kind' => 'check',
        'policy_version_id' => $policyId, 'assigned_by_user_id' => $manager->id,
        'assigned_at' => now(), 'created_at' => now(), 'updated_at' => now(),
    ]);

    $passed = app(MaintenanceCheckService::class)->submit($manager, [
        ...$base, 'request_key' => 'pkg01-check-passed', 'rule_version_id' => $policyId,
        'corrects_run_id' => $unknown->id,
    ]);
    expect($passed->outcome)->toBe('passed')
        ->and(MaintenanceFingerprint::of($passed->rule_snapshot_json))->toBe(MaintenanceFingerprint::of($rules))
        ->and($passed->presented_template_json['items'][0]['label'])->toBe('Tyres')
        ->and($unknown->fresh()->outcome)->toBe('needs_assessment');

    $template->update(['items' => [['id' => 'tyres', 'label' => 'Tyres changed', 'required' => true]]]);
    $stale = app(MaintenanceCheckService::class)->submit($manager, [
        ...$base, 'request_key' => 'pkg01-check-stale', 'rule_version_id' => $policyId,
    ]);
    expect($stale->outcome)->toBe('needs_assessment')
        ->and($stale->passed)->toBeFalse()
        ->and($stale->presented_template_json['items'][0]['label'])->toBe('Tyres changed')
        ->and($passed->fresh()->presented_template_json['items'][0]['label'])->toBe('Tyres')
        ->and(FleetChecklistRun::count())->toBe(3);
    $this->actingAs($manager)->get("/fleet-assets/inspections/{$passed->id}")->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('fleet-assets/inspections/show')
            ->where('inspection.presented_template.items.0.label', 'Tyres')->etc());
    $this->actingAs($manager)->get("/fleet-assets/inspections/{$stale->id}")->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('fleet-assets/inspections/show')
            ->where('inspection.presented_template.items.0.label', 'Tyres changed')->etc());
});

test('approved advisory check failures do not create a booking block while unknown provenance remains unassessed', function () {
    $site = pkg01Site();
    $manager = pkg01StaffAt($site, ['fleet.maintenance.manage']);
    $asset = Asset::factory()->create(['site_id' => $site->id, 'category' => 'vehicle']);
    $template = FleetChecklistTemplate::create([
        'name' => 'Synthetic cosmetic check', 'type' => 'custom',
        'items' => [['id' => 'paint', 'label' => 'Paint', 'required' => true]], 'is_active' => true,
    ]);
    $rules = [
        'template_id' => $template->id,
        'template_sha256' => MaintenanceFingerprint::of($template->items),
        'availability_impact' => 'advisory',
        'questions' => [['id' => 'paint', 'pass_values' => ['pass'], 'allow_na' => false]],
    ];
    $policyId = DB::table('fleet_maintenance_policy_versions')->insertGetId([
        'site_id' => $site->id, 'asset_category' => 'vehicle', 'rule_kind' => 'check',
        'version' => 1, 'rules_json' => json_encode($rules, JSON_THROW_ON_ERROR),
        'content_sha256' => MaintenanceFingerprint::of($rules),
        'approved_by_user_id' => $manager->id, 'approved_at' => now(), 'created_at' => now(),
    ]);
    DB::table('fleet_maintenance_policy_assignments')->insert([
        'site_id' => $site->id, 'asset_category' => 'vehicle', 'rule_kind' => 'check',
        'policy_version_id' => $policyId, 'assigned_by_user_id' => $manager->id,
        'assigned_at' => now(), 'created_at' => now(), 'updated_at' => now(),
    ]);
    $service = app(MaintenanceCheckService::class);
    $advisory = $service->submit($manager, [
        'asset_id' => $asset->id, 'template_id' => $template->id, 'check_kind' => 'check',
        'rule_version_id' => $policyId, 'request_key' => 'pkg01-advisory-failed',
        'answers' => ['paint' => ['result' => 'fail']],
    ]);
    expect($advisory->outcome)->toBe('failed');
    app(MaintenanceRestrictionService::class)->assertBookable($asset->id);

    $template->update(['items' => [['id' => 'paint', 'label' => 'Paint changed', 'required' => true]]]);
    $unknown = $service->submit($manager, [
        'asset_id' => $asset->id, 'template_id' => $template->id, 'check_kind' => 'check',
        'rule_version_id' => $policyId, 'request_key' => 'pkg01-unknown-cosmetic',
        'answers' => ['paint' => ['result' => 'fail']],
    ]);
    expect($unknown->outcome)->toBe('needs_assessment')
        ->and($unknown->rule_version_id)->toBeNull();
    expect(fn () => app(MaintenanceRestrictionService::class)->assertBookable($asset->id))
        ->toThrow(ValidationException::class);
});

test('required evidence cannot be bypassed by NA or a client claim and a saved private file can satisfy it', function () {
    Storage::fake('private');
    $site = pkg01Site();
    $manager = pkg01StaffAt($site, ['fleet.maintenance.manage']);
    $asset = Asset::factory()->create(['site_id' => $site->id, 'category' => 'vehicle']);
    $template = FleetChecklistTemplate::create([
        'name' => 'Synthetic evidence check', 'type' => 'custom',
        'items' => [['id' => 'tyres', 'label' => 'Tyre photograph', 'required' => true, 'options' => ['pass', 'na']]],
        'is_active' => true,
    ]);
    $order = FleetWorkOrder::create([
        'asset_id' => $asset->id, 'reported_by_user_id' => $manager->id,
        'assigned_to_user_id' => $manager->id, 'title' => 'Evidence work',
        'category' => 'vehicle', 'priority' => 'low', 'status' => 'open',
    ]);
    $rules = [
        'template_id' => $template->id,
        'template_sha256' => MaintenanceFingerprint::of($template->items),
        'questions' => [['id' => 'tyres', 'pass_values' => ['pass'], 'allow_na' => true, 'evidence_required' => true]],
    ];
    $policyId = DB::table('fleet_maintenance_policy_versions')->insertGetId([
        'site_id' => $site->id, 'asset_category' => 'vehicle', 'rule_kind' => 'check',
        'version' => 1, 'rules_json' => json_encode($rules, JSON_THROW_ON_ERROR),
        'content_sha256' => MaintenanceFingerprint::of($rules),
        'approved_by_user_id' => $manager->id, 'approved_at' => now(), 'created_at' => now(),
    ]);
    DB::table('fleet_maintenance_policy_assignments')->insert([
        'site_id' => $site->id, 'asset_category' => 'vehicle', 'rule_kind' => 'check',
        'policy_version_id' => $policyId, 'assigned_by_user_id' => $manager->id,
        'assigned_at' => now(), 'created_at' => now(), 'updated_at' => now(),
    ]);
    $base = ['asset_id' => $asset->id, 'template_id' => $template->id,
        'check_kind' => 'check', 'work_order_id' => $order->id, 'rule_version_id' => $policyId];
    $service = app(MaintenanceCheckService::class);
    expect($service->submit($manager, [...$base, 'request_key' => 'pkg01-evidence-na',
        'answers' => ['tyres' => ['result' => 'na']]])->outcome)->toBe('needs_assessment');
    expect($service->submit($manager, [...$base, 'request_key' => 'pkg01-evidence-spoof',
        'answers' => ['tyres' => ['result' => 'pass', 'evidence_verified' => true]]])->outcome)->toBe('needs_assessment');

    $action = app(MaintenanceTransitionService::class)->execute($manager, $order->id,
        'note', 0, 'pkg01-evidence-note', ['note' => 'Evidence received']);
    $actionId = DB::table('fleet_maintenance_actions')->where('work_order_id', $action->id)->value('id');
    Storage::disk('private')->put('pkg01/tyre.jpg', 'synthetic photo bytes');
    $attachmentId = DB::table('fleet_maintenance_attachments')->insertGetId([
        'work_order_id' => $order->id, 'action_id' => $actionId,
        'uploaded_by_user_id' => $manager->id, 'disk' => 'private', 'path' => 'pkg01/tyre.jpg',
        'original_name' => 'tyre.jpg', 'mime_type' => 'image/jpeg', 'byte_size' => 21,
        'sha256' => hash('sha256', 'synthetic photo bytes'),
        'request_key' => 'pkg01-fixture-photo', 'request_fingerprint' => str_repeat('a', 64),
        'created_at' => now(),
    ]);
    expect($service->submit($manager, [...$base, 'request_key' => 'pkg01-evidence-private',
        'answers' => ['tyres' => ['result' => 'pass', 'evidence_attachment_id' => $attachmentId]]])->outcome)->toBe('passed');

    $otherOrder = FleetWorkOrder::create([
        'asset_id' => $asset->id, 'reported_by_user_id' => $manager->id,
        'assigned_to_user_id' => $manager->id, 'title' => 'Unrelated evidence source',
        'category' => 'vehicle', 'priority' => 'low', 'status' => 'open',
    ]);
    $this->actingAs($manager)->post('/fleet-assets/maintenance/work-orders/'.$otherOrder->id.'/attachments', [
        'parent_type' => 'work', 'parent_id' => 0, 'request_key' => 'pkg01-http-private-photo',
        'file' => UploadedFile::fake()->image('private-tyre.jpg'),
    ])->assertRedirect()->assertSessionHas('maintenance_attachment_id');
    $uploadedId = (int) session('maintenance_attachment_id');
    expect($uploadedId)->toBeGreaterThan(0);
    $this->actingAs($manager)->post('/fleet-assets/maintenance/work-orders/'.$otherOrder->id.'/checks', [
        'template_id' => $template->id, 'rule_version_id' => $policyId,
        'answers' => ['tyres' => ['result' => 'pass', 'evidence_attachment_id' => $uploadedId]],
        'request_key' => 'pkg01-http-check-with-photo',
    ])->assertRedirect();
    expect(FleetChecklistRun::query()->where('request_key', 'pkg01-http-check-with-photo')->value('outcome'))->toBe('passed');
    $this->actingAs($manager)->post('/fleet-assets/maintenance/work-orders/'.$order->id.'/checks', [
        'template_id' => $template->id, 'rule_version_id' => $policyId,
        'answers' => ['tyres' => ['result' => 'pass', 'evidence_attachment_id' => $uploadedId]],
        'request_key' => 'pkg01-http-borrowed-photo',
    ])->assertNotFound();
    expect(FleetChecklistRun::query()->where('request_key', 'pkg01-http-borrowed-photo')->exists())->toBeFalse();

    $conditional = $rules;
    $conditional['questions'][0]['when'] = ['another_answer' => 'yes'];
    expect(app(\App\Services\Fleet\MaintenancePolicyService::class)->checkOutcome(
        ['id' => $policyId, 'version' => 1, 'rules' => $conditional, 'sha256' => MaintenanceFingerprint::of($conditional)],
        ['tyres' => ['result' => 'pass', 'evidence_verified' => true]],
    ))->toBe('needs_assessment');

    $conditional = ['questions' => [
        ['id' => 'warning', 'pass_values' => ['yes', 'no'], 'allow_na' => false],
        ['id' => 'photo', 'pass_values' => ['pass'], 'allow_na' => false,
            'when' => ['question_id' => 'warning', 'equals' => 'yes'], 'evidence_required' => true],
    ]];
    $evaluator = app(\App\Services\Fleet\MaintenancePolicyService::class);
    $conditionalPolicy = ['id' => $policyId, 'version' => 2, 'rules' => $conditional,
        'sha256' => MaintenanceFingerprint::of($conditional)];
    expect($evaluator->checkOutcome($conditionalPolicy, [
        'warning' => ['result' => 'no'],
    ]))->toBe('passed');
    expect($evaluator->checkOutcome($conditionalPolicy, [
        'warning' => ['result' => 'yes'], 'photo' => ['result' => 'pass'],
    ]))->toBe('needs_assessment');
    expect($evaluator->checkOutcome($conditionalPolicy, [
        'warning' => ['result' => 'yes'], 'photo' => ['result' => 'pass', 'evidence_verified' => true],
    ]))->toBe('passed');
    expect($evaluator->checkOutcome($conditionalPolicy, [
        'warning' => ['result' => 'no'], 'photo' => ['result' => 'pass', 'evidence_verified' => true],
    ]))->toBe('needs_assessment');
});

test('single transitions guard completion, replay identity and handover acknowledgement', function () {
    Bus::fake([ProcessFinancialEventJob::class]);
    $site = pkg01Site();
    $manager = pkg01StaffAt($site, ['fleet.maintenance.manage']);
    $recipient = pkg01StaffAt($site);
    $asset = Asset::factory()->create(['site_id' => $site->id, 'category' => 'vehicle']);
    $order = FleetWorkOrder::create([
        'asset_id' => $asset->id, 'reported_by_user_id' => $manager->id,
        'assigned_to_user_id' => $manager->id, 'title' => 'Tyre service',
        'category' => 'vehicle', 'priority' => 'low', 'status' => 'open',
        'estimated_cost' => '80.00', 'actual_cost' => '90.00',
    ]);
    $service = app(MaintenanceTransitionService::class);

    expect(fn () => $service->execute($manager, $order->id, 'complete', 0, 'pkg01-complete-blocked'))
        ->toThrow(ValidationException::class);
    expect($order->fresh()->status)->toBe('open')
        ->and(DB::table('fleet_maintenance_actions')->count())->toBe(0);

    $started = $service->execute($manager, $order->id, 'start', 0, 'pkg01-start-one');
    expect($started->version)->toBe(1);
    expect($service->execute($manager, $order->id, 'start', 0, 'pkg01-start-one')->version)->toBe(1);
    expect(fn () => $service->execute($manager, $order->id, 'start', 1, 'pkg01-start-one', ['note' => 'changed']))
        ->toThrow(HttpException::class);

    $proposed = $service->execute($manager, $order->id, 'propose_handover', 1,
        'pkg01-propose-one', ['target_user_id' => $recipient->id]);
    expect($proposed->assigned_to_user_id)->toBe($manager->id);
    $accepted = $service->execute($recipient, $order->id, 'accept_handover', 2, 'pkg01-accept-one');
    expect($accepted->assigned_to_user_id)->toBe($recipient->id);

    // Even a direct legacy model change cannot trigger the old observer's
    // completion/estimate-based FinancialEvent posting bypass.
    $accepted->update(['status' => 'completed', 'completed_at' => now()]);
    expect(FinFinancialEvent::query()->where('source_type', FleetWorkOrder::class)
        ->where('source_id', $order->id)->count())->toBe(0);
    Bus::assertNotDispatched(ProcessFinancialEventJob::class);
});

test('triage deadlines and provider responses retain exact Auckland source times', function () {
    $site = pkg01Site();
    $manager = pkg01StaffAt($site, ['fleet.maintenance.manage']);
    $asset = Asset::factory()->create(['site_id' => $site->id, 'category' => 'vehicle']);
    $order = FleetWorkOrder::create([
        'asset_id' => $asset->id, 'reported_by_user_id' => $manager->id,
        'assigned_to_user_id' => $manager->id, 'title' => 'Tyre appointment',
        'category' => 'vehicle', 'priority' => 'medium', 'status' => 'open',
    ]);
    $service = app(MaintenanceTransitionService::class);
    $triage = $service->execute($manager, $order->id, 'update_next_action', 0,
        'pkg01-next-action', ['next_action' => 'Ask provider for a booking',
            'due_local' => '2026-04-05T02:30', 'due_offset' => '+13:00']);
    expect($triage->due_at->utc()->format('Y-m-d H:i'))->toBe('2026-04-04 13:30')
        ->and($triage->next_action)->toBe('Ask provider for a booking');
    expect(fn () => $service->execute($manager, $order->id, 'update_next_action', 1,
        'pkg01-gap-deadline', ['next_action' => 'Ask provider for a booking',
            'due_local' => '2026-09-27T02:30']))->toThrow(ValidationException::class);
    expect($order->fresh()->version)->toBe(1);

    $plan = $service->execute($manager, $order->id, 'plan_provider', 1,
        'pkg01-provider-plan', ['provider_name' => 'Synthetic workshop',
            'starts_local' => '2026-10-01T09:00', 'ends_local' => '2026-10-01T10:00']);
    expect($plan->version)->toBe(2);
    $savedPlan = DB::table('fleet_maintenance_actions')->where('work_order_id', $order->id)
        ->where('action_type', 'plan_provider')->first();
    $savedPayload = json_decode($savedPlan->payload_json, true);
    expect($savedPayload['starts_local'])->toBe('2026-10-01T09:00')
        ->and($savedPayload['starts_at'])->toBe('2026-09-30 20:00:00');
    $calendar = app(MaintenanceWindowObligationProvider::class);
    $window = fn () => collect($calendar->obligations([$site->id],
        Carbon::parse('2026-09-30 00:00:00', 'UTC'), Carbon::parse('2026-10-02 00:00:00', 'UTC')))
        ->filter(fn ($item) => $item->id === 'maintenance-provider-'.$order->id)->values();
    expect($window()->count())->toBe(1)->and($window()->first()->status)->toBe('planned');
    $confirmed = $service->execute($manager, $order->id, 'record_provider_confirmation', 2,
        'pkg01-provider-confirm', ['response_method' => 'Phone', 'provider_reference' => 'SYN-123']);
    expect($confirmed->version)->toBe(3);
    expect($window()->count())->toBe(1)->and($window()->first()->status)->toBe('confirmed');
    expect(fn () => $service->execute($manager, $order->id, 'record_provider_confirmation', 3,
        'pkg01-provider-double', ['response_method' => 'Phone', 'provider_reference' => 'SYN-123']))
        ->toThrow(ValidationException::class);
    $completed = $service->execute($manager, $order->id, 'record_provider_completion', 3,
        'pkg01-provider-complete', ['service_summary' => 'Tyres checked']);
    expect($completed->version)->toBe(4)
        ->and($completed->status)->toBe('open');
    expect($window()->count())->toBe(1)->and($window()->first()->status)->toBe('completed');
});

test('release requires an independent category-granted reviewer, current passing retest and custody', function () {
    $this->beforeApplicationDestroyed(CommittedFixtureCleanup::capture()->restore(...));
    Storage::fake('private');
    $site = pkg01Site();
    $manager = pkg01StaffAt($site, ['fleet.maintenance.manage']);
    $reviewer = pkg01StaffAt($site, ['fleet.maintenance.release']);
    $recipient = pkg01StaffAt($site);
    $asset = Asset::factory()->create(['site_id' => $site->id, 'category' => 'vehicle']);
    $order = FleetWorkOrder::create([
        'asset_id' => $asset->id, 'reported_by_user_id' => $manager->id,
        'assigned_to_user_id' => $manager->id, 'title' => 'Safety repair',
        'category' => 'vehicle', 'priority' => 'high', 'status' => 'open',
    ]);
    $booking = \App\Models\FleetVehicleBooking::create([
        'asset_id' => $asset->id, 'user_id' => $manager->id,
        'purpose' => 'Synthetic existing booking', 'starts_at' => now()->addDay(),
        'ends_at' => now()->addDay()->addHour(), 'status' => 'pending',
    ]);
    $template = FleetChecklistTemplate::create([
        'name' => 'Synthetic retest', 'type' => 'custom',
        'items' => [['id' => 'brakes', 'label' => 'Brakes', 'required' => true]], 'is_active' => true,
    ]);
    $policyIds = [];
    foreach ([
        'hold' => ['allowed_kinds' => ['safety']],
        'repair' => ['requires_service_evidence' => true],
        'retest' => ['template_id' => $template->id,
            'template_sha256' => MaintenanceFingerprint::of($template->items),
            'questions' => [['id' => 'brakes', 'pass_values' => ['pass'], 'allow_na' => false]]],
        'release' => ['requires_custody' => true],
    ] as $kind => $rules) {
        $id = DB::table('fleet_maintenance_policy_versions')->insertGetId([
            'site_id' => $site->id, 'asset_category' => 'vehicle', 'rule_kind' => $kind,
            'version' => 1, 'rules_json' => json_encode($rules, JSON_THROW_ON_ERROR),
            'content_sha256' => MaintenanceFingerprint::of($rules),
            'approved_by_user_id' => $manager->id, 'approved_at' => now(), 'created_at' => now(),
        ]);
        $policyIds[$kind] = $id;
        DB::table('fleet_maintenance_policy_assignments')->insert([
            'site_id' => $site->id, 'asset_category' => 'vehicle', 'rule_kind' => $kind,
            'policy_version_id' => $id, 'assigned_by_user_id' => $manager->id,
            'assigned_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);
    }
    DB::table('fleet_maintenance_reviewer_grants')->insert([
        'site_id' => $site->id, 'user_id' => $reviewer->id, 'asset_category' => 'vehicle',
        'review_kind' => 'maintenance_release', 'version' => 1, 'decision' => 'grant',
        'recorded_by_user_id' => $manager->id, 'recorded_at' => now(), 'created_at' => now(),
    ]);
    $releasePermission = Permission::query()->firstOrCreate(
        ['key' => 'fleet.maintenance.release'],
        ['description' => 'fleet.maintenance.release', 'group' => 'fleet', 'module' => 'Resources'],
    );
    $manager->permissionOverrides()->attach($releasePermission, ['allowed' => true]);
    DB::table('fleet_maintenance_reviewer_grants')->insert([
        'site_id' => $site->id, 'user_id' => $manager->id, 'asset_category' => 'vehicle',
        'review_kind' => 'maintenance_release', 'version' => 1, 'decision' => 'grant',
        'recorded_by_user_id' => $manager->id, 'recorded_at' => now(), 'created_at' => now(),
    ]);

    $service = app(MaintenanceTransitionService::class);
    $service->execute($manager, $order->id, 'place_restriction', 0, 'pkg01-hold-one', ['restriction_kind' => 'safety']);
    $holdOneId = (int) DB::table('fleet_maintenance_restrictions')->where('work_order_id', $order->id)->value('id');
    expect(fn () => $service->execute($manager, $order->id, 'cancel', 1, 'pkg01-cancel-held'))
        ->toThrow(ValidationException::class);
    expect(fn () => app(MaintenanceRestrictionService::class)->assertBookable($asset->id))
        ->toThrow(ValidationException::class);
    $service->execute($manager, $order->id, 'attest_repair', 1, 'pkg01-attest-one', ['summary' => 'Brake line repaired']);
    $attestation = DB::table('fleet_maintenance_actions')->where('work_order_id', $order->id)
        ->where('action_type', 'attest_repair')->first();
    expect(fn () => $service->execute($manager, $order->id, 'complete', 2, 'pkg01-complete-no-file'))
        ->toThrow(ValidationException::class);
    Storage::disk('private')->put('pkg01/repair.pdf', 'synthetic repair evidence');
    DB::table('fleet_maintenance_attachments')->insert([
        'work_order_id' => $order->id, 'action_id' => $attestation->id,
        'uploaded_by_user_id' => $manager->id, 'disk' => 'private', 'path' => 'pkg01/repair.pdf',
        'original_name' => 'repair.pdf', 'mime_type' => 'application/pdf', 'byte_size' => 25,
        'sha256' => hash('sha256', 'synthetic repair evidence'),
        'request_key' => 'pkg01-fixture-repair', 'request_fingerprint' => str_repeat('b', 64),
        'created_at' => now(),
    ]);
    $completed = $service->execute($manager, $order->id, 'complete', 2, 'pkg01-complete-one');
    expect($completed->status)->toBe('completed');
    expect(fn () => $service->execute($reviewer, $order->id, 'release', 3, 'pkg01-release-no-retest'))
        ->toThrow(ValidationException::class);

    $failed = app(MaintenanceCheckService::class)->submit($manager, [
        'asset_id' => $asset->id, 'template_id' => $template->id, 'check_kind' => 'retest',
        'work_order_id' => $order->id, 'rule_version_id' => $policyIds['retest'],
        'covered_restriction_ids' => [$holdOneId],
        'answers' => ['brakes' => ['result' => 'fail']], 'request_key' => 'pkg01-retest-fail',
    ]);
    expect($failed->outcome)->toBe('failed');
    expect(fn () => $service->execute($reviewer, $order->id, 'release', 3, 'pkg01-release-failed'))
        ->toThrow(ValidationException::class);
    $passed = app(MaintenanceCheckService::class)->submit($manager, [
        'asset_id' => $asset->id, 'template_id' => $template->id, 'check_kind' => 'retest',
        'work_order_id' => $order->id, 'rule_version_id' => $policyIds['retest'],
        'covered_restriction_ids' => [$holdOneId],
        'answers' => ['brakes' => ['result' => 'pass']], 'request_key' => 'pkg01-retest-pass',
    ]);
    expect($passed->outcome)->toBe('passed');
    expect(DB::table('fleet_checklist_runs')->where('id', $passed->id)->value('submitted_at'))
        ->toBeGreaterThan($attestation->occurred_at);
    expect(fn () => $service->execute($reviewer, $order->id, 'release', 3, 'pkg01-release-no-custody'))
        ->toThrow(ValidationException::class);
    $service->execute($manager, $order->id, 'place_restriction', 3, 'pkg01-hold-after-retest', ['restriction_kind' => 'safety']);
    $holdIds = DB::table('fleet_maintenance_restrictions')->where('work_order_id', $order->id)
        ->where('state', 'active')->orderBy('id')->pluck('id')->map(fn ($id) => (int) $id)->all();
    expect(count($holdIds))->toBe(2);
    expect(fn () => $service->execute($reviewer, $order->id, 'release', 4, 'pkg01-release-stale-hold'))
        ->toThrow(ValidationException::class);
    $freshRetest = app(MaintenanceCheckService::class)->submit($manager, [
        'asset_id' => $asset->id, 'template_id' => $template->id, 'check_kind' => 'retest',
        'work_order_id' => $order->id, 'rule_version_id' => $policyIds['retest'],
        'covered_restriction_ids' => $holdIds, 'corrects_run_id' => $passed->id,
        'answers' => ['brakes' => ['result' => 'pass']], 'request_key' => 'pkg01-retest-fresh',
    ]);
    expect($freshRetest->outcome)->toBe('passed');
    $service->execute($manager, $order->id, 'propose_custody', 4, 'pkg01-propose-custody', ['target_user_id' => $recipient->id]);
    $this->actingAs($recipient)->get('/fleet-assets/maintenance/work-orders/'.$order->id)
        ->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('can.custody', true)->where('can.details', false)
            ->where('release_readiness.repair_attested', true)
            ->where('release_readiness.repair_evidence_saved', true)
            ->where('attachments', [])->where('finance', [])->etc());
    $unrelated = pkg01StaffAt($site);
    $this->actingAs($unrelated)->get('/fleet-assets/maintenance/work-orders/'.$order->id)->assertNotFound();
    $this->actingAs($recipient)->put('/fleet-assets/maintenance/work-orders/'.$order->id, [
        'operation' => 'acknowledge_custody', 'version' => 5,
        'request_key' => 'pkg01-custody', 'received' => true,
    ])->assertRedirect();
    expect($order->fresh()->version)->toBe(6);
    $this->actingAs($recipient)->get('/fleet-assets/maintenance/work-orders/'.$order->id)->assertOk();
    expect(fn () => $service->execute($manager, $order->id, 'release', 6, 'pkg01-release-wrong-reviewer'))
        ->toThrow(ValidationException::class);

    // A reviewer already preparing version 7 must re-read sources after the
    // asset lock, even when version 7 was produced by a concurrent new hold.
    $database = DB::connection()->getDatabaseName();
    $token = Str::uuid()->toString();
    $ready = sys_get_temp_dir().DIRECTORY_SEPARATOR."pkg01-release-ready-{$token}";
    $attempt = sys_get_temp_dir().DIRECTORY_SEPARATOR."pkg01-release-attempt-{$token}";
    $barrier = sys_get_temp_dir().DIRECTORY_SEPARATOR."pkg01-release-barrier-{$token}";
    $worker = <<<'PHP'
require $argv[1].'/vendor/autoload.php';
$app = require $argv[1].'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
file_put_contents($argv[4], 'ready');
$deadline = microtime(true) + 15;
while (! is_file($argv[6])) {
    if (microtime(true) >= $deadline) throw new RuntimeException('Release barrier timed out');
    usleep(10000);
}
file_put_contents($argv[5], 'attempt');
try {
    $actor = App\Models\User::query()->findOrFail((int) $argv[2]);
    app(App\Services\Fleet\MaintenanceTransitionService::class)->execute(
        $actor, (int) $argv[3], 'release', 7, 'pkg01-overlap-release');
    echo 'released';
} catch (Illuminate\Validation\ValidationException $error) {
    echo 'blocked';
}
PHP;
    $process = null;
    DB::connection()->commit();
    try {
        DB::connection()->beginTransaction();
        Asset::query()->whereKey($asset->id)->lockForUpdate()->firstOrFail();
        $process = new Process([PHP_BINARY, '-r', $worker, base_path(), (string) $reviewer->id,
            (string) $order->id, $ready, $attempt, $barrier], base_path(), [
                'APP_ENV' => 'testing', 'DB_CONNECTION' => 'mysql', 'DB_DATABASE' => $database,
                'QUEUE_CONNECTION' => 'null',
            ]);
        $process->setTimeout(30);
        $process->start();
        pkg01WaitForFiles([$ready], 'Release worker did not bootstrap');
        touch($barrier);
        pkg01WaitForFiles([$attempt], 'Release worker did not reach the asset lock');
        usleep(250000);
        expect($process->isRunning())->toBeTrue();
        $service->execute($manager, $order->id, 'place_restriction', 6,
            'pkg01-overlap-new-hold', ['restriction_kind' => 'safety']);
        DB::connection()->commit();
        $process->wait();
        expect($process->isSuccessful())->toBeTrue()
            ->and(trim($process->getOutput()))->toBe('blocked')
            ->and(DB::table('fleet_maintenance_restrictions')->where('work_order_id', $order->id)
                ->where('state', 'active')->count())->toBe(3);
    } finally {
        while (DB::connection()->transactionLevel() > 0) DB::connection()->rollBack();
        if ($process?->isRunning()) $process->stop(1);
        foreach ([$ready, $attempt, $barrier] as $path) if (is_file($path)) unlink($path);
    }
    $currentHoldIds = DB::table('fleet_maintenance_restrictions')->where('work_order_id', $order->id)
        ->where('state', 'active')->orderBy('id')->pluck('id')->map(fn ($id) => (int) $id)->all();
    $again = app(MaintenanceCheckService::class)->submit($manager, [
        'asset_id' => $asset->id, 'template_id' => $template->id, 'check_kind' => 'retest',
        'work_order_id' => $order->id, 'rule_version_id' => $policyIds['retest'],
        'covered_restriction_ids' => $currentHoldIds, 'corrects_run_id' => $freshRetest->id,
        'answers' => ['brakes' => ['result' => 'pass']], 'request_key' => 'pkg01-overlap-fresh-retest',
    ]);
    expect($again->outcome)->toBe('passed');
    $service->execute($manager, $order->id, 'propose_custody', 7, 'pkg01-overlap-propose-custody', ['target_user_id' => $recipient->id]);
    $service->execute($recipient, $order->id, 'acknowledge_custody', 8, 'pkg01-overlap-custody', ['received' => true]);
    $released = $service->execute($reviewer, $order->id, 'release', 9, 'pkg01-release-authorised');
    expect($released->version)->toBe(10)
        ->and(DB::table('fleet_maintenance_restrictions')->where('work_order_id', $order->id)
            ->where('state', 'active')->count())->toBe(0)
        ->and(DB::table('fleet_maintenance_effects')->where('work_order_id', $order->id)
            ->where('state', 'pending')->count())->toBe(1);
    expect(config('fleet_maintenance.effects_enabled'))->toBeFalse();
    expect(DB::table('fleet_maintenance_booking_impacts')->where('booking_id', $booking->id)->count())->toBe(3)
        ->and(DB::table('fleet_maintenance_booking_impacts')->where('booking_id', $booking->id)
            ->where('followup_state', 'needs_review')->whereNotNull('release_action_id')->count())->toBe(3)
        ->and($booking->fresh()->status)->toBe('pending');
    $this->actingAs($recipient)->get('/fleet-assets/maintenance/work-orders/'.$order->id)->assertNotFound();
    app(MaintenanceRestrictionService::class)->assertBookable($asset->id);
    $effectId = (int) DB::table('fleet_maintenance_effects')->where('work_order_id', $order->id)->value('id');
    $manager->hrEmployeeProfile->update(['is_active' => false]);
    expect(app(MaintenanceEffectDispatcher::class)->dispatchOne($effectId))->toBe('retry')
        ->and(DB::table('notifications')->where('notifiable_id', $manager->id)->count())->toBe(0);
    $manager->hrEmployeeProfile->update(['is_active' => true]);
    DB::table('fleet_maintenance_effects')->where('id', $effectId)->update(['next_attempt_at' => now()->subMinute()]);
    config()->set('fleet_maintenance.effects_enabled', true);
    $this->artisan('maintenance:dispatch-effects --limit=25')->assertExitCode(0);
    expect(DB::table('fleet_maintenance_effects')->where('id', $effectId)->value('state'))->toBe('delivered')
        ->and(app(MaintenanceEffectDispatcher::class)->dispatchOne($effectId))->toBe('delivered')
        ->and(DB::table('notifications')->where('notifiable_id', $manager->id)->count())->toBe(1);
});

test('a completed held repair can recover a new rule and enabled committed effects deliver once', function () {
    $this->beforeApplicationDestroyed(CommittedFixtureCleanup::capture()->restore(...));
    Storage::fake('private');
    expect(config('fleet_maintenance.effects_enabled'))->toBeFalse();
    $site = pkg01Site();
    $manager = pkg01StaffAt($site, ['fleet.maintenance.manage']);
    $reviewer = pkg01StaffAt($site, ['fleet.maintenance.release']);
    $recipient = pkg01StaffAt($site);
    $asset = Asset::factory()->create(['site_id' => $site->id, 'category' => 'vehicle']);
    $template = FleetChecklistTemplate::create([
        'name' => 'Synthetic automatic release retest', 'type' => 'custom',
        'items' => [['id' => 'brakes', 'label' => 'Brakes', 'required' => true]], 'is_active' => true,
    ]);
    $policies = [];
    foreach ([
        'hold' => ['allowed_kinds' => ['safety']],
        'repair' => ['requires_service_evidence' => true],
        'retest' => ['template_id' => $template->id,
            'template_sha256' => MaintenanceFingerprint::of($template->items),
            'questions' => [['id' => 'brakes', 'pass_values' => ['pass'], 'allow_na' => false]]],
        'release' => ['requires_custody' => true],
    ] as $kind => $rules) {
        $id = DB::table('fleet_maintenance_policy_versions')->insertGetId([
            'site_id' => $site->id, 'asset_category' => 'vehicle', 'rule_kind' => $kind,
            'version' => 1, 'rules_json' => json_encode($rules, JSON_THROW_ON_ERROR),
            'content_sha256' => MaintenanceFingerprint::of($rules),
            'approved_by_user_id' => $manager->id, 'approved_at' => now(), 'created_at' => now(),
        ]);
        $policies[$kind] = $id;
        DB::table('fleet_maintenance_policy_assignments')->insert([
            'site_id' => $site->id, 'asset_category' => 'vehicle', 'rule_kind' => $kind,
            'policy_version_id' => $id, 'assigned_by_user_id' => $manager->id,
            'assigned_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);
    }
    DB::table('fleet_maintenance_reviewer_grants')->insert([
        'site_id' => $site->id, 'user_id' => $reviewer->id, 'asset_category' => 'vehicle',
        'review_kind' => 'maintenance_release', 'version' => 1, 'decision' => 'grant',
        'recorded_by_user_id' => $manager->id, 'recorded_at' => now(), 'created_at' => now(),
    ]);
    $service = app(MaintenanceTransitionService::class);
    $attach = function (FleetWorkOrder $order, int $actionId, string $key) use ($manager): void {
        Storage::disk('private')->put("pkg01/{$key}.pdf", 'synthetic service evidence');
        DB::table('fleet_maintenance_attachments')->insert([
            'work_order_id' => $order->id, 'action_id' => $actionId,
            'uploaded_by_user_id' => $manager->id, 'disk' => 'private', 'path' => "pkg01/{$key}.pdf",
            'original_name' => "{$key}.pdf", 'mime_type' => 'application/pdf', 'byte_size' => 26,
            'sha256' => hash('sha256', 'synthetic service evidence'),
            'request_key' => $key, 'request_fingerprint' => str_repeat('a', 64),
            'created_at' => now(),
        ]);
    };
    $create = function (string $name) use ($asset, $manager): FleetWorkOrder {
        return FleetWorkOrder::create([
            'asset_id' => $asset->id, 'reported_by_user_id' => $manager->id,
            'assigned_to_user_id' => $manager->id, 'title' => $name,
            'category' => 'vehicle', 'priority' => 'high', 'status' => 'open',
        ]);
    };
    $first = $create('Synthetic changed-rule repair');
    $service->execute($manager, $first->id, 'place_restriction', 0, 'pkg01-auto-hold-a', ['restriction_kind' => 'safety']);
    $service->execute($manager, $first->id, 'attest_repair', 1, 'pkg01-auto-attest-a', ['summary' => 'Initial repair']);
    $oldAttestation = DB::table('fleet_maintenance_actions')->where('work_order_id', $first->id)
        ->where('action_type', 'attest_repair')->orderByDesc('id')->first();
    $attach($first, $oldAttestation->id, 'pkg01-auto-evidence-a');
    $service->execute($manager, $first->id, 'complete', 2, 'pkg01-auto-complete-a');

    $newRepairRules = ['requires_service_evidence' => true, 'review_note' => 'Synthetic approved rule revision'];
    $newRepairId = DB::table('fleet_maintenance_policy_versions')->insertGetId([
        'site_id' => $site->id, 'asset_category' => 'vehicle', 'rule_kind' => 'repair',
        'version' => 2, 'rules_json' => json_encode($newRepairRules, JSON_THROW_ON_ERROR),
        'content_sha256' => MaintenanceFingerprint::of($newRepairRules),
        'approved_by_user_id' => $manager->id, 'approved_at' => now(), 'created_at' => now(),
    ]);
    DB::table('fleet_maintenance_policy_assignments')->where('site_id', $site->id)
        ->where('asset_category', 'vehicle')->where('rule_kind', 'repair')
        ->update(['policy_version_id' => $newRepairId, 'updated_at' => now()]);
    expect(fn () => $service->execute($reviewer, $first->id, 'release', 3, 'pkg01-auto-stale-release'))
        ->toThrow(ValidationException::class);
    $service->execute($manager, $first->id, 'attest_repair', 3, 'pkg01-auto-attest-current',
        ['summary' => 'Reviewed against the new rule']);
    expect($first->fresh()->status)->toBe('completed')
        ->and(DB::table('fleet_maintenance_actions')->where('work_order_id', $first->id)
            ->where('action_type', 'attest_repair')->count())->toBe(2)
        ->and(DB::table('fleet_maintenance_actions')->where('id', $oldAttestation->id)->value('policy_version_id'))
            ->toBe($policies['repair']);
    expect(fn () => $service->execute($manager, $first->id, 'attest_repair', 4,
        'pkg01-auto-attest-duplicate', ['summary' => 'No later rule']))->toThrow(ValidationException::class);
    $newAttestationId = (int) DB::table('fleet_maintenance_actions')->where('work_order_id', $first->id)
        ->where('action_type', 'attest_repair')->orderByDesc('id')->value('id');
    $attach($first, $newAttestationId, 'pkg01-auto-evidence-current');

    $ready = function (FleetWorkOrder $order, int $version, string $suffix) use ($service, $manager, $recipient, $template, $policies): int {
        $holdIds = DB::table('fleet_maintenance_restrictions')->where('work_order_id', $order->id)
            ->where('state', 'active')->orderBy('id')->pluck('id')->map(fn ($id) => (int) $id)->all();
        $run = app(MaintenanceCheckService::class)->submit($manager, [
            'asset_id' => $order->asset_id, 'template_id' => $template->id,
            'check_kind' => 'retest', 'work_order_id' => $order->id,
            'rule_version_id' => $policies['retest'], 'covered_restriction_ids' => $holdIds,
            'answers' => ['brakes' => ['result' => 'pass']], 'request_key' => "pkg01-auto-retest-{$suffix}",
        ]);
        expect($run->outcome)->toBe('passed');
        $service->execute($manager, $order->id, 'propose_custody', $version,
            "pkg01-auto-custody-offer-{$suffix}", ['target_user_id' => $recipient->id]);
        $service->execute($recipient, $order->id, 'acknowledge_custody', $version + 1,
            "pkg01-auto-custody-receipt-{$suffix}", ['received' => true]);
        return $version + 2;
    };
    $firstVersion = $ready($first, 4, 'a');
    DB::connection()->commit(); // End RefreshDatabase's outer transaction so afterCommit can really run.
    DB::connection()->beginTransaction();
    try {
        config()->set('fleet_maintenance.effects_enabled', true);
        $service->execute($reviewer, $first->id, 'release', $firstVersion, 'pkg01-auto-rollback');
        expect(DB::table('fleet_maintenance_effects')->where('work_order_id', $first->id)->count())->toBe(1);
    } finally {
        DB::connection()->rollBack();
    }
    expect(DB::table('fleet_maintenance_effects')->where('work_order_id', $first->id)->count())->toBe(0)
        ->and(DB::table('notifications')->where('type', 'fleet.maintenance.released')->count())->toBe(0)
        ->and(DB::table('fleet_maintenance_restrictions')->where('work_order_id', $first->id)
            ->where('state', 'active')->count())->toBe(1);
    $service->execute($reviewer, $first->id, 'release', $firstVersion, 'pkg01-auto-commit');
    expect(DB::table('fleet_maintenance_effects')->where('work_order_id', $first->id)->value('state'))->toBe('delivered')
        ->and(DB::table('notifications')->where('type', 'fleet.maintenance.released')->count())->toBe(1);

    $second = $create('Synthetic retry release');
    $service->execute($manager, $second->id, 'place_restriction', 0, 'pkg01-auto-hold-b', ['restriction_kind' => 'safety']);
    $service->execute($manager, $second->id, 'attest_repair', 1, 'pkg01-auto-attest-b', ['summary' => 'Second repair']);
    $secondAttestationId = (int) DB::table('fleet_maintenance_actions')->where('work_order_id', $second->id)
        ->where('action_type', 'attest_repair')->orderByDesc('id')->value('id');
    $attach($second, $secondAttestationId, 'pkg01-auto-evidence-b');
    $service->execute($manager, $second->id, 'complete', 2, 'pkg01-auto-complete-b');
    $secondVersion = $ready($second, 3, 'b');
    $manager->hrEmployeeProfile->update(['is_active' => false]);
    $service->execute($reviewer, $second->id, 'release', $secondVersion, 'pkg01-auto-retry');
    expect(DB::table('fleet_maintenance_effects')->where('work_order_id', $second->id)->value('state'))->toBe('retry')
        ->and(DB::table('notifications')->where('type', 'fleet.maintenance.released')->count())->toBe(1);
    $manager->hrEmployeeProfile->update(['is_active' => true]);
    DB::table('fleet_maintenance_effects')->where('work_order_id', $second->id)
        ->update(['next_attempt_at' => now()->subMinute()]);
    $this->artisan('maintenance:dispatch-effects --limit=25')->assertExitCode(0);
    $this->artisan('maintenance:dispatch-effects --limit=25')->assertExitCode(0);
    expect(DB::table('fleet_maintenance_effects')->where('work_order_id', $second->id)->value('state'))->toBe('delivered')
        ->and(DB::table('notifications')->where('type', 'fleet.maintenance.released')->count())->toBe(2);
    config()->set('fleet_maintenance.effects_enabled', false);
});

test('an overlapping booking decision waits for the asset lock and sees a newly committed hold', function () {
    $this->beforeApplicationDestroyed(CommittedFixtureCleanup::capture()->restore(...));
    $site = pkg01Site();
    $manager = pkg01StaffAt($site, ['fleet.maintenance.manage']);
    $asset = Asset::factory()->create(['site_id' => $site->id, 'category' => 'vehicle', 'status' => 'active']);
    $order = FleetWorkOrder::create([
        'asset_id' => $asset->id, 'reported_by_user_id' => $manager->id,
        'assigned_to_user_id' => $manager->id, 'title' => 'Synthetic brake hold',
        'category' => 'vehicle', 'priority' => 'high', 'status' => 'open',
    ]);
    $rules = ['allowed_kinds' => ['safety']];
    $policyId = DB::table('fleet_maintenance_policy_versions')->insertGetId([
        'site_id' => $site->id, 'asset_category' => 'vehicle', 'rule_kind' => 'hold',
        'version' => 1, 'rules_json' => json_encode($rules, JSON_THROW_ON_ERROR),
        'content_sha256' => MaintenanceFingerprint::of($rules),
        'approved_by_user_id' => $manager->id, 'approved_at' => now(), 'created_at' => now(),
    ]);
    DB::table('fleet_maintenance_policy_assignments')->insert([
        'site_id' => $site->id, 'asset_category' => 'vehicle', 'rule_kind' => 'hold',
        'policy_version_id' => $policyId, 'assigned_by_user_id' => $manager->id,
        'assigned_at' => now(), 'created_at' => now(), 'updated_at' => now(),
    ]);
    $database = DB::connection()->getDatabaseName();
    $token = Str::uuid()->toString();
    $ready = sys_get_temp_dir().DIRECTORY_SEPARATOR."pkg01-book-ready-{$token}";
    $attempt = sys_get_temp_dir().DIRECTORY_SEPARATOR."pkg01-book-attempt-{$token}";
    $barrier = sys_get_temp_dir().DIRECTORY_SEPARATOR."pkg01-book-barrier-{$token}";
    $worker = <<<'PHP'
require $argv[1].'/vendor/autoload.php';
$app = require $argv[1].'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
file_put_contents($argv[4], 'ready');
$deadline = microtime(true) + 15;
while (! is_file($argv[6])) {
    if (microtime(true) >= $deadline) throw new RuntimeException('Booking barrier timed out');
    usleep(10000);
}
file_put_contents($argv[5], 'attempt');
try {
    Illuminate\Support\Facades\DB::transaction(function () use ($argv) {
        // Create a repeatable-read snapshot before waiting for the asset lock.
        App\Models\User::query()->findOrFail((int) $argv[3]);
        App\Models\Asset::query()->whereKey((int) $argv[2])->lockForUpdate()->firstOrFail();
        app(App\Services\Fleet\MaintenanceRestrictionService::class)->assertBookable((int) $argv[2]);
        App\Models\FleetVehicleBooking::create([
            'asset_id' => (int) $argv[2], 'user_id' => (int) $argv[3],
            'purpose' => 'Synthetic overlapping booking',
            'starts_at' => now()->addDay(), 'ends_at' => now()->addDay()->addHour(),
            'status' => 'pending',
        ]);
    });
    echo 'booked';
} catch (Illuminate\Validation\ValidationException $error) {
    echo 'blocked';
}
PHP;
    $process = null;
    DB::connection()->commit(); // Publish fixtures outside RefreshDatabase's outer transaction.
    try {
        DB::connection()->beginTransaction();
        Asset::query()->whereKey($asset->id)->lockForUpdate()->firstOrFail();
        $process = new Process([PHP_BINARY, '-r', $worker, base_path(), (string) $asset->id,
            (string) $manager->id, $ready, $attempt, $barrier], base_path(), [
                'APP_ENV' => 'testing', 'DB_CONNECTION' => 'mysql', 'DB_DATABASE' => $database,
                'QUEUE_CONNECTION' => 'null',
            ]);
        $process->setTimeout(30);
        $process->start();
        pkg01WaitForFiles([$ready], 'Booking worker did not bootstrap');
        touch($barrier);
        pkg01WaitForFiles([$attempt], 'Booking worker did not reach the asset lock');
        usleep(250000);
        expect($process->isRunning())->toBeTrue();
        app(MaintenanceTransitionService::class)->execute($manager, $order->id,
            'place_restriction', 0, 'pkg01-overlap-hold', ['restriction_kind' => 'safety']);
        DB::connection()->commit();
        $process->wait();
        expect($process->isSuccessful())->toBeTrue()
            ->and(trim($process->getOutput()))->toBe('blocked')
            ->and(DB::table('fleet_vehicle_bookings')->where('asset_id', $asset->id)->count())->toBe(0)
            ->and(DB::table('fleet_maintenance_restrictions')->where('asset_id', $asset->id)->where('state', 'active')->count())->toBe(1);
    } finally {
        while (DB::connection()->transactionLevel() > 0) DB::connection()->rollBack();
        if ($process?->isRunning()) $process->stop(1);
        foreach ([$ready, $attempt, $barrier] as $path) if (is_file($path)) unlink($path);
    }
});

function pkg01WaitForFiles(array $paths, string $message): void
{
    $deadline = microtime(true) + 15;
    while (microtime(true) < $deadline) {
        if (collect($paths)->every(fn (string $path) => is_file($path))) return;
        usleep(10000);
    }
    throw new RuntimeException($message);
}


test('Designer review: work detail uses permitted projections for custody fleet and Finance readers', function () {
    $site = pkg01Site();
    $manager = pkg01StaffAt($site, ['fleet.maintenance.manage', 'finance.ap.view']);
    $recipient = pkg01StaffAt($site);
    $reader = pkg01StaffAt($site, ['fleet.viewAny']);
    $asset = Asset::factory()->create(['site_id' => $site->id, 'category' => 'vehicle']);
    $order = FleetWorkOrder::create([
        'asset_id' => $asset->id, 'reported_by_user_id' => $manager->id,
        'assigned_to_user_id' => $manager->id, 'title' => 'Private repair detail',
        'description' => 'Private source description', 'notes' => 'Private internal notes',
        'completion_notes' => 'Private completion notes', 'next_action' => 'Private next action',
        'estimated_cost' => 123.45, 'actual_cost' => 456.00,
        'category' => 'vehicle', 'priority' => 'medium', 'status' => 'completed',
    ]);
    $bill = FinBill::factory()->create(['site_id' => $site->id, 'asset_id' => $asset->id,
        'bill_number' => 'SYNTHETIC-PRIVATE-BILL', 'total_amount' => 987.65]);
    app(MaintenanceFinanceService::class)->linkBill($manager, $order->id, $bill->id);
    app(MaintenanceTransitionService::class)->execute($manager, $order->id,
        'propose_custody', 0, 'designer-private-custody', ['target_user_id' => $recipient->id]);
    $this->actingAs($recipient)->get('/fleet-assets/maintenance/work-orders/'.$order->id)
        ->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('work_order.title', 'Maintenance handover')->where('work_order.description', null)
            ->where('work_order.next_action', null)->where('work_order.reported_by', null)
            ->missing('work_order.notes')->missing('work_order.completion_notes')
            ->missing('work_order.actual_cost')->missing('work_order.estimated_cost')->missing('work_order.journal_id')
            ->where('finance', [])->where('reports', [])->where('can.details', false)->etc());
    $this->actingAs($reader)->get('/fleet-assets/maintenance/work-orders/'.$order->id)
        ->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('work_order.description', 'Private source description')->where('can.details', true)
            ->missing('work_order.notes')->missing('work_order.completion_notes')->missing('work_order.actual_cost')
            ->missing('work_order.estimated_cost')->missing('work_order.journal_id')
            ->missing('finance.0.reference')->missing('finance.0.total_amount')->missing('finance.0.journal_id')->etc());
    $this->actingAs($manager)->get('/fleet-assets/maintenance/work-orders/'.$order->id)
        ->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('finance.0.reference', 'SYNTHETIC-PRIVATE-BILL')
            ->where('finance.0.total_amount', '987.65')->missing('work_order.actual_cost')->etc());
});

test('Designer review: actual default administrator seeding withholds safety configuration authority', function () {
    $this->seed(\Database\Seeders\RbacSeeder::class);
    $admin = \App\Models\Role::query()->where('name', 'admin')->firstOrFail();
    expect($admin->permissions()->whereIn('key', ['fleet.maintenance.configure', 'fleet.maintenance.release'])->count())->toBe(0);
    $site = pkg01Site();
    $actor = pkg01StaffAt($site);
    $actor->roles()->attach($admin);
    expect($actor->fresh()->canDo('fleet.maintenance.configure'))->toBeFalse();
    $backup = pkg01StaffAt($site);
    expect(fn () => app(MaintenanceConfigurationService::class)->validate($actor, [
        'kind' => 'route', 'site_id' => $site->id, 'coordinator_user_id' => $actor->id,
        'backup_user_id' => $backup->id, 'approval_reference' => 'SYNTHETIC-NOT-A-GRANT',
    ]))->toThrow(HttpException::class);
});

test('Designer review: authored template configures and records standalone check evidence without inventing work', function () {
    Storage::fake('private');
    $site = pkg01Site();
    $actor = pkg01StaffAt($site, ['fleet.maintenance.manage', 'fleet.maintenance.configure', 'fleet.viewAny']);
    $backup = pkg01StaffAt($site);
    $foreign = pkg01StaffAt(pkg01Site(), ['fleet.maintenance.manage', 'fleet.viewAny']);
    $reader = pkg01StaffAt($site, ['fleet.viewAny']);
    $asset = Asset::factory()->create(['site_id' => $site->id, 'category' => 'vehicle']);
    $this->actingAs($actor)->post('/fleet-assets/maintenance/checklists', [
        'name' => 'Designer authored template', 'items' => [
            ['label' => 'Condition observed', 'type' => 'select', 'options' => ['pass', 'fail'], 'required' => true],
        ],
    ])->assertRedirect()->assertSessionHasNoErrors();
    $template = FleetChecklistTemplate::query()->where('name', 'Designer authored template')->firstOrFail();
    $question = $template->items[0]['id'];
    expect($question)->toBeString()->not->toBe('');
    $input = ['kind' => 'policy', 'site_id' => $site->id, 'asset_category' => 'vehicle', 'rule_kind' => 'check',
        'approval_reference' => 'SYNTHETIC-DESIGNER-TEST', 'rules' => [
            'template_id' => $template->id, 'template_sha256' => MaintenanceFingerprint::of($template->items),
            'questions' => [['id' => $question, 'pass_values' => ['pass'], 'allow_na' => false, 'evidence_required' => true]],
            'availability_impact' => 'blocking',
        ]];
    $path = tempnam(sys_get_temp_dir(), 'pkg01-config-');
    try {
        file_put_contents($path, json_encode($input, JSON_THROW_ON_ERROR));
        $this->artisan('maintenance:configure', ['--input' => $path, '--actor-id' => $actor->id])->assertExitCode(0);
    } finally { unlink($path); }
    $policyId = DB::table('fleet_maintenance_policy_assignments')->where('site_id', $site->id)->value('policy_version_id');
    $url = '/fleet-assets/maintenance/checklists/'.$template->id.'/run';
    $photo = UploadedFile::fake()->image('standalone.png');
    $payload = ['asset_id' => $asset->id, 'results' => [$question => ['result' => 'pass']],
        'rule_version_id' => $policyId, 'request_key' => 'designer-standalone-pass', 'files' => [$question => $photo]];
    $countBefore = FleetWorkOrder::count();
    $this->actingAs($actor)->post($url, $payload)->assertRedirect()->assertSessionHasNoErrors();
    $passed = FleetChecklistRun::query()->where('request_key', $payload['request_key'])->firstOrFail();
    expect($passed->outcome)->toBe('passed')->and($passed->work_order_id)->toBeNull()
        ->and(FleetWorkOrder::count())->toBe($countBefore)
        ->and($passed->responses[$question]['evidence_verified'])->toBeTrue();
    $this->get('/fleet-assets/maintenance/work-orders?asset_id='.$asset->id.'&checklist_run_id='.$passed->id.'&new=1')
        ->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('checklist_runs.0.id', $passed->id)->where('prefill_checklist_run_id', (string) $passed->id)->etc());
    $this->get('/fleet-assets/inspections/'.$passed->id)->assertOk()->assertInertia(fn (Assert $page) => $page
        ->where('inspection.asset.category', 'vehicle')->where('inspection.answer_outcomes.'.$question, 'passed')->etc());
    $storedPath = $passed->responses[$question]['evidence_file']['path'];
    Storage::disk('private')->assertExists($storedPath);
    $this->post($url, $payload)->assertRedirect()->assertSessionHasNoErrors();
    expect(FleetChecklistRun::query()->where('request_key', $payload['request_key'])->count())->toBe(1);
    $evidenceUrl = '/fleet-assets/maintenance/checklists/runs/'.$passed->id.'/evidence/'.$question;
    $this->get($evidenceUrl)->assertOk();
    $this->actingAs($foreign)->get($evidenceUrl)->assertNotFound();
    $this->actingAs($reader)->get($evidenceUrl)->assertForbidden();
    $this->actingAs($actor)->post($url, [...$payload, 'request_key' => 'designer-standalone-fail',
        'results' => [$question => ['result' => 'fail', 'notes' => 'Original synthetic observation']],
        'files' => [$question => UploadedFile::fake()->image('failed.png')],
    ])->assertRedirect()->assertSessionHasNoErrors();
    $failed = FleetChecklistRun::query()->where('request_key', 'designer-standalone-fail')->firstOrFail();
    $original = $failed->responses;
    app(MaintenanceConfigurationService::class)->apply($actor, ['kind' => 'route', 'site_id' => $site->id,
        'coordinator_user_id' => $actor->id, 'backup_user_id' => $backup->id, 'approval_reference' => 'SYNTHETIC-ROUTE']);
    $order = app(MaintenanceReportService::class)->submit($actor, ['asset_id' => $asset->id,
        'title' => 'Explicit defect assessment', 'priority' => 'medium', 'request_key' => 'designer-link-failed-check',
        'source_type' => 'fleet_checklist_run', 'source_id' => $failed->id]);
    expect($failed->fresh()->responses)->toBe($original)->and($failed->fresh()->work_order_id)->toBeNull()
        ->and($failed->outcome)->toBe('failed');
    $this->get('/fleet-assets/maintenance/work-orders/'.$order->id)->assertOk()->assertInertia(fn (Assert $page) => $page
        ->where('checks.0.id', $failed->id)->where('checks.0.outcome', 'failed')->etc());
    $this->get('/fleet-assets/inspections/'.$failed->id)->assertOk()->assertInertia(fn (Assert $page) => $page
        ->where('inspection.responses.'.$question.'.evidence.name', 'failed.png')
        ->missing('inspection.responses.'.$question.'.evidence_file')->etc());
});

test('Designer review: Outing booking waits for the asset lock and a hold leaves no partial outing', function () {
    $this->beforeApplicationDestroyed(CommittedFixtureCleanup::capture()->restore(...));
    $site = pkg01Site();
    $manager = pkg01StaffAt($site, ['fleet.manage', 'fleet.outings.manage']);
    $asset = Asset::factory()->create(['site_id' => $site->id, 'category' => 'vehicle', 'status' => 'active']);
    $order = FleetWorkOrder::create(['asset_id' => $asset->id, 'reported_by_user_id' => $manager->id,
        'title' => 'Outing guard', 'category' => 'vehicle', 'priority' => 'medium', 'status' => 'open']);
    pkg01DesignerPolicy($site, $manager, 'hold', ['allowed_kinds' => ['safety']]);
    $outingData = ['title' => 'Concurrent synthetic outing', 'destination' => 'Synthetic destination',
        'asset_id' => $asset->id, 'planned_departure' => now()->addDay()->toDateTimeString(),
        'planned_return' => now()->addDay()->addHour()->toDateTimeString()];
    $prefix = sys_get_temp_dir().DIRECTORY_SEPARATOR.'pkg01-outing-'.Str::uuid();
    $ready = $prefix.'-ready'; $attempt = $prefix.'-attempt'; $barrier = $prefix.'-barrier';
    $worker = <<<'PHP'
require $argv[1].'/vendor/autoload.php';
$app = require $argv[1].'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
file_put_contents($argv[4], 'ready');
$deadline = microtime(true) + 15;
while (! is_file($argv[6])) { if (microtime(true) > $deadline) throw new RuntimeException('Barrier timeout'); usleep(10000); }
file_put_contents($argv[5], 'attempt');
$actor = App\Models\User::query()->findOrFail((int) $argv[2]);
$request = Illuminate\Http\Request::create('/fleet-assets/outings', 'POST', json_decode($argv[3], true));
$request->setUserResolver(fn () => $actor);
try { app(App\Http\Controllers\FleetAssets\OutingController::class)->store($request); echo 'created'; }
catch (Illuminate\Validation\ValidationException $error) { echo 'blocked'; }
PHP;
    $process = null;
    DB::connection()->commit();
    try {
        DB::beginTransaction();
        Asset::query()->whereKey($asset->id)->lockForUpdate()->firstOrFail();
        $process = new Process([PHP_BINARY, '-r', $worker, base_path(), (string) $manager->id,
            json_encode($outingData), $ready, $attempt, $barrier], base_path(), [
                'APP_ENV' => 'testing', 'DB_CONNECTION' => 'mysql', 'DB_DATABASE' => DB::connection()->getDatabaseName(),
                'QUEUE_CONNECTION' => 'null',
            ]);
        $process->setTimeout(30); $process->start();
        pkg01WaitForFiles([$ready], 'Outing worker did not bootstrap'); touch($barrier);
        pkg01WaitForFiles([$attempt], 'Outing worker did not attempt the lock'); usleep(250000);
        expect($process->isRunning())->toBeTrue();
        app(MaintenanceTransitionService::class)->execute($manager, $order->id,
            'place_restriction', 0, 'designer-outing-hold', ['restriction_kind' => 'safety']);
        DB::commit(); $process->wait();
        expect($process->isSuccessful())->toBeTrue()->and(trim($process->getOutput()))->toBe('blocked');
        $this->actingAs($manager)->post('/fleet-assets/outings', $outingData)
            ->assertRedirect()->assertSessionHasErrors('asset_id');
        expect(\App\Models\FleetOuting::query()->where('asset_id', $asset->id)->count())->toBe(0)
            ->and(\App\Models\FleetVehicleBooking::query()->where('asset_id', $asset->id)->count())->toBe(0);
    } finally {
        while (DB::transactionLevel() > 0) DB::rollBack();
        if ($process?->isRunning()) $process->stop(1);
        foreach ([$ready, $attempt, $barrier] as $path) if (is_file($path)) unlink($path);
    }
});

function pkg01DesignerPolicy(Site $site, User $actor, string $kind, array $rules): int
{
    $id = DB::table('fleet_maintenance_policy_versions')->insertGetId([
        'site_id' => $site->id, 'asset_category' => 'vehicle', 'rule_kind' => $kind, 'version' => 1,
        'rules_json' => json_encode($rules), 'content_sha256' => MaintenanceFingerprint::of($rules),
        'approved_by_user_id' => $actor->id, 'approved_at' => now(), 'created_at' => now(),
    ]);
    DB::table('fleet_maintenance_policy_assignments')->insert([
        'site_id' => $site->id, 'asset_category' => 'vehicle', 'rule_kind' => $kind, 'policy_version_id' => $id,
        'assigned_by_user_id' => $actor->id, 'assigned_at' => now(), 'created_at' => now(), 'updated_at' => now(),
    ]);
    return $id;
}

test('Designer review: two completed jobs clear only their own holds while the asset remains unavailable', function () {
    Storage::fake('private');
    $site = pkg01Site();
    $manager = pkg01StaffAt($site, ['fleet.maintenance.manage']);
    $reviewer = pkg01StaffAt($site, ['fleet.maintenance.release']);
    $asset = Asset::factory()->create(['site_id' => $site->id, 'category' => 'vehicle']);
    $template = FleetChecklistTemplate::create(['name' => 'Synthetic two-job retest', 'type' => 'custom',
        'items' => [['id' => 'condition', 'label' => 'Condition', 'type' => 'select', 'options' => ['pass', 'fail']]], 'is_active' => true]);
    pkg01DesignerPolicy($site, $manager, 'hold', ['allowed_kinds' => ['safety']]);
    pkg01DesignerPolicy($site, $manager, 'repair', ['requires_service_evidence' => true]);
    pkg01DesignerPolicy($site, $manager, 'release', ['requires_custody' => false]);
    $policyId = pkg01DesignerPolicy($site, $manager, 'retest', [
        'template_id' => $template->id, 'template_sha256' => MaintenanceFingerprint::of($template->items),
        'questions' => [['id' => 'condition', 'pass_values' => ['pass'], 'allow_na' => false]],
    ]);
    DB::table('fleet_maintenance_reviewer_grants')->insert(['site_id' => $site->id, 'user_id' => $reviewer->id,
        'asset_category' => 'vehicle', 'review_kind' => 'maintenance_release', 'version' => 1, 'decision' => 'grant',
        'recorded_by_user_id' => $manager->id, 'recorded_at' => now(), 'created_at' => now()]);
    $service = app(MaintenanceTransitionService::class);
    $orders = [];
    foreach (['First repair', 'Second repair'] as $index => $title) {
        $order = FleetWorkOrder::create(['asset_id' => $asset->id, 'reported_by_user_id' => $manager->id,
            'assigned_to_user_id' => $manager->id, 'title' => $title, 'category' => 'vehicle', 'priority' => 'high', 'status' => 'open']);
        $service->execute($manager, $order->id, 'place_restriction', 0, 'designer-two-hold-'.$index, ['restriction_kind' => 'safety']);
        $service->execute($manager, $order->id, 'attest_repair', 1, 'designer-two-attest-'.$index, ['summary' => 'Synthetic repair']);
        $attestationId = DB::table('fleet_maintenance_actions')->where('work_order_id', $order->id)->where('action_type', 'attest_repair')->value('id');
        app(\App\Services\Fleet\MaintenanceAttachmentService::class)->upload($manager, $order->id, 'action', $attestationId,
            'designer-two-evidence-'.$index, UploadedFile::fake()->image('service.png'));
        $service->execute($manager, $order->id, 'complete', 2, 'designer-two-complete-'.$index);
        $orders[] = $order;
    }
    $retest = function ($order, string $key) use ($manager, $asset, $template, $policyId) {
        $ids = DB::table('fleet_maintenance_restrictions')->where('asset_id', $asset->id)->where('state', 'active')->orderBy('id')->pluck('id')->all();
        return app(MaintenanceCheckService::class)->submit($manager, ['asset_id' => $asset->id,
            'template_id' => $template->id, 'check_kind' => 'retest', 'work_order_id' => $order->id,
            'rule_version_id' => $policyId, 'covered_restriction_ids' => $ids,
            'answers' => ['condition' => ['result' => 'pass']], 'request_key' => $key]);
    };
    expect($retest($orders[0], 'designer-two-retest-1')->outcome)->toBe('passed');
    expect($retest($orders[1], 'designer-two-retest-2')->outcome)->toBe('passed');
    $service->execute($reviewer, $orders[0]->id, 'release', 3, 'designer-two-release-1');
    expect(DB::table('fleet_maintenance_restrictions')->where('work_order_id', $orders[0]->id)->value('state'))->toBe('released')
        ->and(DB::table('fleet_maintenance_restrictions')->where('work_order_id', $orders[1]->id)->value('state'))->toBe('active');
    expect(fn () => app(MaintenanceRestrictionService::class)->assertBookable($asset->id))->toThrow(ValidationException::class);
    expect(fn () => $service->execute($reviewer, $orders[1]->id, 'release', 3, 'designer-two-stale-release'))
        ->toThrow(ValidationException::class);
    expect($retest($orders[1], 'designer-two-current-retest')->outcome)->toBe('passed');
    $service->execute($reviewer, $orders[1]->id, 'release', 3, 'designer-two-release-2');
    expect(DB::table('fleet_maintenance_restrictions')->where('asset_id', $asset->id)->where('state', 'active')->count())->toBe(0);
    app(MaintenanceRestrictionService::class)->assertBookable($asset->id);
    expect(DB::table('fleet_maintenance_actions')->whereIn('work_order_id', collect($orders)->pluck('id'))
        ->where('action_type', 'release')->count())->toBe(2);
});
