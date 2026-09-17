<?php

use App\Domain\Hr\Models\HrOnboardingChecklist;
use App\Domain\Hr\Services\OnboardingService;
use App\Domain\It\Services\ItCatalogSubmissionService;
use App\Domain\It\Services\ItProvisioningReadinessService;
use App\Domain\It\Services\ItProvisioningRequestLifecycleService;
use App\Domain\It\Services\ItProvisioningTemplatePublicationService;
use App\Domain\It\Services\ItProvisioningTemplateService;
use App\Domain\It\Services\ItProvisioningTrackingService;
use App\Domain\It\Services\ItProvisioningWorkflowService;
use App\Models\ItCatalogItem;
use App\Models\ItEmailDelivery;
use App\Models\ItProvisioningRequest;
use App\Models\ItProvisioningTemplate;
use App\Models\ItProvisioningWorkflow;
use App\Models\ItQueue;
use App\Models\ItTeam;
use App\Models\ItTicketEvent;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

beforeEach(function () {
    $this->seed(RbacSeeder::class);
    $this->site = Site::factory()->create();
    foreach (['manager', 'assignee', 'approver', 'cover', 'employee'] as $name) {
        $role = $name === 'employee' ? 'support_worker' : 'hr';
        $actor = User::factory()->create(['role' => $role, 'approved_at' => now()]);
        $actor->roles()->syncWithoutDetaching(Role::where('name', $role)->pluck('id'));
        ensureCanonicalHrStaffProfile($actor, $this->site);
        $this->{$name} = $actor;
    }
    $this->profile = $this->employee->hrEmployeeProfile()->firstOrFail();
});

function w15rTemplate($test, string $lifecycle = 'joiner'): ItProvisioningTemplate
{
    $template = app(ItProvisioningTemplateService::class)->create($test->manager, [
        'name' => 'Routed reviewed '.$lifecycle, 'description' => 'Synthetic original instructions',
        'lifecycle_type' => $lifecycle, 'site_id' => $test->site->id, 'position_role' => null,
        'employment_type' => null, 'selection_priority' => 0, 'is_active' => true,
        'tasks' => [
            ['task_key' => 'account', 'title' => 'Private account work', 'description' => 'Private original task instructions',
                'category' => 'account', 'action' => $lifecycle === 'leaver' ? 'revoke' : 'grant', 'request_type' => 'account',
                'stage' => 1, 'sort_order' => 1, 'dependency_task_keys' => [], 'trigger_fields' => [],
                'approval_required' => true, 'evidence_required' => true, 'due_offset_days' => 0, 'fulfiller_fields' => []],
            ['task_key' => 'verify', 'title' => 'Private second task', 'description' => 'Private second instructions',
                'category' => 'other', 'action' => 'change', 'request_type' => 'other',
                'stage' => 2, 'sort_order' => 2, 'dependency_task_keys' => ['account'], 'trigger_fields' => [],
                'approval_required' => false, 'evidence_required' => true, 'due_offset_days' => 1, 'fulfiller_fields' => []],
        ],
    ]);

    return app(ItProvisioningTemplatePublicationService::class)->publish($template, $test->manager, true, [
        'expected_version' => $template->lock_version, 'expected_published_version_id' => null,
        'reason' => 'Synthetic reviewer accepts this complete instruction graph.',
    ]);
}

function w15rLaunch($test, ?ItProvisioningTemplate $template = null): ItProvisioningWorkflow
{
    $template ??= w15rTemplate($test);
    $response = $test->actingAs($test->manager)->postJson('/it/provisioning/commands/launch/'.$test->profile->id.'/launch', [
        'actor_user_id' => $test->manager->id, 'request_uuid' => (string) Str::uuid(), 'expected_version' => 1,
        'template_version_id' => $template->published_version_id, 'lifecycle_type' => $template->lifecycle_type,
        'effective_date' => today()->toDateString(), 'owner_user_id' => $test->assignee->id, 'cover_user_id' => $test->cover->id,
        'reason' => 'Synthetic reviewed staff workflow.',
    ])->assertOk()->assertJsonPath('status', 'committed');

    return ItProvisioningWorkflow::findOrFail($response->json('data.result_id'));
}

function w15rCommand($test, string $kind, int $target, string $operation, array $data = [], ?User $actor = null)
{
    $actor ??= $test->manager;
    $version = $kind === 'request' ? ItProvisioningRequest::findOrFail($target)->lock_version : ItProvisioningWorkflow::findOrFail($target)->lock_version;

    return $test->actingAs($actor)->postJson('/it/provisioning/commands/'.$kind.'/'.$target.'/'.$operation, [
        'actor_user_id' => $actor->id, 'request_uuid' => (string) Str::uuid(), 'expected_version' => $version, ...$data,
    ]);
}

