<?php

use App\Domain\Hr\Jobs\DeliverHrWebhookJob;
use App\Domain\Hr\Models\HrAutomationRule;
use App\Domain\Hr\Models\HrAutomationRun;
use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Domain\Hr\Models\HrLeaveBalance;
use App\Domain\Hr\Models\HrLeaveRequest;
use App\Domain\Hr\Models\HrWebhookDelivery;
use App\Domain\Hr\Models\HrWebhookEndpoint;
use App\Domain\Hr\Services\HrWebhookDestinationPolicy;
use App\Domain\Hr\Services\HrWebhookHeaderPolicy;
use App\Domain\Hr\Services\HrWebhookService;
use App\Domain\Monitoring\Contracts\DnsResolver;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Database\Seeders\SeedHrPermissionsSeeder;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia as Assert;
use Symfony\Component\HttpKernel\Exception\HttpException;

final class HrWebhookFeatureDnsResolver implements DnsResolver
{
    /** @var array<string, list<list<string>>> */
    private array $answers = [];

    /** @var array<string, int> */
    public array $calls = [];

    /** @param list<string> $answers */
    public function answer(string $host, array $answers): void
    {
        $this->answers[$host] = [$answers];
    }

    /** @param list<list<string>> $answers */
    public function sequence(string $host, array $answers): void
    {
        $this->answers[$host] = $answers;
    }

    public function resolve(string $host): array
    {
        $this->calls[$host] = ($this->calls[$host] ?? 0) + 1;
        if (! isset($this->answers[$host])) {
            return ['93.184.216.34'];
        }

        $answer = array_shift($this->answers[$host]);

        return $answer ?? [];
    }
}

beforeEach(function () {
    $this->seed(RbacSeeder::class);
    $this->seed(SeedHrPermissionsSeeder::class);

    $this->dns = new HrWebhookFeatureDnsResolver;
    app()->instance(DnsResolver::class, $this->dns);

    $this->hr = User::factory()->create([
        'role' => 'hr',
        'approved_at' => now(),
    ]);

    $this->staff = User::factory()->create([
        'role' => 'support_worker',
        'approved_at' => now(),
    ]);

    $hrRole = Role::query()->where('name', 'hr')->first();
    if ($hrRole) {
        $this->hr->roles()->syncWithoutDetaching([$hrRole->id]);
    }

    $supportRole = Role::query()->where('name', 'support_worker')->first();
    if ($supportRole) {
        $this->staff->roles()->syncWithoutDetaching([$supportRole->id]);
    }

    $this->site = Site::factory()->create([
        'name' => 'Webhook workflow Site',
        'is_active' => true,
        'archived' => false,
    ]);

    foreach ([$this->hr, $this->staff] as $staffMember) {
        HrEmployeeProfile::factory()->create([
            'user_id' => $staffMember->id,
            'employee_number' => 'WEBHOOK-'.$staffMember->id,
            'position_role' => $staffMember->role,
            'primary_site_id' => $this->site->id,
            'secondary_site_ids' => [],
            'start_date' => today()->subYear(),
            'end_date' => null,
            'is_active' => true,
        ]);
    }
});

test('hr user can create and toggle webhook endpoints', function () {
    $this->actingAs($this->hr)
        ->post('/hr/settings/webhooks', [
            'name' => 'Ops webhooks',
            'target_url' => 'https://hooks.example.test/hr',
            'signing_secret' => 'secret-signing-key',
            'event_types' => ['leave.request.approved', 'payroll.run.exported'],
            'timeout_seconds' => 8,
            'retry_limit' => 2,
            'is_active' => true,
        ])
        ->assertSessionHas('success');

    $endpoint = HrWebhookEndpoint::query()->latest('id')->first();
    expect($endpoint)->not->toBeNull();
    expect($endpoint->is_active)->toBeTrue();
    expect($endpoint->event_types)->toContain('leave.request.approved');

    $this->actingAs($this->hr)
        ->post("/hr/settings/webhooks/{$endpoint->id}/toggle-active")
        ->assertSessionHas('success');

    $endpoint->refresh();
    expect($endpoint->is_active)->toBeFalse();

    $this->actingAs($this->hr)
        ->from('/hr/settings/webhooks')
        ->post('/hr/settings/webhooks', [
            'name' => 'Unsafe override endpoint',
            'target_url' => 'https://hooks.example.test/unsafe',
            'event_types' => ['employee.created'],
            'headers' => [
                'X-Oblivion-Webhook-Signature' => 'attempted-override',
            ],
        ])
        ->assertRedirect('/hr/settings/webhooks')
        ->assertSessionHasErrors('headers');

    expect(HrWebhookEndpoint::query()->where('name', 'Unsafe override endpoint')->exists())->toBeFalse();
});

