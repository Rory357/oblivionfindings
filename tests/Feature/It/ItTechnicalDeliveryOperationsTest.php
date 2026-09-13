<?php

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Domain\It\Services\ItTechnicalDeliveryOperationsPresenter;
use App\Domain\SecurityDevices\Enums\LinkType;
use App\Domain\SecurityDevices\Models\Device;
use App\Domain\SecurityDevices\Models\DeviceAssetLink;
use App\Domain\SecurityDevices\Models\DeviceAssignment;
use App\Domain\SecurityDevices\Models\DeviceEvent;
use App\Domain\SecurityDevices\Models\DeviceEventSignalOutbox;
use App\Models\Asset;
use App\Models\AuditLog;
use App\Models\FleetSignal;
use App\Models\FleetSignalOutbox;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;

beforeEach(function () {
    $this->seed(RbacSeeder::class);
    $this->freezeTime();
    Queue::fake();
    Notification::fake();
    Http::preventStrayRequests();
});

function technicalOperationsActor(Site $site, array $grants = ['it.view', 'it.manage', 'securityDevices.devices.view', 'assets.viewAny']): User
{
    $actor = User::factory()->create(['role' => 'hr', 'approved_at' => now()]);
    $role = Role::query()->create(['name' => 'technical-operations-'.str()->uuid(), 'label' => 'Synthetic delivery operator', 'level' => 50, 'type' => 'custom']);
    foreach ($grants as $key) {
        $permission = Permission::query()->firstOrCreate(['key' => $key], ['description' => $key, 'group' => 'it', 'module' => 'Operations']);
        $role->permissions()->syncWithoutDetaching([$permission->id]);
    }
    $actor->roles()->attach($role);
    HrEmployeeProfile::factory()->create([
        'user_id' => $actor->id, 'employee_number' => 'TECH-OPS-'.$actor->id,
        'work_email' => $actor->email, 'primary_site_id' => $site->id, 'secondary_site_ids' => [],
        'is_active' => true, 'start_date' => now()->subMonth(), 'end_date' => null,
        'created_by' => $actor->id, 'updated_by' => $actor->id,
    ]);

    return $actor->fresh();
}

function technicalOperationsDelivery(Site $site, string $source = 'device', array $attributes = []): DeviceEventSignalOutbox|FleetSignalOutbox
{
    $device = Device::factory()->create(['domain' => $source === 'device' ? 'it_infrastructure' : 'tracking']);
    if ($source === 'device') {
        DeviceAssignment::query()->create([
            'device_id' => $device->id, 'assignable_type' => DeviceAssignment::TARGET_SITE,
            'assignable_id' => $site->id, 'assignment_type' => 'permanent', 'assigned_at' => now()->subHour(),
            'assigned_by_user_id' => User::factory()->create()->id,
        ]);
        $event = DeviceEvent::withoutEvents(fn () => DeviceEvent::query()->create([
            'device_id' => $device->id, 'event_type' => 'offline', 'severity' => 'high',
            'source' => 'oblivion_monitoring', 'occurred_at' => now(),
            'payload' => ['private' => 'private-source-sentinel'],
        ]));
        $row = new DeviceEventSignalOutbox(['device_event_id' => $event->id]);
    } else {
        $asset = Asset::factory()->vehicle()->create(['site_id' => $site->id, 'home_site_id' => null, 'client_id' => null]);
        DeviceAssetLink::query()->create([
            'device_id' => $device->id, 'asset_id' => $asset->id,
            'link_type' => LinkType::InstalledIn, 'linked_at' => now()->subHour(),
        ]);
        $signal = FleetSignal::query()->create([
            'device_id' => $device->id, 'asset_id' => $asset->id, 'signal_type' => 'device.offline',
            'severity_hint' => 'medium', 'occurred_at' => now(), 'idempotency_key' => (string) str()->uuid(),
            'payload' => ['private' => 'private-trip-sentinel'],
        ]);
        $row = new FleetSignalOutbox(['fleet_signal_id' => $signal->id]);
    }
    $row->forceFill(array_replace([
        'status' => 'sent', 'it_status' => 'pending', 'it_scope' => ['site_id' => (int) $site->id],
        'it_attempts' => 0, 'it_attempt_limit' => 5, 'last_error' => 'private-exception-sentinel',
        'it_ticket_ids' => [999999], 'created_at' => now()->subHour(),
    ], $attributes))->save();

    return $row;
}

