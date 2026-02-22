<?php

use App\Domain\Hr\Models\HrAutomationRule;
use App\Domain\Hr\Models\HrAutomationRun;
use App\Domain\Hr\Models\HrReportExport;
use App\Domain\Hr\Notifications\HrScheduledReportReadyNotification;
use App\Domain\Hr\Services\HrWebhookService;
use App\Models\Role;
use App\Models\User;
use App\Notifications\AppEventNotification;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake('private');
    Notification::fake();

    $this->seed(\Database\Seeders\RbacSeeder::class);

    $this->hr = User::factory()->create([
        'role' => 'hr',
        'approved_at' => now(),
    ]);

    $this->recipient = User::factory()->create([
        'role' => 'support_worker',
        'approved_at' => now(),
    ]);

    $hrRole = Role::query()->where('name', 'hr')->first();
    if ($hrRole) {
        $this->hr->roles()->syncWithoutDetaching([$hrRole->id]);
    }

    $supportRole = Role::query()->where('name', 'support_worker')->first();
    if ($supportRole) {
        $this->recipient->roles()->syncWithoutDetaching([$supportRole->id]);
    }
});

test('hr user can create and toggle automation rule', function () {
    $this->actingAs($this->hr)
        ->post('/hr/reports/automations', [
            'name' => 'Leave approvals alert',
            'event_type' => 'leave.request.approved',
            'condition_field' => 'status',
            'condition_value' => 'approved',
            'action_type' => 'notify_users',
            'action_title' => 'Leave approved',
            'action_body' => 'A leave request has been approved.',
            'action_url' => '/hr/leave',
            'recipient_user_ids' => [$this->recipient->id],
            'is_active' => true,
            'stop_on_match' => true,
        ])
        ->assertSessionHas('success');

    $rule = HrAutomationRule::query()->latest('id')->first();
    expect($rule)->not->toBeNull();
    expect($rule->event_type)->toBe('leave.request.approved');
    expect($rule->is_active)->toBeTrue();
    expect($rule->stop_on_match)->toBeTrue();
    expect(data_get($rule->actions, '0.type'))->toBe('notify_users');

    $this->actingAs($this->hr)
        ->post("/hr/reports/automations/{$rule->id}/toggle-active")
        ->assertSessionHas('success');

    $rule->refresh();
    expect($rule->is_active)->toBeFalse();
});

test('automation sends user notification when conditions match', function () {
    $rule = HrAutomationRule::query()->create([
        'tenant_id' => null,
        'name' => 'Notify on approved leave',
        'event_type' => 'leave.request.approved',
        'conditions' => [
            'equals' => [
                'status' => 'approved',
            ],
        ],
        'actions' => [[
            'type' => 'notify_users',
            'title' => 'Leave approved',
            'body' => 'Automation fired.',
            'url' => '/hr/leave',
            'user_ids' => [$this->recipient->id],
        ]],
        'is_active' => true,
        'stop_on_match' => false,
        'created_by' => $this->hr->id,
        'updated_by' => $this->hr->id,
    ]);

    app(HrWebhookService::class)->publish(null, 'leave.request.approved', [
        'status' => 'approved',
        'leave_request_id' => 55,
    ]);

    $run = HrAutomationRun::query()
        ->where('rule_id', $rule->id)
        ->latest('id')
        ->first();

    expect($run)->not->toBeNull();
    expect($run->status)->toBe('success');

    Notification::assertSentTo(
        $this->recipient,
        AppEventNotification::class,
        fn (AppEventNotification $notification) => ($notification->payload['event_type'] ?? null) === 'leave.request.approved'
            && ($notification->payload['kind'] ?? null) === 'hr_automation'
    );
});