test('global Site access never replaces the webhook action and direct IDs stay concealed', function () {
    $this->staff->permissionOverrides()->syncWithoutDetaching([
        Permission::query()->where('key', 'sites.viewAll')->firstOrFail()->id => ['allowed' => true],
    ]);

    $endpoint = HrWebhookEndpoint::query()->create([
        'name' => 'Concealed endpoint',
        'target_url' => 'https://hooks.example.test/concealed',
        'event_types' => ['employee.created'],
        'timeout_seconds' => 10,
        'retry_limit' => 3,
        'is_active' => true,
        'created_by' => $this->hr->id,
        'updated_by' => $this->hr->id,
    ]);
    $delivery = HrWebhookDelivery::query()->create([
        'endpoint_id' => $endpoint->id,
        'event_type' => 'employee.created',
        'event_uuid' => (string) str()->uuid(),
        'payload' => ['employee_profile_id' => 89],
        'status' => HrWebhookDelivery::STATUS_FAILED,
        'attempts' => 3,
        'max_attempts' => 3,
        'queued_at' => now()->subMinute(),
        'failed_at' => now(),
        'idempotency_key' => sha1('concealed-delivery'),
    ]);

    foreach ([$endpoint->id, $endpoint->id + 999_999] as $endpointId) {
        $this->actingAs($this->staff)
            ->put("/hr/settings/webhooks/{$endpointId}", ['name' => 'Not allowed'])
            ->assertForbidden();
    }
    foreach ([$delivery->id, $delivery->id + 999_999] as $deliveryId) {
        $this->actingAs($this->staff)
            ->post("/hr/settings/webhooks/deliveries/{$deliveryId}/retry")
            ->assertForbidden();
    }

    expect(fn () => app(HrWebhookService::class)->createEndpoint($this->staff, [
        'name' => 'Direct service bypass',
        'target_url' => 'https://hooks.example.test/bypass',
        'event_types' => ['employee.created'],
    ]))->toThrow(HttpException::class)
        ->and($this->dns->calls)->toBe([]);

    $this->actingAs($this->hr)
        ->put('/hr/settings/webhooks/999999999', ['name' => 'Missing'])
        ->assertNotFound();
    $this->actingAs($this->hr)
        ->post('/hr/settings/webhooks/deliveries/999999999/retry')
        ->assertNotFound();
});