test('technical delivery facts use only permitted current sources and never project raw diagnostics', function (string $source) {
    $site = Site::factory()->create();
    $actor = technicalOperationsActor($site);
    $failed = technicalOperationsDelivery($site, $source, ['it_status' => 'failed', 'it_attempts' => 3,
        'it_outcome_code' => 'processing_failed', 'it_last_attempt_at' => now()->subMinute()]);
    technicalOperationsDelivery($site, $source, ['it_status' => 'applied', 'it_outcome_code' => 'ticket_created', 'it_completed_at' => now()->subMinutes(4)]);
    technicalOperationsDelivery($site, $source, ['it_status' => null, 'it_scope' => null]);
    technicalOperationsDelivery(Site::factory()->create(), $source, ['it_status' => 'failed']);

    $health = collect(app(ItTechnicalDeliveryOperationsPresenter::class)->operations($actor)['sources'])->firstWhere('source', $source);
    expect($health)->toMatchArray(['can_view' => true, 'available' => true, 'total' => 2, 'failures' => 1, 'pending' => 0, 'legacy_unverified' => null])
        ->and($health['oldest_pending_at'])->toBe(now()->subHour()->toIso8601String())
        ->and($health['last_success_at'])->toBe(now()->subMinutes(4)->toIso8601String())
        ->and(collect($health['rows'])->firstWhere('id', $failed->id))->toMatchArray(['attempts' => 3, 'outcome_code' => 'processing_failed', 'source_delivered' => true])
        ->and(json_encode($health))->not->toContain('private-', '999999', 'it_scope', 'payload', 'device_id', 'asset_id');
})->with(['device', 'fleet']);

test('revoked source access and unapproved actors have no delivery counts', function () {
    $site = Site::factory()->create();
    technicalOperationsDelivery($site);
    $actor = technicalOperationsActor($site, ['it.manage']);
    expect(app(ItTechnicalDeliveryOperationsPresenter::class)->operations($actor)['sources'][0])
        ->toMatchArray(['can_view' => false, 'available' => false, 'total' => null, 'rows' => []]);
    $actor = technicalOperationsActor($site);
    $actor->update(['approved_at' => null]);
    expect(app(ItTechnicalDeliveryOperationsPresenter::class)->operations($actor->fresh())['sources'][0]['can_view'])->toBeFalse();
});

test('scope drift and a lost last approved site remove counts as well as history', function () {
    $site = Site::factory()->create();
    $actor = technicalOperationsActor($site);
    $row = technicalOperationsDelivery($site);
    $other = Site::factory()->create();
    $row->event->device->assignments()->first()->update(['released_at' => now()]);
    DeviceAssignment::query()->create([
        'device_id' => $row->event->device_id, 'assignable_type' => DeviceAssignment::TARGET_SITE,
        'assignable_id' => $other->id, 'assignment_type' => 'permanent', 'assigned_at' => now(), 'assigned_by_user_id' => $actor->id,
    ]);
    expect(app(ItTechnicalDeliveryOperationsPresenter::class)->operations($actor)['sources'][0]['total'])->toBe(0);
    technicalOperationsDelivery($site);
    HrEmployeeProfile::query()->where('user_id', $actor->id)->update(['is_active' => false]);
    expect(app(ItTechnicalDeliveryOperationsPresenter::class)->operations($actor->fresh())['sources'][0])
        ->toMatchArray(['total' => 0, 'rows' => []]);
});

test('unknown outcome text is concealed and source delivery never implies IT success', function () {
    $site = Site::factory()->create();
    $actor = technicalOperationsActor($site);
    technicalOperationsDelivery($site, attributes: ['it_status' => 'unroutable', 'it_outcome_code' => 'private-diagnostic-sentinel']);
    $health = app(ItTechnicalDeliveryOperationsPresenter::class)->operations($actor)['sources'][0];
    expect($health['last_success_at'])->toBeNull()->and($health['rows'][0])
        ->toMatchArray(['state' => 'unroutable', 'outcome_code' => null, 'source_delivered' => true])
        ->and(json_encode($health))->not->toContain('private-diagnostic-sentinel');
});

test('technical history is bounded and page clamping preserves complete visible counts', function () {
    $site = Site::factory()->create();
    $actor = technicalOperationsActor($site);
    for ($i = 0; $i < 26; $i++) {
        technicalOperationsDelivery($site);
    }
    $first = app(ItTechnicalDeliveryOperationsPresenter::class)->operations($actor)['sources'][0];
    $last = app(ItTechnicalDeliveryOperationsPresenter::class)->operations($actor, ['device_delivery_page' => 999])['sources'][0];
    expect($first['rows'])->toHaveCount(25)->and($first['total'])->toBe(26)
        ->and($first['next_url'])->toBe('/it/setup?device_delivery_page=2&tab=operations#it-device-delivery-history')
        ->and($last['rows'])->toHaveCount(1)->and($last['page'])->toBe(2)->and($last['next_url'])->toBeNull();
});