test('automation queues report export and notifies recipients', function () {
    $rule = HrAutomationRule::query()->create([
        'tenant_id' => null,
        'name' => 'Export payroll snapshot',
        'event_type' => 'payroll.run.exported',
        'conditions' => [],
        'actions' => [[
            'type' => 'queue_report_export',
            'report_type' => 'headcount',
            'filters' => [
                'date_from' => now()->subMonth()->toDateString(),
                'date_to' => now()->toDateString(),
            ],
            'recipient_user_ids' => [$this->recipient->id],
        ]],
        'is_active' => true,
        'stop_on_match' => false,
        'created_by' => $this->hr->id,
        'updated_by' => $this->hr->id,
    ]);

    app(HrWebhookService::class)->publish(null, 'payroll.run.exported', [
        'run_id' => 201,
    ]);

    $run = HrAutomationRun::query()
        ->where('rule_id', $rule->id)
        ->latest('id')
        ->first();

    expect($run)->not->toBeNull();
    expect($run->status)->toBe('success');

    $export = HrReportExport::query()->latest('id')->first();
    expect($export)->not->toBeNull();
    expect($export->report_type)->toBe('headcount');
    expect(Storage::disk('private')->exists($export->storage_path))->toBeTrue();

    Notification::assertSentTo($this->recipient, HrScheduledReportReadyNotification::class);
});

test('automation records skipped run when conditions do not match', function () {
    $rule = HrAutomationRule::query()->create([
        'tenant_id' => null,
        'name' => 'Skip non-approved leave',
        'event_type' => 'leave.request.approved',
        'conditions' => [
            'equals' => [
                'status' => 'approved',
            ],
        ],
        'actions' => [[
            'type' => 'notify_users',
            'user_ids' => [$this->recipient->id],
        ]],
        'is_active' => true,
        'stop_on_match' => false,
        'created_by' => $this->hr->id,
        'updated_by' => $this->hr->id,
    ]);

    app(HrWebhookService::class)->publish(null, 'leave.request.approved', [
        'status' => 'declined',
        'leave_request_id' => 78,
    ]);

    $run = HrAutomationRun::query()
        ->where('rule_id', $rule->id)
        ->latest('id')
        ->first();

    expect($run)->not->toBeNull();
    expect($run->status)->toBe('skipped');

    Notification::assertNothingSent();
});

test('automation sends microsoft teams webhook message', function () {
    Http::fake([
        'https://example.test/teams-hook' => Http::response(['ok' => true], 200),
    ]);

    $rule = HrAutomationRule::query()->create([
        'tenant_id' => null,
        'name' => 'Notify Teams on leave approval',
        'event_type' => 'leave.request.approved',
        'conditions' => [],
        'actions' => [[
            'type' => 'notify_microsoft_teams',
            'title' => 'Leave approved',
            'body' => 'Automation fired.',
            'webhook_url' => 'https://example.test/teams-hook',
            'include_payload' => true,
            'timeout_seconds' => 10,
        ]],
        'is_active' => true,
        'stop_on_match' => false,
        'created_by' => $this->hr->id,
        'updated_by' => $this->hr->id,
    ]);

    app(HrWebhookService::class)->publish(null, 'leave.request.approved', [
        'status' => 'approved',
        'leave_request_id' => 321,
    ]);

    Http::assertSent(function ($request) {
        $data = $request->data();

        return $request->url() === 'https://example.test/teams-hook'
            && ($data['title'] ?? null) === 'Leave approved'
            && str_contains((string) ($data['text'] ?? ''), 'Automation fired.')
            && ($data['event_type'] ?? null) === 'leave.request.approved';
    });

    $run = HrAutomationRun::query()
        ->where('rule_id', $rule->id)
        ->latest('id')
        ->first();

    expect($run)->not->toBeNull();
    expect($run->status)->toBe('success');
});