test('create and update reject unsafe destinations before persistence', function () {
    $this->dns->answer('private.example.test', ['127.0.0.1']);

    $this->actingAs($this->hr)
        ->from('/hr/settings/webhooks')
        ->post('/hr/settings/webhooks', [
            'name' => 'Private endpoint',
            'target_url' => 'https://private.example.test/webhook',
            'event_types' => ['employee.created'],
        ])
        ->assertRedirect('/hr/settings/webhooks')
        ->assertSessionHasErrors('target_url');

    expect(HrWebhookEndpoint::query()->where('name', 'Private endpoint')->exists())->toBeFalse();

    $endpoint = HrWebhookEndpoint::query()->create([
        'name' => 'Existing public endpoint',
        'target_url' => 'https://hooks.example.test/original',
        'event_types' => ['employee.created'],
        'timeout_seconds' => 10,
        'retry_limit' => 3,
        'is_active' => true,
        'created_by' => $this->hr->id,
        'updated_by' => $this->hr->id,
    ]);
    $this->dns->answer('private.example.test', ['169.254.169.254']);

    $this->actingAs($this->hr)
        ->from('/hr/settings/webhooks')
        ->put("/hr/settings/webhooks/{$endpoint->id}", [
            'target_url' => 'https://private.example.test/metadata',
        ])
        ->assertRedirect('/hr/settings/webhooks')
        ->assertSessionHasErrors('target_url');

    expect($endpoint->refresh()->target_url)->toBe('https://hooks.example.test/original');

    $legacy = HrWebhookEndpoint::query()->create([
        'name' => 'Paused legacy endpoint',
        'target_url' => 'https://legacy-private.example.test/webhook',
        'event_types' => ['employee.created'],
        'timeout_seconds' => 10,
        'retry_limit' => 3,
        'is_active' => false,
        'created_by' => $this->hr->id,
        'updated_by' => $this->hr->id,
    ]);
    $this->dns->answer('legacy-private.example.test', ['::1']);

    $this->actingAs($this->hr)
        ->from('/hr/settings/webhooks')
        ->post("/hr/settings/webhooks/{$legacy->id}/toggle-active")
        ->assertRedirect('/hr/settings/webhooks')
        ->assertSessionHasErrors('target_url');

    expect($legacy->refresh()->is_active)->toBeFalse();

    $activeLegacy = HrWebhookEndpoint::query()->create([
        'name' => 'Active legacy endpoint',
        'target_url' => 'https://active-legacy-private.example.test/webhook',
        'event_types' => ['employee.created'],
        'timeout_seconds' => 10,
        'retry_limit' => 3,
        'is_active' => true,
        'created_by' => $this->hr->id,
        'updated_by' => $this->hr->id,
    ]);
    $this->dns->answer('active-legacy-private.example.test', ['10.10.10.10']);

    $this->actingAs($this->hr)
        ->from('/hr/settings/webhooks')
        ->put("/hr/settings/webhooks/{$activeLegacy->id}", [
            'name' => 'Renamed unsafe endpoint',
        ])
        ->assertRedirect('/hr/settings/webhooks')
        ->assertSessionHasErrors('target_url');

    expect($activeLegacy->refresh()->name)->toBe('Active legacy endpoint');
});

test('delivery fails closed when a configured hostname rebinds before dispatch', function () {
    config()->set('queue.default', 'sync');
    Http::fake();
    $this->dns->sequence('rebind.example.test', [
        ['93.184.216.34'],
        ['127.0.0.1'],
    ]);

    $this->actingAs($this->hr)
        ->post('/hr/settings/webhooks', [
            'name' => 'Rebinding endpoint',
            'target_url' => 'https://rebind.example.test/webhook',
            'event_types' => ['employee.created'],
            'retry_limit' => 1,
            'is_active' => true,
        ])
        ->assertSessionHas('success');

    app(HrWebhookService::class)->publishApplicationEvent('employee.created', [
        'employee_profile_id' => 90,
    ]);

    $delivery = HrWebhookDelivery::query()->latest('id')->firstOrFail();
    expect($delivery->status)->toBe(HrWebhookDelivery::STATUS_FAILED)
        ->and($delivery->error_message)->toBe('Webhook destination is not approved.')
        ->and($delivery->response_code)->toBeNull()
        ->and($this->dns->calls)->toBe(['rebind.example.test' => 2]);
    Http::assertNothingSent();
});