test('an unapplied delivery schema reports unavailable instead of healthy zero metrics', function () {
    $actor = technicalOperationsActor(Site::factory()->create());
    Schema::partialMock()->shouldReceive('hasColumns')->andReturn(false);
    foreach (app(ItTechnicalDeliveryOperationsPresenter::class)->operations($actor)['sources'] as $source) {
        expect($source)->toMatchArray(['can_view' => true, 'available' => false, 'total' => null, 'pending' => null, 'failures' => null, 'rows' => []]);
    }
});

test('setup operations publishes current actor-bound technical delivery facts and validates pages', function () {
    config(['inertia.ssr.enabled' => false]);
    $site = Site::factory()->create();
    $actor = technicalOperationsActor($site);
    technicalOperationsDelivery($site);
    $this->actingAs($actor)->get('/it/setup?tab=operations')->assertOk()->assertInertia(fn ($page) => $page
        ->where('operationsAudit.technical_delivery_health.viewer_user_id', $actor->id)
        ->where('operationsAudit.technical_delivery_health.sources.0.total', 1)
        ->where('operationsAudit.technical_delivery_health.sources.0.rows.0.state', 'pending'));
    $this->getJson('/it/setup?tab=operations&device_delivery_page=-1')->assertUnprocessable()->assertJsonValidationErrors('device_delivery_page');
});

test('delivery search uses only public classifications within the same source scope', function (string $source) {
    $site = Site::factory()->create();
    $actor = technicalOperationsActor($site);
    $failed = technicalOperationsDelivery($site, $source, ['it_status' => 'failed', 'it_outcome_code' => 'processing_failed']);
    technicalOperationsDelivery($site, $source, ['it_status' => 'applied', 'it_outcome_code' => 'ticket_created']);
    technicalOperationsDelivery($site, $source, ['it_status' => 'unroutable', 'it_outcome_code' => 'private-outcome-sentinel']);
    technicalOperationsDelivery(Site::factory()->create(), $source, ['it_status' => 'failed']);
    $presenter = app(ItTechnicalDeliveryOperationsPresenter::class);
    $search = fn (string $query) => collect($presenter->operations($actor, ['q' => $query])['sources'])->firstWhere('source', $source);
    expect($search('Delivery failed'))->toMatchArray(['total' => 3, 'failures' => 2, 'history_total' => 1, 'search_query' => 'Delivery failed'])
        ->and($search('Delivery failed')['rows'][0]['id'])->toBe($failed->id)
        ->and($search('private-outcome-sentinel')['history_total'])->toBe(0)
        ->and($search('private-exception-sentinel')['history_total'])->toBe(0)
        ->and($search('No classified outcome recorded')['history_total'])->toBe(1)
        ->and($search((string) $failed->id)['rows'][0]['id'])->toBe($failed->id);
})->with(['device', 'fleet']);

test('filtered delivery pagination preserves encoded search and its canonical history anchor', function () {
    $site = Site::factory()->create();
    $actor = technicalOperationsActor($site);
    for ($i = 0; $i < 26; $i++) {
        technicalOperationsDelivery($site, attributes: ['it_status' => 'failed']);
    }
    technicalOperationsDelivery($site, attributes: ['it_status' => 'applied']);
    $health = app(ItTechnicalDeliveryOperationsPresenter::class)->operations($actor, ['q' => 'Delivery failed'])['sources'][0];
    expect($health)->toMatchArray(['total' => 27, 'history_total' => 26, 'last_page' => 2])
        ->and($health['next_url'])->toBe('/it/setup?device_delivery_page=2&q=Delivery%20failed&tab=operations#it-device-delivery-history')
        ->and($health['links'][2]['url'])->toBe($health['next_url']);
});

test('a reviewed HTTP retry grants one bounded attempt and rejects the same stale request', function (string $source) {
    $site = Site::factory()->create();
    $actor = technicalOperationsActor($site);
    $row = technicalOperationsDelivery($site, $source, ['it_status' => 'dead_letter', 'it_attempts' => 20, 'it_attempt_limit' => 20]);
    $url = '/it/setup/technical-deliveries/'.$source.'/'.$row->id;
    $review = $this->actingAs($actor)->getJson($url.'?viewer_user_id='.$actor->id)->assertOk()->assertHeader('Cache-Control', 'no-store, private')->json('data');
    expect($review)->toMatchArray(['source' => $source, 'id' => $row->id, 'viewer_user_id' => $actor->id, 'can_retry' => true]);
    $body = ['viewer_user_id' => $actor->id, 'version' => $review['version']];
    $this->postJson($url.'/retry', $body)->assertOk()->assertJsonPath('data.retry_requested', true)->assertJsonPath('data.state', 'pending');
    $this->postJson($url.'/retry', $body)->assertConflict()->assertJsonPath('code', 'delivery_changed');
    expect($row->fresh())->toMatchArray(['it_status' => 'pending', 'it_attempts' => 20, 'it_attempt_limit' => 21]);
    expect(AuditLog::query()->where('action', 'it.'.($source === 'device' ? 'monitoring' : 'fleet').'.delivery_retry_requested')->count())->toBe(1);
    $this->getJson($url.'?viewer_user_id='.$actor->id)->assertOk()->assertJsonPath('data.can_retry', false)->assertJsonPath('data.attempt_limit', 21);
})->with(['device', 'fleet']);