function w15rFallbackQueue($test): ItQueue
{
    $team = ItTeam::factory()->create(['manager_user_id' => $test->approver->id]);
    $team->members()->attach($test->cover->id, ['role' => 'member']);

    return ItQueue::factory()->create(['team_id' => $team->id, 'is_active' => true, 'filter_rules' => [
        'is_default' => true, 'site_ids' => [$test->site->id], 'cover_user_id' => $test->cover->id,
    ]]);
}

test('a catalogue submission routes approval to the configured approvers, notifies them, and the requester hears the decision', function () {
    $template = w15rTemplate($this);
    $item = ItCatalogItem::factory()->provisioning()->create([
        'name' => 'Synthetic routed access request', 'site_scope' => [$this->site->id], 'requires_approval' => true,
        'approver_user_id' => $this->approver->id, 'cover_approver_user_id' => $this->cover->id, 'approval_window_days' => 3,
        'provisioning_template_version_id' => $template->published_version_id,
    ]);
    $result = app(ItCatalogSubmissionService::class)->submit($item, $this->employee, [
        'idempotency_key' => (string) Str::uuid(), 'schema_version' => 1, 'site_id' => $this->site->id, 'values' => [],
    ]);
    $tasks = $result['submission']->result->workflow->requests()->orderBy('stage')->get();
    expect($tasks)->toHaveCount(2);
    foreach ($tasks as $task) {
        expect($task->approval_status)->toBe('pending')
            ->and($task->approval_requested_at)->not->toBeNull()
            ->and((int) $task->primary_approver_user_id)->toBe($this->approver->id)
            ->and((int) $task->cover_approver_user_id)->toBe($this->cover->id)
            ->and($task->approval_expires_at->isAfter(now()->addDays(2)))->toBeTrue()
            ->and($task->approval_expires_at->isBefore(now()->addDays(4)))->toBeTrue()
            ->and(ItTicketEvent::query()->where('subject_id', $task->id)->where('type', 'approval_requested')
                ->value('payload')['routing_basis'] ?? null)->toBe('catalogue_default');
    }
    $deliveries = ItEmailDelivery::query()->where('notification_type', 'it_provisioning_update')->where('audience', 'approver')->get();
    // Approvers hold work access, so their subject names the actual task; requesters only ever see the public title.
    expect($deliveries->pluck('recipient_user_id')->unique()->sort()->values()->all())
        ->toBe(collect([$this->approver->id, $this->cover->id])->sort()->values()->all())
        ->and(json_encode($deliveries->pluck('subject')))->toContain('Approval needed');
    // The readiness verdict now names the responsible approver instead of asking for one.
    $verdict = app(ItProvisioningReadinessService::class)->forRequest($tasks->first(), $this->manager);
    expect($verdict['approver']['id'])->toBe($this->approver->id)->and($verdict['actions'])->not->toContain('request_approval');

    w15rCommand($this, 'request', $tasks->first()->id, 'approve', ['reason' => 'Reviewed.'], $this->approver)->assertOk();
    $requesterDelivery = ItEmailDelivery::query()->where('notification_type', 'it_provisioning_update')
        ->where('recipient_user_id', $this->employee->id)->where('audience', 'requester')
        ->where('notification_context->event', 'approved')->first();
    expect($tasks->first()->fresh()->approval_status)->toBe('approved')
        ->and($requesterDelivery)->not->toBeNull()
        ->and($requesterDelivery->subject)->toContain('Synthetic routed access request')
        ->and($requesterDelivery->subject)->not->toContain('Private account work');
    // A workflow-level decision covers every remaining task the approver is responsible for.
    w15rCommand($this, 'workflow', $tasks->first()->provisioning_workflow_id, 'approve', ['reason' => 'Reviewed the rest.'], $this->approver)->assertOk();
    expect($tasks->last()->fresh()->approval_status)->toBe('approved');
});

test('routing falls back to the service desk queue and records a gap when nobody eligible exists', function () {
    $template = w15rTemplate($this);
    $item = ItCatalogItem::factory()->provisioning()->create([
        'name' => 'Synthetic fallback request', 'site_scope' => [$this->site->id], 'requires_approval' => true,
        'provisioning_template_version_id' => $template->published_version_id,
    ]);
    $submit = fn () => app(ItCatalogSubmissionService::class)->submit($item, $this->employee, [
        'idempotency_key' => (string) Str::uuid(), 'schema_version' => 1, 'site_id' => $this->site->id, 'values' => [],
    ])['submission']->result;
    $first = $submit();
    expect($first->fresh()->approval_requested_at)->toBeNull()
        ->and(ItTicketEvent::query()->where('subject_id', $first->id)->where('type', 'approval_routing_unavailable')->exists())->toBeTrue()
        ->and(app(ItProvisioningReadinessService::class)->forRequest($first->fresh(), $this->manager)['actions'])->toContain('request_approval');

    w15rFallbackQueue($this);
    $second = $submit();
    expect($second->fresh()->approval_requested_at)->not->toBeNull()
        ->and((int) $second->fresh()->primary_approver_user_id)->toBe($this->approver->id)
        ->and(ItTicketEvent::query()->where('subject_id', $second->id)->where('type', 'approval_requested')
            ->value('payload')['routing_basis'] ?? null)->toBe('service_desk_fallback');
});

