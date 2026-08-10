<?php

use App\Domain\Hr\Jobs\DeliverHrWebhookJob;
use App\Domain\Hr\Models\HrAutomationRule;
use App\Domain\Hr\Models\HrAutomationRun;
use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Domain\Hr\Models\HrLeaveBalance;
use App\Domain\Hr\Models\HrLeaveRequest;
use App\Domain\Hr\Models\HrWebhookDelivery;
use App\Domain\Hr\Models\HrWebhookEndpoint;
use App\Domain\Hr\Services\HrWebhookService;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Database\Seeders\SeedHrPermissionsSeeder;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->seed(RbacSeeder::class);
    $this->seed(SeedHrPermissionsSeeder::class);

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
        return $request->url() === 'https://hooks.example.test/hr'
            && $request->method() === 'POST'
            && $request->hasHeader('X-Oblivion-Webhook-Signature')
            && $request->hasHeader('X-Custom-Header', 'hr')
            && array_keys($request->data()) === ['id', 'type', 'occurred_at', 'data']
            && ($request['type'] ?? null) === 'leave.request.approved'
            && isset($request['data']['leave_request_id']);
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

    $this->actingAs($this->hr)
        ->post("/hr/settings/webhooks/deliveries/{$failed->id}/retry")
        ->assertSessionHas('success');

    $retry = HrWebhookDelivery::query()->where('retry_of_id', $failed->id)->firstOrFail();
    expect($retry->payload)->toBe($failed->payload)
        ->and($retry->status)->toBe(HrWebhookDelivery::STATUS_PENDING)
        ->and($retry->event_uuid)->not->toBe($failed->event_uuid);
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
