<?php

use App\Domain\Hr\Models\HrOffboardingChecklist;
use App\Domain\Hr\Models\HrOnboardingChecklist;
use App\Domain\Hr\Services\OnboardingService;
use App\Domain\It\Services\ItCatalogManagementService;
use App\Domain\It\Services\ItCatalogSubmissionService;
use App\Domain\It\Services\ItProvisioningTemplatePublicationService;
use App\Domain\It\Services\ItProvisioningTemplateService;
use App\Domain\It\Services\ItProvisioningTrackingService;
use App\Domain\It\Services\ItProvisioningWorkflowService;
use App\Models\Identity;
use App\Models\ItCatalogItem;
use App\Models\ItCatalogSubmission;
use App\Models\ItProvisioningRequest;
use App\Models\ItProvisioningTemplate;
use App\Models\ItProvisioningWorkflow;
use App\Models\ItTicketCommandReceipt;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;

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

function w15ReviewedTemplate($test, string $lifecycle = 'joiner'): ItProvisioningTemplate
{
    $template = app(ItProvisioningTemplateService::class)->create($test->manager, [
        'name' => 'Synthetic reviewed '.$lifecycle, 'description' => 'Synthetic original instructions',
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

function w15Launch($test, ?ItProvisioningTemplate $template = null): ItProvisioningWorkflow
{
    $template ??= w15ReviewedTemplate($test);
    $response = $test->actingAs($test->manager)->postJson('/it/provisioning/commands/launch/'.$test->profile->id.'/launch', [
        'actor_user_id' => $test->manager->id, 'request_uuid' => (string) Str::uuid(), 'expected_version' => 1,
        'template_version_id' => $template->published_version_id, 'lifecycle_type' => $template->lifecycle_type,
        'effective_date' => today()->toDateString(), 'owner_user_id' => $test->assignee->id, 'cover_user_id' => $test->cover->id,
        'reason' => 'Synthetic reviewed staff workflow.',
    ])->assertOk()->assertJsonPath('status', 'committed');

    return ItProvisioningWorkflow::findOrFail($response->json('data.result_id'));
}

function w15TaskCommand($test, ItProvisioningRequest $task, string $operation, array $data = [], ?User $actor = null)
{
    $actor ??= $test->manager;

    return $test->actingAs($actor)->postJson('/it/provisioning/commands/request/'.$task->id.'/'.$operation, [
        'actor_user_id' => $actor->id, 'request_uuid' => (string) Str::uuid(),
        'expected_version' => $task->fresh()->lock_version, ...$data,
    ]);
}

function w15ApproveTask($test, ItProvisioningRequest $task): void
{
    w15TaskCommand($test, $task, 'request_approval', [
        'primary_approver_user_id' => $test->approver->id, 'cover_approver_user_id' => $test->cover->id,
        'approval_expires_on' => today()->addDays(2)->toDateString(), 'reason' => 'Synthetic access request requires independent approval.',
    ])->assertOk();
    w15TaskCommand($test, $task, 'approve', ['reason' => 'Synthetic approval after reviewing the original instructions.'], $test->approver)->assertOk();
}

test('manual work has one immutable command result and cancellation prevents late creation', function () {
    $uuid = (string) Str::uuid();
    $url = '/it/provisioning/commands/manual/'.$this->profile->id.'/create';
    $payload = ['actor_user_id' => $this->manager->id, 'request_uuid' => $uuid, 'expected_version' => 1,
        'type' => 'account', 'item' => 'Synthetic mailbox', 'priority' => 'normal', 'notes' => 'Private manual instructions',
        'assigned_to_user_id' => $this->assignee->id];
    $first = $this->actingAs($this->manager)->postJson($url, $payload)->assertOk();
    $id = $first->json('data.result_id');
    $this->postJson($url, $payload)->assertOk()->assertJsonPath('data.result_id', $id)->assertJsonPath('data.replayed', true);
    $this->postJson($url, [...$payload, 'item' => 'Changed command'])->assertConflict();
    $task = ItProvisioningRequest::findOrFail($id);
    expect($task->category)->toBe('account')->and($task->action)->toBe('grant')->and($task->stage)->toBe(1)
        ->and($task->approval_required)->toBeTrue()->and($task->evidence_required)->toBeTrue()
        ->and(ItProvisioningRequest::count())->toBe(1)
        ->and(json_encode(ItTicketCommandReceipt::where('request_uuid', $uuid)->sole()->getAttributes()))
        ->not->toContain('Private manual instructions');
    $cancelled = (string) Str::uuid();
    $this->postJson($url.'/cancel', ['actor_user_id' => $this->manager->id, 'request_uuid' => $cancelled])
        ->assertOk()->assertJsonPath('status', 'cancelled');
    $this->postJson($url, [...$payload, 'request_uuid' => $cancelled])->assertOk()->assertJsonPath('status', 'cancelled');
    expect(ItProvisioningRequest::count())->toBe(1);
});

test('workflow completion enforces independent approval dependencies and manual verification', function () {
    $workflow = w15Launch($this);
    [$account, $verify] = $workflow->requests()->get()->all();
    w15TaskCommand($this, $verify, 'fulfil', ['evidence_summary' => 'Synthetic downstream evidence is recorded.'])->assertUnprocessable();
    w15TaskCommand($this, $account, 'request_approval', [
        'primary_approver_user_id' => $this->manager->id, 'cover_approver_user_id' => $this->cover->id,
        'approval_expires_on' => today()->addDays(2)->toDateString(), 'reason' => 'Requester must not approve this.',
    ])->assertUnprocessable();
    w15TaskCommand($this, $account, 'fulfil', ['evidence_summary' => 'Synthetic account verification.', 'external_ref' => 'SYN-ACCOUNT'])->assertUnprocessable();
    w15ApproveTask($this, $account);
    w15TaskCommand($this, $account, 'fulfil', ['evidence_summary' => 'Synthetic account verification.'])->assertUnprocessable();
    w15TaskCommand($this, $account, 'fulfil', ['evidence_summary' => 'Synthetic account verification.', 'external_ref' => 'SYN-ACCOUNT'])->assertOk();
    expect($account->fresh()->notes)->toBe('Private original task instructions')
        ->and($account->fresh()->fulfilment_mode)->toBe('manual_evidence');
    w15TaskCommand($this, $verify, 'fulfil', ['evidence_summary' => 'Synthetic downstream verification.'])->assertOk();
    expect($workflow->fresh()->status)->toBe('completed');
});

test('failure needs explicit retry and stale decisions cannot replace current work', function () {
    $workflow = w15Launch($this);
    $task = $workflow->requests()->firstOrFail();
    $oldVersion = $task->lock_version;
    w15TaskCommand($this, $task, 'fail', ['reason' => 'Synthetic external action did not complete.'])->assertOk();
    w15TaskCommand($this, $task, 'fulfil', ['evidence_summary' => 'Synthetic verification.', 'external_ref' => 'SYN-FAIL'])->assertUnprocessable();
    w15TaskCommand($this, $task, 'retry', ['reason' => 'Synthetic fault reviewed.', 'expected_version' => $oldVersion])->assertConflict();
    w15TaskCommand($this, $task, 'retry', ['reason' => 'Synthetic fault reviewed; retry original work.'])->assertOk();
    expect($task->fresh()->status)->toBe('pending')
        ->and($task->events()->where('type', 'failed')->exists())->toBeTrue()
        ->and($task->events()->where('type', 'retried')->exists())->toBeTrue();
});

test('workflow cancellation retains completed evidence and creates each explicit reversal only once', function () {
    $workflow = w15Launch($this);
    [$account, $verify] = $workflow->requests()->get()->all();
    w15ApproveTask($this, $account);
    w15TaskCommand($this, $account, 'fulfil', ['evidence_summary' => 'Synthetic retained account proof.', 'external_ref' => 'SYN-RETAIN'])->assertOk();
    $original = $account->fresh()->only(['status', 'evidence_summary', 'external_ref', 'fulfilled_at', 'notes']);
    $url = '/it/provisioning/commands/workflow/'.$workflow->id.'/cancel';
    $data = ['actor_user_id' => $this->manager->id, 'request_uuid' => (string) Str::uuid(),
        'expected_version' => $workflow->fresh()->lock_version, 'reason' => 'Synthetic cancellation requires explicit corrective work.',
        'create_reversals' => true];
    $this->actingAs($this->manager)->postJson($url, $data)->assertOk();
    $this->postJson($url, $data)->assertOk()->assertJsonPath('data.replayed', true);
    expect($account->fresh()->only(array_keys($original)))->toEqual($original)
        ->and($verify->fresh()->status)->toBe('cancelled')
        ->and($workflow->fresh()->status)->toBe('cancelled')
        ->and($workflow->requests()->where('reversal_of_request_id', $account->id)->count())->toBe(1);
    $reversal = $workflow->requests()->where('reversal_of_request_id', $account->id)->sole();
    expect($reversal->action)->toBe('revoke')->and($reversal->approval_required)->toBeTrue()->and($reversal->status)->toBe('pending');
    w15TaskCommand($this, $verify, 'reopen', ['reason' => 'Late stale source action.'])->assertUnprocessable();
});

test('catalogue tracking covers every original task and keeps its public request identity', function () {
    $template = w15ReviewedTemplate($this);
    $item = ItCatalogItem::factory()->provisioning()->create([
        'name' => 'Synthetic staff access request', 'site_scope' => [$this->site->id], 'requires_approval' => true,
        'provisioning_template_version_id' => $template->published_version_id,
    ]);
    $input = ['idempotency_key' => (string) Str::uuid(), 'schema_version' => 1, 'site_id' => $this->site->id, 'values' => []];
    $first = app(ItCatalogSubmissionService::class)->submit($item, $this->employee, $input);
    $again = app(ItCatalogSubmissionService::class)->submit($item, $this->employee, $input);
    expect($again['created'])->toBeFalse()->and(ItCatalogSubmission::count())->toBe(1);
    $anchor = $first['submission']->result;
    $workflow = $anchor->workflow;
    $tasks = $workflow->requests()->get();
    expect($tasks)->toHaveCount(2)->and($tasks->every(fn ($task) => $task->approval_required))->toBeTrue();
    // Model updates here isolate the read projection from the separately tested action gateway.
    $anchor->update(['status' => 'done', 'approval_status' => 'approved']);
    $tracking = app(ItProvisioningTrackingService::class);
    $detail = $tracking->detail($this->employee, $anchor);
    expect($detail['status'])->toBe('in_progress')->and($detail['approval_status'])->toBe('pending')
        ->and($detail['progress'])->toMatchArray(['total' => 2, 'done' => 1])
        ->and($detail['title'])->toBe('Synthetic staff access request')
        ->and($detail['href'])->toBe('/it/provisioning/'.$anchor->id)
        ->and(json_encode($detail))->not->toContain('Private account work', 'Private original task instructions', 'Private second task');
    expect($tracking->listing($this->employee, Request::create('/it', 'GET', ['my_status' => 'done']))['matched'])->toBe(0)
        ->and($tracking->listing($this->employee, Request::create('/it', 'GET', ['my_status' => 'in_progress']))['matched'])->toBe(1);
    $tasks->last()->update(['status' => 'done', 'approval_status' => 'approved']);
    $workflow->requests()->create(['employee_profile_id' => $this->profile->id, 'type' => 'account',
        'item' => 'Private corrective task', 'status' => 'pending', 'reversal_of_request_id' => $anchor->id]);
    $workflow->update(['status' => 'in_progress']);
    $detail = $tracking->detail($this->employee, $anchor->fresh());
    expect($detail['status'])->toBe('done')->and($detail['progress']['total'])->toBe(2)
        ->and($tracking->listing($this->employee, Request::create('/it', 'GET', ['my_status' => 'done']))['matched'])->toBe(1)
        ->and(json_encode($detail))->not->toContain('Private corrective task');
});

test('HR start date changes retime only unfinished work and cancellation never revives completed work', function () {
    w15ReviewedTemplate($this);
    $checklist = HrOnboardingChecklist::factory()->create([
        'employee_profile_id' => $this->profile->id, 'status' => 'pending', 'created_by' => $this->manager->id,
    ]);
    $workflow = app(ItProvisioningWorkflowService::class)->launchFromOnboarding($checklist, $this->manager->id);
    [$account, $verify] = $workflow->requests()->get()->all();
    $account->update(['status' => 'done', 'evidence_summary' => 'Synthetic completed evidence retained.', 'external_ref' => 'SYN-HR']);
    $verify->update(['approval_required' => true, 'approval_status' => 'approved']);
    $originalDate = $workflow->effective_at->toDateString();
    $completed = $account->fresh()->only(['due_date', 'status', 'evidence_summary', 'external_ref']);
    $newDate = today()->addDays(10)->toDateString();
    $this->actingAs($this->manager)->postJson('/it/provisioning/commands/workflow/'.$workflow->id.'/reschedule', [
        'actor_user_id' => $this->manager->id, 'request_uuid' => (string) Str::uuid(),
        'expected_version' => $workflow->fresh()->lock_version, 'effective_date' => $newDate,
        'reason' => 'IT must not replace the canonical HR date.',
    ])->assertUnprocessable();
    $this->putJson('/hr/people/'.$this->profile->id, ['start_date' => $newDate])->assertRedirect();
    expect($workflow->fresh()->effective_at->toDateString())->toBe($newDate)
        ->and($workflow->fresh()->original_effective_at->toDateString())->toBe($originalDate)
        ->and($verify->fresh()->due_date->toDateString())->toBe(today()->addDays(11)->toDateString())
        ->and($verify->fresh()->approval_status)->toBe('cancelled')
        ->and($account->fresh()->only(array_keys($completed)))->toEqual($completed);
    $this->putJson('/hr/people/'.$this->profile->id, ['position_title' => 'Updated support role'])->assertRedirect();
    expect($this->profile->fresh()->start_date->toDateString())->toBe($newDate);
    $this->putJson('/hr/people/'.$this->profile->id, ['start_date' => null])
        ->assertUnprocessable()->assertJsonValidationErrors('start_date');
    expect($workflow->fresh()->effective_at->toDateString())->toBe($newDate)
        ->and($verify->fresh()->due_date->toDateString())->toBe(today()->addDays(11)->toDateString());
    app(OnboardingService::class)->setChecklistStatus($checklist, 'cancelled', $this->manager);
    expect($workflow->fresh()->cancelled_at)->not->toBeNull()
        ->and($verify->fresh()->status)->toBe('cancelled')
        ->and($account->fresh()->only(array_keys($completed)))->toEqual($completed);
    app(OnboardingService::class)->setChecklistStatus($checklist, 'pending', $this->manager);
    expect($workflow->fresh()->status)->toBe('cancelled')->and($verify->fresh()->status)->toBe('cancelled');
});

test('current access is rechecked while retained committed manual work remains recoverable after the employee leaves', function () {
    $uuid = (string) Str::uuid();
    $url = '/it/provisioning/commands/manual/'.$this->profile->id.'/create';
    $payload = ['actor_user_id' => $this->manager->id, 'request_uuid' => $uuid, 'expected_version' => 1,
        'type' => 'other', 'item' => 'Synthetic retained work', 'priority' => 'normal'];
    $created = $this->actingAs($this->manager)->postJson($url, $payload)->assertOk();
    $id = $created->json('data.result_id');
    $this->profile->update(['is_active' => false]);
    $this->getJson($url.'?'.http_build_query(['actor_user_id' => $this->manager->id, 'request_uuid' => $uuid]))
        ->assertOk()->assertJsonPath('status', 'committed')->assertJsonPath('data.result_id', $id);
    $this->postJson($url, [...$payload, 'request_uuid' => (string) Str::uuid()])->assertNotFound();
    $this->manager->hrEmployeeProfile()->update(['end_date' => today()->subDay()->toDateString()]);
    $this->getJson($url.'?'.http_build_query(['actor_user_id' => $this->manager->id, 'request_uuid' => $uuid]))->assertNotFound();
    expect(ItProvisioningRequest::count())->toBe(1);
});

test('withdrawn publication history prevents a rollback that would reactivate legacy instructions', function () {
    $template = w15ReviewedTemplate($this);
    app(ItProvisioningTemplatePublicationService::class)->publish($template, $this->manager, false, [
        'expected_version' => $template->lock_version, 'expected_published_version_id' => $template->published_version_id,
        'reason' => 'Synthetic instructions withdrawn pending review.',
    ]);
    expect($template->fresh()->published_at)->toBeNull()->and($template->fresh()->published_version_id)->toBeNull();
    $migration = require database_path('migrations/2026_09_13_000080_complete_it_provisioning_lifecycle.php');
    expect(fn () => $migration->down())->toThrow(RuntimeException::class, 'lifecycle evidence exists');
    expect(Schema::hasColumn('it_provisioning_templates', 'published_version_id'))->toBeTrue()
        ->and($template->fresh()->published_version_id)->toBeNull();
});

test('a replacement catalogue Site cannot authorize changing the original out-of-scope record', function () {
    $otherSite = Site::factory()->create();
    $item = ItCatalogItem::factory()->create(['site_scope' => [$otherSite->id], 'name' => 'Other Site original form']);
    $originalVersion = $item->lock_version;
    try {
        app(ItCatalogManagementService::class)->update($item, $this->manager, [
            'expected_version' => $originalVersion, 'site_scope' => [$this->site->id], 'name' => 'Attempted replacement',
        ]);
        $this->fail('The original Site must be authorized before reading a replacement scope.');
    } catch (HttpException $error) {
        expect($error->getStatusCode())->toBe(404);
    }
    expect($item->fresh()->name)->toBe('Other Site original form')
        ->and($item->fresh()->site_scope)->toBe([$otherSite->id])
        ->and($item->fresh()->lock_version)->toBe($originalVersion);
});

test('saved edits and withdrawal never rewrite launched instructions while new work requires current publication', function () {
    $template = w15ReviewedTemplate($this);
    $first = w15Launch($this, $template);
    $originalVersion = $template->publishedVersion()->firstOrFail();
    $originalContract = $originalVersion->contract;
    $originalTasks = $first->requests()->get()->map->only(['item', 'notes', 'action', 'stage', 'dependency_request_ids'])->all();
    $changed = $originalContract;
    $changed['name'] = 'Synthetic replacement instructions';
    $changed['tasks'][0]['title'] = 'Synthetic revised account work';
    $changed['expected_version'] = $template->fresh()->lock_version;
    $template = app(ItProvisioningTemplateService::class)->update($template, $this->manager, $changed);
    expect($template->published_version_id)->toBe($originalVersion->id)
        ->and($template->current_version_id)->not->toBe($originalVersion->id);
    $stillPublished = w15Launch($this, $template);
    expect($stillPublished->template_version_id)->toBe($originalVersion->id);
    $template = app(ItProvisioningTemplatePublicationService::class)->publish($template, $this->manager, false, [
        'expected_version' => $template->lock_version, 'expected_published_version_id' => $template->published_version_id,
        'reason' => 'Synthetic instructions withdrawn before new work.',
    ]);
    $url = '/it/provisioning/commands/launch/'.$this->profile->id.'/launch';
    $this->actingAs($this->manager)->postJson($url, [
        'actor_user_id' => $this->manager->id, 'request_uuid' => (string) Str::uuid(), 'expected_version' => 1,
        'template_version_id' => $originalVersion->id, 'lifecycle_type' => 'joiner',
        'effective_date' => today()->toDateString(), 'owner_user_id' => $this->assignee->id, 'cover_user_id' => $this->cover->id,
        'reason' => 'Cannot launch withdrawn instructions.',
    ])->assertUnprocessable();
    $template = app(ItProvisioningTemplatePublicationService::class)->publish($template, $this->manager, true, [
        'expected_version' => $template->lock_version, 'expected_published_version_id' => null,
        'reason' => 'Synthetic replacement reviewed and published.',
    ]);
    $new = w15Launch($this, $template);
    expect($new->template_version_id)->toBe($template->published_version_id)
        ->and($new->requests()->firstOrFail()->item)->toBe('Synthetic revised account work')
        ->and($originalVersion->fresh()->contract)->toEqual($originalContract)
        ->and($first->requests()->get()->map->only(['item', 'notes', 'action', 'stage', 'dependency_request_ids'])->all())->toEqual($originalTasks);
});

test('completion reauthorizes the original linked record even when the command omits target fields', function () {
    $workflow = w15Launch($this);
    $account = $workflow->requests()->firstOrFail();
    w15ApproveTask($this, $account);
    // An original identity link may cease to belong to the beneficiary.
    $identity = Identity::query()->create([
        'user_id' => $this->cover->id, 'provider' => 'microsoft', 'provider_user_id' => 'synthetic-linked-identity',
        'email' => 'synthetic-private@example.test',
    ]);
    $account->update(['canonical_target_type' => 'identity', 'canonical_target_id' => $identity->id]);
    w15TaskCommand($this, $account, 'fulfil', [
        'external_ref' => 'SYN-BOUND', 'evidence_summary' => 'Synthetic external work proof cannot bypass linked-record access.',
    ])->assertUnprocessable();
    expect($account->fresh()->status)->toBe('pending')->and($account->fresh()->fulfilled_at)->toBeNull()
        ->and($identity->fresh()->user_id)->toBe($this->cover->id);
});

test('corrective work prevents dependent original completion and keeps its own dates when original work is cancelled', function () {
    $workflow = w15Launch($this);
    [$account, $verify] = $workflow->requests()->get()->all();
    w15ApproveTask($this, $account);
    w15TaskCommand($this, $account, 'fulfil', ['evidence_summary' => 'Synthetic original account evidence.', 'external_ref' => 'SYN-CORRECTION'])->assertOk();
    $url = '/it/provisioning/commands/workflow/'.$workflow->id;
    $this->actingAs($this->manager)->postJson($url.'/reverse', [
        'actor_user_id' => $this->manager->id, 'request_uuid' => (string) Str::uuid(),
        'expected_version' => $workflow->fresh()->lock_version, 'reason' => 'Synthetic completed access must be reversed.',
    ])->assertOk();
    $reversal = $workflow->requests()->where('reversal_of_request_id', $account->id)->sole();
    w15TaskCommand($this, $verify, 'fulfil', ['evidence_summary' => 'Cannot continue after prerequisite reversal.'])->assertUnprocessable();
    $this->get('/it/provisioning/tasks/'.$verify->id)->assertOk()->assertInertia(fn ($page) => $page
        ->where('task.readiness.actions', fn ($actions) => ! collect($actions)->contains('fulfil')));
    $this->postJson($url.'/cancel', [
        'actor_user_id' => $this->manager->id, 'request_uuid' => (string) Str::uuid(),
        'expected_version' => $workflow->fresh()->lock_version, 'reason' => 'Cancel remaining original instructions.',
    ])->assertOk();
    expect($verify->fresh()->status)->toBe('cancelled')
        ->and($reversal->fresh()->status)->toBe('pending')
        ->and($account->fresh()->status)->toBe('done');
});

test('template history checks the original Site of each version after current publication moves', function () {
    $template = w15ReviewedTemplate($this);
    $oldVersion = $template->publishedVersion()->firstOrFail();
    $secondSite = Site::factory()->create();
    $managerProfile = $this->manager->hrEmployeeProfile()->firstOrFail();
    $managerProfile->update(['secondary_site_ids' => [$secondSite->id]]);
    $changed = $oldVersion->contract;
    $changed['site_id'] = $secondSite->id;
    $changed['name'] = 'Synthetic second Site instructions';
    $changed['expected_version'] = $template->lock_version;
    $template = app(ItProvisioningTemplateService::class)->update($template, $this->manager, $changed);
    $template = app(ItProvisioningTemplatePublicationService::class)->publish($template, $this->manager, true, [
        'expected_version' => $template->lock_version, 'expected_published_version_id' => $oldVersion->id,
        'reason' => 'Synthetic second Site publication reviewed with access to both original scopes.',
    ]);
    $managerProfile->update(['primary_site_id' => $secondSite->id, 'secondary_site_ids' => []]);
    $this->actingAs($this->manager->fresh())->get('/it/provisioning/templates/'.$template->id)->assertOk()
        ->assertInertia(fn ($page) => $page->has('template.versions', 1)
            ->where('template.versions.0.id', $template->published_version_id)
            ->where('template.versions.0.contract.site_id', $secondSite->id));
    expect($oldVersion->fresh()->contract['site_id'])->toBe($this->site->id);
});

test('an offboarding workflow with no source date retains unknown dates until HR records the last working day', function () {
    w15ReviewedTemplate($this, 'leaver');
    $checklist = HrOffboardingChecklist::query()->create([
        'employee_profile_id' => $this->profile->id, 'template_key' => 'synthetic-missing-date',
        'status' => 'pending', 'due_date' => null, 'created_by' => $this->manager->id,
    ]);
    $workflow = app(ItProvisioningWorkflowService::class)->launchFromOffboarding($checklist, $this->manager->id);
    $tasks = $workflow->requests()->get();
    expect($workflow->effective_at)->toBeNull()->and($workflow->original_effective_at)->toBeNull()
        ->and($tasks->every(fn ($task) => $task->due_date === null))->toBeTrue()
        ->and($tasks->pluck('due_offset_days')->all())->toBe([0, 1]);
    w15TaskCommand($this, $tasks->first(), 'assign', ['assigned_to_user_id' => $this->assignee->id])->assertUnprocessable();
    $newDate = today()->addDays(12)->toDateString();
    app(OnboardingService::class)->setOffboardingChecklistStatus($checklist, 'in_progress', $this->manager, $newDate);
    expect($workflow->fresh()->effective_at->toDateString())->toBe($newDate)
        ->and($tasks->first()->fresh()->due_date->toDateString())->toBe($newDate)
        ->and($tasks->last()->fresh()->due_date->toDateString())->toBe(today()->addDays(13)->toDateString());
});

test('expired or ineligible approval responsibility cannot decide until a new independent review is requested', function () {
    $workflow = w15Launch($this);
    $task = $workflow->requests()->firstOrFail();
    w15TaskCommand($this, $task, 'request_approval', [
        'primary_approver_user_id' => $this->approver->id, 'cover_approver_user_id' => $this->cover->id,
        'approval_expires_on' => today()->addDays(2)->toDateString(), 'reason' => 'Synthetic independent review.',
    ])->assertOk();
    $firstRequestedAt = $task->fresh()->approval_requested_at;
    $task->update(['approval_expires_at' => now()->subMinute()]);
    w15TaskCommand($this, $task, 'approve', ['reason' => 'This expired decision must not commit.'], $this->approver)->assertForbidden();
    expect($task->fresh()->approval_status)->toBe('pending')->and($task->fresh()->approved_at)->toBeNull();
    w15TaskCommand($this, $task, 'request_approval', [
        'primary_approver_user_id' => $this->approver->id, 'cover_approver_user_id' => $this->cover->id,
        'approval_expires_on' => today()->addDays(3)->toDateString(), 'reason' => 'Synthetic renewed review after expiry.',
    ])->assertOk();
    expect($task->events()->where('type', 'approval_requested')->count())->toBe(2);
    $this->approver->hrEmployeeProfile()->update(['end_date' => today()->subDay()->toDateString()]);
    w15TaskCommand($this, $task, 'approve', ['reason' => 'Former staff cannot decide.'], $this->approver)->assertNotFound();
    w15TaskCommand($this, $task, 'approve', ['reason' => 'Eligible cover reviewed the renewed request.'], $this->cover)->assertOk();
    expect($task->fresh()->approved_by_user_id)->toBe($this->cover->id)
        ->and($task->fresh()->approval_requested_at->greaterThanOrEqualTo($firstRequestedAt))->toBeTrue();
});

test('manual mover work derives its delta from retained provisioning and the current canonical HR profile', function () {
    $baseline = w15Launch($this);
    $this->profile->update(['position_role' => 'team_lead']);
    $template = w15ReviewedTemplate($this, 'mover');
    $mover = w15Launch($this, $template);
    expect($mover->changes)->toEqual(['position_role' => ['from' => $baseline->role_snapshot, 'to' => 'team_lead']])
        ->and($mover->role_snapshot)->toBe('team_lead');
    $this->actingAs($this->manager)->postJson('/it/provisioning/commands/launch/'.$this->profile->id.'/launch', [
        'actor_user_id' => $this->manager->id, 'request_uuid' => (string) Str::uuid(), 'expected_version' => 1,
        'template_version_id' => $template->published_version_id, 'lifecycle_type' => 'mover',
        'effective_date' => today()->toDateString(), 'owner_user_id' => $this->assignee->id, 'cover_user_id' => $this->cover->id,
        'reason' => 'The latest recorded context already matches HR.',
        'changes' => ['position_role' => ['from' => 'fabricated', 'to' => 'admin']],
    ])->assertUnprocessable();
    expect(ItProvisioningWorkflow::count())->toBe(2);
});