test('overdue approvals expire on schedule, block decisions, and can be requested again', function () {
    $workflow = w15rLaunch($this);
    $account = $workflow->requests()->orderBy('stage')->first();
    w15rCommand($this, 'request', $account->id, 'request_approval', [
        'primary_approver_user_id' => $this->approver->id, 'cover_approver_user_id' => $this->cover->id,
        'approval_expires_on' => today()->addDay()->toDateString(), 'reason' => 'Synthetic review.',
    ])->assertOk();
    $this->artisan('it:expire-provisioning-approvals')->assertExitCode(0);
    expect($account->fresh()->approval_status)->toBe('pending');

    $this->travel(3)->days();
    $this->artisan('it:expire-provisioning-approvals')->assertExitCode(0);
    $account->refresh();
    expect($account->approval_status)->toBe('expired')
        ->and(ItTicketEvent::query()->where('subject_id', $account->id)->where('type', 'approval_expired')->exists())->toBeTrue()
        ->and(ItEmailDelivery::query()->where('notification_type', 'it_provisioning_update')
            ->where('notification_context->event', 'approval_expired')->where('recipient_user_id', $this->manager->id)->exists())->toBeTrue();
    $verdict = app(ItProvisioningReadinessService::class)->forRequest($account, $this->approver);
    expect($verdict['actions'])->toContain('request_approval')->not->toContain('approve')
        ->and($verdict['blockers'])->toContain('The approval deadline passed. Request a new review.');
    w15rCommand($this, 'request', $account->id, 'approve', ['reason' => 'Late.'], $this->approver)->assertUnprocessable();

    w15rCommand($this, 'request', $account->id, 'request_approval', [
        'primary_approver_user_id' => $this->approver->id, 'cover_approver_user_id' => $this->cover->id,
        'approval_expires_on' => today()->addDays(2)->toDateString(), 'reason' => 'Fresh review after expiry.',
    ])->assertOk();
    expect($account->fresh()->approval_status)->toBe('pending');
    w15rCommand($this, 'request', $account->id, 'approve', ['reason' => 'Reviewed in time.'], $this->approver)->assertOk();
    expect($account->fresh()->approval_status)->toBe('approved');
    $this->travelBack();
});

test('a completed task is reversed once individually and a cancelled workflow resumes only while nothing was reversed', function () {
    $workflow = w15rLaunch($this);
    [$account, $verify] = $workflow->requests()->orderBy('stage')->get()->all();
    $account->update(['status' => 'done', 'approval_status' => 'approved', 'evidence_summary' => 'Synthetic completed evidence.', 'external_ref' => 'SYN-1']);
    app(ItProvisioningRequestLifecycleService::class)->reconcileWorkflow($workflow);
    expect(app(ItProvisioningReadinessService::class)->forRequest($account->fresh(), $this->manager)['actions'])->toBe(['reverse']);
    w15rCommand($this, 'request', $verify->id, 'reverse', ['reason' => 'Not complete.'])->assertUnprocessable();
    w15rCommand($this, 'request', $account->id, 'reverse', ['reason' => 'Access was granted to the wrong mailbox.'])->assertOk();
    $reversal = $workflow->requests()->where('reversal_of_request_id', $account->id)->sole();
    expect($reversal->action)->toBe('revoke')->and($reversal->approval_required)->toBeTrue()
        ->and($reversal->evidence_required)->toBeTrue()->and($account->fresh()->status)->toBe('done')
        ->and($account->fresh()->evidence_summary)->toBe('Synthetic completed evidence.');
    w15rCommand($this, 'request', $account->id, 'reverse', ['reason' => 'Again.'])->assertUnprocessable();
    expect($workflow->requests()->where('reversal_of_request_id', $account->id)->count())->toBe(1);
    w15rCommand($this, 'workflow', $workflow->id, 'cancel', ['reason' => 'Hire withdrawn.'])->assertOk();
    // Corrective work exists, so resuming is refused with the explicit rule.
    $this->actingAs($this->manager)->get('/it/provisioning/workflows/'.$workflow->id)->assertOk()
        ->assertInertia(fn ($page) => $page->where('workflow.actions', fn ($actions) => ! collect($actions)->contains('resume')));
    w15rCommand($this, 'workflow', $workflow->id, 'resume', ['reason' => 'Try again.'])->assertUnprocessable();
});