test('automation evaluates advanced any-logic condition rules', function () {
    $rule = HrAutomationRule::query()->create([
        'tenant_id' => null,
        'name' => 'Advanced any-rule matcher',
        'event_type' => 'leave.request.approved',
        'conditions' => [
            'logic' => 'any',
            'rules' => [
                ['field' => 'status', 'operator' => 'equals', 'value' => 'approved'],
                ['field' => 'leave_type', 'operator' => 'in', 'value' => ['annual', 'sick']],
            ],
        ],
        'actions' => [[
            'type' => 'notify_users',
            'title' => 'Advanced rule matched',
            'body' => 'Condition matched on leave_type.',
            'user_ids' => [$this->recipient->id],
        ]],
        'is_active' => true,
        'stop_on_match' => false,
        'created_by' => $this->hr->id,
        'updated_by' => $this->hr->id,
    ]);

    app(HrWebhookService::class)->publish(null, 'leave.request.approved', [
        'status' => 'declined',
        'leave_type' => 'annual',
    ]);

    $run = HrAutomationRun::query()
        ->where('rule_id', $rule->id)
        ->latest('id')
        ->first();

    expect($run)->not->toBeNull();
    expect($run->status)->toBe('success');
    Notification::assertSentTo($this->recipient, AppEventNotification::class);
});

test('automation rule can execute multiple actions in one run', function () {
    $rule = HrAutomationRule::query()->create([
        'tenant_id' => null,
        'name' => 'Multi action rule',
        'event_type' => 'payroll.run.exported',
        'conditions' => [],
        'actions' => [
            [
                'type' => 'notify_users',
                'title' => 'Payroll exported',
                'body' => 'User notification action fired.',
                'user_ids' => [$this->recipient->id],
            ],
            [
                'type' => 'queue_report_export',
                'report_type' => 'headcount',
                'filters' => [
                    'date_from' => now()->subMonth()->toDateString(),
                    'date_to' => now()->toDateString(),
                ],
                'recipient_user_ids' => [$this->recipient->id],
            ],
        ],
        'is_active' => true,
        'stop_on_match' => false,
        'created_by' => $this->hr->id,
        'updated_by' => $this->hr->id,
    ]);

    app(HrWebhookService::class)->publish(null, 'payroll.run.exported', ['run_id' => 999]);

    $run = HrAutomationRun::query()
        ->where('rule_id', $rule->id)
        ->latest('id')
        ->first();

    expect($run)->not->toBeNull();
    expect($run->status)->toBe('success');
    Notification::assertSentTo($this->recipient, AppEventNotification::class);
    Notification::assertSentTo($this->recipient, HrScheduledReportReadyNotification::class);
    expect(HrReportExport::query()->where('report_type', 'headcount')->exists())->toBeTrue();
});

test('controller accepts advanced JSON payload for conditions and actions', function () {
    $this->actingAs($this->hr)
        ->post('/hr/reports/automations', [
            'name' => 'Advanced JSON payload rule',
            'event_type' => 'leave.request.approved',
            'condition_logic' => 'all',
            'condition_rules_json' => json_encode([
                ['field' => 'status', 'operator' => 'equals', 'value' => 'approved'],
                ['field' => 'hours_requested', 'operator' => 'gt', 'value' => '4'],
            ], JSON_THROW_ON_ERROR),
            'actions_json' => json_encode([
                [
                    'type' => 'notify_users',
                    'title' => 'Advanced JSON',
                    'recipient_user_ids' => [$this->recipient->id],
                ],
                [
                    'type' => 'notify_microsoft_teams',
                    'title' => 'Advanced JSON Teams',
                    'webhook_url' => 'https://example.test/hook',
                    'timeout_seconds' => 10,
                ],
            ], JSON_THROW_ON_ERROR),
            'action_type' => 'notify_users',
            'is_active' => true,
            'stop_on_match' => false,
        ])
        ->assertSessionHas('success');

    $rule = HrAutomationRule::query()->latest('id')->first();
    expect($rule)->not->toBeNull();
    expect((array) $rule->conditions)->toHaveKey('rules');
    expect(collect($rule->actions ?? [])->count())->toBe(2);
});