test('delivery reauthorizes redirects and never connects to a private hop', function () {
    config()->set('queue.default', 'sync');
    $this->dns->sequence('hooks.example.test', [
        ['93.184.216.34'],
        ['10.0.0.8'],
    ]);
    Http::fake(fn ($request) => Http::response('', 307, [
        'Location' => '/internal',
    ]));

    $endpoint = HrWebhookEndpoint::query()->create([
        'name' => 'Redirect endpoint',
        'target_url' => 'https://hooks.example.test/start',
        'event_types' => ['employee.created'],
        'timeout_seconds' => 10,
        'retry_limit' => 1,
        'is_active' => true,
        'created_by' => $this->hr->id,
        'updated_by' => $this->hr->id,
    ]);

    app(HrWebhookService::class)->publishApplicationEvent('employee.created', [
        'employee_profile_id' => 91,
    ]);

    $delivery = HrWebhookDelivery::query()->where('endpoint_id', $endpoint->id)->firstOrFail();
    expect($delivery->status)->toBe(HrWebhookDelivery::STATUS_FAILED)
        ->and($delivery->error_message)->toBe('Webhook destination is not approved.')
        ->and($delivery->response_code)->toBeNull();
    Http::assertSentCount(1);
    Http::assertSent(fn ($request) => $request->url() === 'https://hooks.example.test/start');
});

test('delivery follows only an approved same-origin preserving redirect with identical signed bytes', function () {
    config()->set('queue.default', 'sync');
    $this->dns->sequence('hooks.example.test', [
        ['93.184.216.34'],
        ['93.184.216.34'],
    ]);
    Http::fake(function ($request) {
        return $request->url() === 'https://hooks.example.test/start'
            ? Http::response('', 307, ['Location' => '/accepted'])
            : Http::response(['ok' => true], 200);
    });

    $endpoint = HrWebhookEndpoint::query()->create([
        'name' => 'Same-origin redirect endpoint',
        'target_url' => 'https://hooks.example.test/start',
        'signing_secret' => 'redirect-secret',
        'event_types' => ['employee.created'],
        'timeout_seconds' => 10,
        'retry_limit' => 1,
        'is_active' => true,
        'created_by' => $this->hr->id,
        'updated_by' => $this->hr->id,
    ]);

    app(HrWebhookService::class)->publishApplicationEvent('employee.created', [
        'employee_profile_id' => 92,
    ]);

    $delivery = HrWebhookDelivery::query()->where('endpoint_id', $endpoint->id)->firstOrFail();
    $requests = Http::recorded();
    expect($delivery->status)->toBe(HrWebhookDelivery::STATUS_SUCCESS)
        ->and($delivery->response_code)->toBe(200)
        ->and($requests)->toHaveCount(2)
        ->and((string) $requests[0][0]->body())->toBe((string) $requests[1][0]->body())
        ->and($requests[0][0]->header('X-Oblivion-Webhook-Signature'))
        ->toBe($requests[1][0]->header('X-Oblivion-Webhook-Signature'))
        ->and($this->dns->calls)->toBe(['hooks.example.test' => 2]);
});

test('approving leave request publishes webhook delivery and posts signed payload', function () {
    config()->set('queue.default', 'sync');

    Http::fake([
        'https://hooks.example.test/*' => Http::response(['ok' => true], 200),
    ]);

    HrWebhookEndpoint::query()->create([
        'name' => 'Leave approvals endpoint',
        'target_url' => 'https://hooks.example.test/hr',
        'signing_secret' => 'webhook-secret',
        'event_types' => ['leave.request.approved'],
        'headers' => ['X-Custom-Header' => 'hr'],
        'timeout_seconds' => 10,
        'retry_limit' => 3,
        'is_active' => true,
        'created_by' => $this->hr->id,
        'updated_by' => $this->hr->id,
    ]);

    HrLeaveBalance::query()->create([
        'user_id' => $this->staff->id,
        'leave_type' => 'annual',
        'year' => now()->year,
        'balance_hours' => 160,
        'accrued_hours' => 160,
        'used_hours' => 0,
        'pending_hours' => 8,
        'source' => 'system',
        'last_synced_at' => now(),
        'updated_by' => $this->hr->id,
    ]);

    $leaveRequest = HrLeaveRequest::query()->create([
        'user_id' => $this->staff->id,
        'leave_type' => 'annual',
        'starts_at' => now()->addDays(3)->toDateString(),
        'ends_at' => now()->addDays(3)->toDateString(),
        'hours_requested' => 8,
        'status' => 'pending',
        'submitted_at' => now()->subDay(),
        'approval_due_at' => now()->addHours(12),
        'escalation_level' => 1,
    ]);

    $this->actingAs($this->hr)
        ->post("/hr/leave/{$leaveRequest->id}/approve", [
            'review_notes' => 'approved for roster coverage',
        ])
        ->assertSessionHas('success');

    $delivery = HrWebhookDelivery::query()->latest('id')->first();
    expect($delivery)->not->toBeNull();
    expect($delivery->event_type)->toBe('leave.request.approved');
    expect($delivery->status)->toBe('success');
    expect($delivery->response_code)->toBe(200);

    Http::assertSent(function ($request) {
        $body = json_decode((string) $request->body(), true);

        return $request->url() === 'https://hooks.example.test/hr'
            && $request->method() === 'POST'
            && $request->hasHeader('X-Oblivion-Webhook-Signature')
            && $request->hasHeader('X-Custom-Header', 'hr')
            && is_array($body)
            && array_keys($body) === ['id', 'type', 'occurred_at', 'data']
            && ($body['type'] ?? null) === 'leave.request.approved'
            && isset($body['data']['leave_request_id']);
    });
});

