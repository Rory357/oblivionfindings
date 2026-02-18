<?php

use App\Domain\Hr\Models\HrLeaveBalance;
use App\Domain\Hr\Models\HrLeaveRequest;
use App\Domain\Hr\Models\HrWebhookDelivery;
use App\Domain\Hr\Models\HrWebhookEndpoint;
use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    $this->seed(\Database\Seeders\RbacSeeder::class);

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
});

test('hr user can create and toggle webhook endpoints', function () {
    $this->actingAs($this->hr)
        ->post('/hr/reports/webhooks', [
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
        ->post("/hr/reports/webhooks/{$endpoint->id}/toggle-active")
        ->assertSessionHas('success');

    $endpoint->refresh();
    expect($endpoint->is_active)->toBeFalse();
});

test('approving leave request publishes webhook delivery and posts signed payload', function () {
    config()->set('queue.default', 'sync');

    Http::fake([
        'https://hooks.example.test/*' => Http::response(['ok' => true], 200),
    ]);

    HrWebhookEndpoint::query()->create([
        'tenant_id' => 1,
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
        'tenant_id' => 1,
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
        'tenant_id' => 1,
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
            && ($request['type'] ?? null) === 'leave.request.approved'
            && isset($request['data']['leave_request_id']);
    });
});