test('a cancelled workflow without corrective work resumes with its cancelled tasks reopened', function () {
    $workflow = w15rLaunch($this);
    [$account, $verify] = $workflow->requests()->orderBy('stage')->get()->all();
    w15rCommand($this, 'workflow', $workflow->id, 'cancel', ['reason' => 'Start date unclear.'])->assertOk();
    expect($workflow->fresh()->cancelled_at)->not->toBeNull()->and($account->fresh()->status)->toBe('cancelled');
    $this->actingAs($this->manager)->get('/it/provisioning/workflows/'.$workflow->id)->assertOk()
        ->assertInertia(fn ($page) => $page->where('workflow.actions', fn ($actions) => collect($actions)->contains('resume')));
    w15rCommand($this, 'workflow', $workflow->id, 'resume', ['reason' => 'Start date confirmed.'])->assertOk();
    $workflow->refresh();
    expect($workflow->cancelled_at)->toBeNull()->and($workflow->status)->toBe('pending')
        ->and($account->fresh()->status)->toBe('pending')->and($verify->fresh()->status)->toBe('pending')
        ->and($account->fresh()->approval_status)->toBe('cancelled')
        ->and($workflow->events()->where('type', 'resumed')->exists())->toBeTrue();
    w15rCommand($this, 'workflow', $workflow->id, 'resume', ['reason' => 'Again.'])->assertUnprocessable();
});

test('HR checklist cancellation raises approval-gated corrective work for completed grants and a clean checklist resumes its IT work', function () {
    w15rTemplate($this);
    $checklist = HrOnboardingChecklist::factory()->create([
        'employee_profile_id' => $this->profile->id, 'status' => 'pending', 'created_by' => $this->manager->id,
    ]);
    $workflow = app(ItProvisioningWorkflowService::class)->launchFromOnboarding($checklist, $this->manager->id);
    [$account, $verify] = $workflow->requests()->orderBy('stage')->get()->all();
    app(OnboardingService::class)->setChecklistStatus($checklist, 'cancelled', $this->manager);
    expect($workflow->fresh()->cancelled_at)->not->toBeNull()
        ->and($workflow->requests()->whereNotNull('reversal_of_request_id')->exists())->toBeFalse();
    app(OnboardingService::class)->setChecklistStatus($checklist, 'pending', $this->manager);
    $workflow->refresh();
    expect($workflow->cancelled_at)->toBeNull()->and($account->fresh()->status)->toBe('pending')
        ->and($workflow->events()->where('type', 'source_resumed')->exists())->toBeTrue();

    $account->update(['status' => 'done', 'approval_status' => 'approved', 'evidence_summary' => 'Synthetic completed evidence.', 'external_ref' => 'SYN-HR']);
    app(OnboardingService::class)->setChecklistStatus($checklist, 'cancelled', $this->manager);
    $reversal = $workflow->requests()->where('reversal_of_request_id', $account->id)->sole();
    expect($reversal->approval_required)->toBeTrue()->and($reversal->status)->not->toBe('done')
        ->and($verify->fresh()->status)->toBe('cancelled')->and($account->fresh()->status)->toBe('done')
        ->and($workflow->fresh()->events()->where('type', 'source_reversal_requested')->exists())->toBeTrue();
    app(OnboardingService::class)->setChecklistStatus($checklist, 'pending', $this->manager);
    expect($workflow->fresh()->cancelled_at)->not->toBeNull()->and($verify->fresh()->status)->toBe('cancelled');
});

test('a withdrawn provisioning template blocks new catalogue submissions while existing requests keep their contract', function () {
    $template = w15rTemplate($this);
    $item = ItCatalogItem::factory()->provisioning()->create([
        'name' => 'Synthetic pinned request', 'site_scope' => [$this->site->id], 'requires_approval' => false,
        'provisioning_template_version_id' => $template->published_version_id,
    ]);
    $input = fn () => ['idempotency_key' => (string) Str::uuid(), 'schema_version' => 1, 'site_id' => $this->site->id, 'values' => []];
    $first = app(ItCatalogSubmissionService::class)->submit($item, $this->employee, $input())['submission']->result;
    app(ItProvisioningTemplatePublicationService::class)->publish($template->fresh(), $this->manager, false, [
        'expected_version' => $template->fresh()->lock_version, 'expected_published_version_id' => $template->published_version_id,
        'reason' => 'Instructions withdrawn for review.',
    ]);
    expect(fn () => app(ItCatalogSubmissionService::class)->submit($item, $this->employee, $input()))
        ->toThrow(ValidationException::class, 'withdrawn');
    expect(ItProvisioningWorkflow::count())->toBe(1)
        ->and(app(ItProvisioningTrackingService::class)->detail($this->employee, $first->fresh())['title'])->toBe('Synthetic pinned request');
});