test('employee rehire is an application-wide webhook and automation event visible in settings', function () {
    Queue::fake();

    $endpoint = HrWebhookEndpoint::query()->create([
        'name' => 'Application rehire endpoint',
        'target_url' => 'https://hooks.example.test/rehire',
        'event_types' => ['employee.rehired'],
        'timeout_seconds' => 10,
        'retry_limit' => 3,
        'is_active' => true,
        'created_by' => $this->hr->id,
        'updated_by' => $this->hr->id,
    ]);
    $rule = HrAutomationRule::query()->create([
        'name' => 'Application rehire audit',
        'event_type' => 'employee.rehired',
        'conditions' => [],
        'actions' => [],
        'is_active' => true,
        'stop_on_match' => false,
        'created_by' => $this->hr->id,
        'updated_by' => $this->hr->id,
    ]);

    expect(app(HrWebhookService::class)->publishApplicationEvent('employee.rehired', [
        'employee_profile_id' => 501,
        'user_id' => 502,
    ]))->toBe(1);

    $delivery = HrWebhookDelivery::query()->where('endpoint_id', $endpoint->id)->firstOrFail();
    $run = HrAutomationRun::query()->where('rule_id', $rule->id)->firstOrFail();
    expect($delivery->payload)->toEqual([
        'employee_profile_id' => 501,
        'user_id' => 502,
    ])->and($run->event_payload)->toEqual($delivery->payload);

    $this->actingAs($this->hr)
        ->get('/hr/settings/webhooks')
        ->assertInertia(fn (Assert $page) => $page
            ->where('endpoints.0.id', $endpoint->id)
            ->where('deliveries.0.id', $delivery->id)
            ->where('eventOptions', fn ($options) => collect($options)->contains('value', 'employee.rehired')));

    $this->actingAs($this->hr)
        ->get('/hr/settings/automations')
        ->assertInertia(fn (Assert $page) => $page
            ->where('rules.0.id', $rule->id)
            ->where('recentRuns.0.id', $run->id)
            ->where('eventOptions', fn ($options) => collect($options)->contains('value', 'employee.rehired')));

    Queue::assertPushed(DeliverHrWebhookJob::class, fn (DeliverHrWebhookJob $job) => $job->deliveryId === $delivery->id);
});