test('an uncertain queue dispatch retains the retry allowance without claiming completed work', function () {
    $site = Site::factory()->create();
    $actor = technicalOperationsActor($site);
    $row = technicalOperationsDelivery($site, attributes: ['it_status' => 'failed', 'it_attempts' => 3]);
    $url = '/it/setup/technical-deliveries/device/'.$row->id;
    $review = $this->actingAs($actor)->getJson($url.'?viewer_user_id='.$actor->id)->assertOk()->json('data');
    Bus::shouldReceive('dispatch')->once()->andThrow(new RuntimeException('private-queue-failure'));
    $this->postJson($url.'/retry', ['viewer_user_id' => $actor->id, 'version' => $review['version']])
        ->assertOk()->assertJsonPath('data.retry_requested', true)->assertJsonPath('data.state', 'pending');
    expect($row->fresh()->it_attempts)->toBe(3)->and($row->fresh()->it_attempt_limit)->toBe(4);
});

test('a changed delivery outcome invalidates the reviewed retry even when it fails again', function () {
    $site = Site::factory()->create();
    $actor = technicalOperationsActor($site);
    $row = technicalOperationsDelivery($site, attributes: ['it_status' => 'failed', 'it_attempts' => 3]);
    $url = '/it/setup/technical-deliveries/device/'.$row->id;
    $review = $this->actingAs($actor)->getJson($url.'?viewer_user_id='.$actor->id)->assertOk()->json('data');
    $row->update(['it_attempts' => 4]);
    $this->postJson($url.'/retry', ['viewer_user_id' => $actor->id, 'version' => $review['version']])->assertConflict();
    expect($row->fresh()->it_attempt_limit)->toBe(5);
    Queue::assertNothingPushed();
});

test('forged actor and source identifiers and lost site access deny retry before any allowance', function () {
    $site = Site::factory()->create();
    $actor = technicalOperationsActor($site);
    $row = technicalOperationsDelivery($site, attributes: ['it_status' => 'failed']);
    $url = '/it/setup/technical-deliveries/device/'.$row->id;
    $review = $this->actingAs($actor)->getJson($url.'?viewer_user_id='.$actor->id)->assertOk()->json('data');
    $this->postJson($url.'/retry', ['viewer_user_id' => $actor->id + 999, 'version' => $review['version']])->assertForbidden();
    $hidden = technicalOperationsDelivery(Site::factory()->create(), attributes: ['it_status' => 'failed']);
    $this->getJson('/it/setup/technical-deliveries/device/'.$hidden->id.'?viewer_user_id='.$actor->id)->assertNotFound();
    HrEmployeeProfile::query()->where('user_id', $actor->id)->update(['is_active' => false]);
    $this->postJson($url.'/retry', ['viewer_user_id' => $actor->id, 'version' => $review['version']])->assertNotFound();
    expect($row->fresh()->it_status)->toBe('failed')->and($row->fresh()->it_attempt_limit)->toBe(5);
    Queue::assertNothingPushed();
});

test('audit failure rolls back the allowance and hides exception details even in debug mode', function () {
    config(['app.debug' => true]);
    $site = Site::factory()->create();
    $actor = technicalOperationsActor($site);
    $row = technicalOperationsDelivery($site, attributes: ['it_status' => 'dead_letter', 'it_attempts' => 5]);
    $url = '/it/setup/technical-deliveries/device/'.$row->id;
    $review = $this->actingAs($actor)->getJson($url.'?viewer_user_id='.$actor->id)->assertOk()->json('data');
    AuditLog::creating(function (AuditLog $audit): void {
        if ($audit->action === 'it.monitoring.delivery_retry_requested') {
            throw new RuntimeException('private-audit-exception-sentinel');
        }
    });
    $response = $this->postJson($url.'/retry', ['viewer_user_id' => $actor->id, 'version' => $review['version']])
        ->assertStatus(500)->assertJsonPath('code', 'delivery_outcome_unknown')->assertHeader('Cache-Control', 'no-store, private');
    expect($response->getContent())->not->toContain('private-audit-exception-sentinel', 'trace', 'exception')
        ->and($row->fresh()->it_status)->toBe('dead_letter')->and($row->fresh()->it_attempt_limit)->toBe(5);
    Queue::assertNothingPushed();
});