test('failed delivery can be retried once with explicit lineage and no duplicate queueing', function () {
    Queue::fake();

    $endpoint = HrWebhookEndpoint::query()->create([
        'name' => 'Recovery endpoint',
        'target_url' => 'https://hooks.example.test/recovery',
        'event_types' => ['employee.created'],
        'timeout_seconds' => 10,
        'retry_limit' => 3,
        'is_active' => true,
        'created_by' => $this->hr->id,
        'updated_by' => $this->hr->id,
    ]);
    $failed = HrWebhookDelivery::query()->create([
        'endpoint_id' => $endpoint->id,
        'event_type' => 'employee.created',
        'event_uuid' => (string) str()->uuid(),
        'payload' => ['employee_profile_id' => 88],
        'status' => HrWebhookDelivery::STATUS_FAILED,
        'attempts' => 3,
        'max_attempts' => 3,
        'queued_at' => now()->subMinute(),
        'failed_at' => now(),
        'idempotency_key' => sha1('failed-delivery'),
    ]);

    Http::fake();
    (new DeliverHrWebhookJob($failed->id))->handle(
        app(HrWebhookHeaderPolicy::class),
        app(HrWebhookDestinationPolicy::class),
    );
    expect($failed->refresh()->attempts)->toBe(3);
    Http::assertNothingSent();

    $this->actingAs($this->hr)
        ->post("/hr/settings/webhooks/deliveries/{$failed->id}/retry")
        ->assertSessionHas('success');

    $retry = HrWebhookDelivery::query()->where('retry_of_id', $failed->id)->firstOrFail();
    expect($retry->payload)->toBe($failed->payload)
        ->and($retry->status)->toBe(HrWebhookDelivery::STATUS_PENDING)
        ->and($retry->event_uuid)->toBe($failed->event_uuid)
        ->and($retry->idempotency_key)->toBe(sha1($failed->idempotency_key.'|manual-retry|'.$failed->id));
    Queue::assertPushed(DeliverHrWebhookJob::class, fn (DeliverHrWebhookJob $job) => $job->deliveryId === $retry->id);

    $this->actingAs($this->hr)
        ->get('/hr/settings/webhooks')
        ->assertInertia(fn (Assert $page) => $page
            ->where('deliveries.0.retry_of_id', $failed->id)
            ->where('deliveries.1.id', $failed->id)
            ->where('deliveries.1.has_retry', true));

    $this->actingAs($this->hr)
        ->from('/hr/settings/webhooks')
        ->post("/hr/settings/webhooks/deliveries/{$failed->id}/retry")
        ->assertRedirect('/hr/settings/webhooks')
        ->assertSessionHasErrors('delivery');

    expect(HrWebhookDelivery::query()->where('retry_of_id', $failed->id)->count())->toBe(1);

    Http::fake(['https://hooks.example.test/*' => Http::response(['ok' => true], 200)]);
    (new DeliverHrWebhookJob($retry->id))->handle(
        app(HrWebhookHeaderPolicy::class),
        app(HrWebhookDestinationPolicy::class),
    );

    expect($retry->refresh()->status)->toBe(HrWebhookDelivery::STATUS_SUCCESS);
    Http::assertSent(function ($request) use ($failed) {
        $body = json_decode((string) $request->body(), true);

        return $request->url() === 'https://hooks.example.test/recovery'
            && $request->hasHeader('X-Oblivion-Webhook-Delivery', (string) $failed->id)
            && $request->hasHeader('X-Oblivion-Webhook-Idempotency', $failed->idempotency_key)
            && ($body['id'] ?? null) === $failed->event_uuid
            && ($body['data'] ?? null) === $failed->payload;
    });
});

test('automatic retry keeps identical signed bytes and succeeds only within its attempt budget', function () {
    Queue::fake();
    $statuses = [500, 200];
    Http::fake(function () use (&$statuses) {
        return Http::response(['ok' => false], array_shift($statuses) ?? 500);
    });

    $endpoint = HrWebhookEndpoint::query()->create([
        'name' => 'Automatic retry endpoint',
        'target_url' => 'https://hooks.example.test/automatic-retry',
        'signing_secret' => 'automatic-retry-secret',
        'event_types' => ['employee.created'],
        'timeout_seconds' => 10,
        'retry_limit' => 2,
        'is_active' => true,
        'created_by' => $this->hr->id,
        'updated_by' => $this->hr->id,
    ]);
    app(HrWebhookService::class)->publishApplicationEvent('employee.created', [
        'employee_profile_id' => 94,
    ]);
    $delivery = HrWebhookDelivery::query()->where('endpoint_id', $endpoint->id)->firstOrFail();
    $job = new DeliverHrWebhookJob($delivery->id);

    $job->handle(app(HrWebhookHeaderPolicy::class), app(HrWebhookDestinationPolicy::class));
    expect($delivery->refresh()->status)->toBe(HrWebhookDelivery::STATUS_RETRYING)
        ->and($delivery->attempts)->toBe(1)
        ->and($delivery->failed_at)->toBeNull();

    $job->handle(app(HrWebhookHeaderPolicy::class), app(HrWebhookDestinationPolicy::class));
    $job->handle(app(HrWebhookHeaderPolicy::class), app(HrWebhookDestinationPolicy::class));
    $requests = Http::recorded();
    expect($delivery->refresh()->status)->toBe(HrWebhookDelivery::STATUS_SUCCESS)
        ->and($delivery->attempts)->toBe(2)
        ->and($requests)->toHaveCount(2)
        ->and((string) $requests[0][0]->body())->toBe((string) $requests[1][0]->body())
        ->and($requests[0][0]->header('X-Oblivion-Webhook-Signature'))
        ->toBe($requests[1][0]->header('X-Oblivion-Webhook-Signature'))
        ->and($requests[0][0]->header('X-Oblivion-Webhook-Idempotency'))
        ->toBe($requests[1][0]->header('X-Oblivion-Webhook-Idempotency'));
});

test('manual retry reuses destination policy and does not queue an unsafe endpoint', function () {
    Queue::fake();
    $this->dns->answer('private.example.test', ['127.0.0.1']);

    $endpoint = HrWebhookEndpoint::query()->create([
        'name' => 'Unsafe retry endpoint',
        'target_url' => 'https://private.example.test/webhook',
        'event_types' => ['employee.created'],
        'timeout_seconds' => 10,
        'retry_limit' => 3,
        'is_active' => true,
        'created_by' => $this->hr->id,
        'updated_by' => $this->hr->id,
    ]);
    $failed = HrWebhookDelivery::query()->create([
        'endpoint_id' => $endpoint->id,
        'event_type' => 'employee.created',
        'event_uuid' => (string) str()->uuid(),
        'payload' => ['employee_profile_id' => 93],
        'status' => HrWebhookDelivery::STATUS_FAILED,
        'attempts' => 3,
        'max_attempts' => 3,
        'queued_at' => now()->subMinute(),
        'failed_at' => now(),
        'idempotency_key' => sha1('unsafe-failed-delivery'),
    ]);

    $this->actingAs($this->hr)
        ->from('/hr/settings/webhooks')
        ->post("/hr/settings/webhooks/deliveries/{$failed->id}/retry")
        ->assertRedirect('/hr/settings/webhooks')
        ->assertSessionHasErrors('delivery');

    expect(HrWebhookDelivery::query()->where('retry_of_id', $failed->id)->exists())->toBeFalse();
    Queue::assertNothingPushed();
});

test('delivery failures retain bounded diagnostics rather than remote response content', function () {
    config()->set('queue.default', 'sync');
    Http::fake([
        'https://hooks.example.test/*' => Http::response([
            'access_token' => 'must-not-be-retained',
            'internal_detail' => 'private upstream detail',
        ], 500),
    ]);

    $endpoint = HrWebhookEndpoint::query()->create([
        'name' => 'Bounded diagnostics endpoint',
        'target_url' => 'https://hooks.example.test/failure',
        'event_types' => ['employee.created'],
        'timeout_seconds' => 10,
        'retry_limit' => 1,
        'is_active' => true,
        'created_by' => $this->hr->id,
        'updated_by' => $this->hr->id,
    ]);

    app(HrWebhookService::class)->publishApplicationEvent('employee.created', [
        'employee_profile_id' => 89,
    ]);

    $delivery = HrWebhookDelivery::query()->where('endpoint_id', $endpoint->id)->firstOrFail();
    expect($delivery->status)->toBe(HrWebhookDelivery::STATUS_FAILED)
        ->and($delivery->response_code)->toBe(500)
        ->and($delivery->response_body)->toBeNull()
        ->and($delivery->error_message)->toBe('Webhook endpoint returned HTTP 500.')
        ->and($endpoint->refresh()->last_error)->toBe('Webhook endpoint returned HTTP 500.');
});
