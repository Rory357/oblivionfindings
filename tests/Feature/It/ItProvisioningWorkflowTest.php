<?php

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Domain\Hr\Models\HrOffboardingChecklist;
use App\Domain\Hr\Models\HrOnboardingChecklist;
use App\Domain\Hr\Models\HrOnboardingTask;
use App\Domain\It\Services\ItProvisioningWorkflowService;
use App\Domain\SecurityDevices\Enums\AssignmentType;
use App\Domain\SecurityDevices\Models\Device;
use App\Domain\SecurityDevices\Models\DeviceAssignment;
use App\Models\Asset;
use App\Models\AssetAssignment;
use App\Models\ItProvisioningRequest;
use App\Models\ItProvisioningTemplate;
use App\Models\ItProvisioningTemplateTask;
use App\Models\ItProvisioningWorkflow;
use App\Models\ItTeam;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use App\Support\LegacyStorageContext;
use Database\Seeders\ItProvisioningTemplateSeeder;
use Database\Seeders\RbacSeeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Schema;

function jmlManager(): User
{
    $user = User::factory()->create([
        'role' => 'hr',
        'approved_at' => now(),
    ]);
    $user->roles()->syncWithoutDetaching([
        Role::query()->where('name', 'hr')->firstOrFail()->id,
    ]);

    return $user;
}

function jmlProfile(?Site $site = null, array $overrides = []): HrEmployeeProfile
{
    $site ??= test()->site;
    $user = User::factory()->create(['approved_at' => now()]);

    return HrEmployeeProfile::factory()->create([
        'user_id' => $user->id,
        'employee_number' => 'EMP-JML-'.$user->id,
        'personal_email' => 'private-'.$user->id.'@example.test',
        'home_address' => 'Never disclose this address',
        'bank_account' => '12-3456-7890123-00',
        'work_email' => $user->email,
        'position_title' => 'Support Worker',
        'position_role' => 'support_worker',
        'employment_type' => 'full_time',
        'start_date' => now()->addDays(14)->toDateString(),
        'is_active' => true,
        'primary_site_id' => $site?->id,
        ...$overrides,
    ]);
}

function jmlAssignSite(User $user, Site $site): void
{
    $profile = HrEmployeeProfile::query()->where('user_id', $user->id)->first();
    if ($profile) {
        if ((int) $profile->primary_site_id !== (int) $site->id) {
            $profile->update([
                'secondary_site_ids' => collect($profile->secondary_site_ids ?? [])
                    ->push($site->id)
                    ->map(fn (mixed $id): int => (int) $id)
                    ->unique()
                    ->values()
                    ->all(),
            ]);
        }

        return;
    }

    HrEmployeeProfile::factory()->create([
        'user_id' => $user->id,
        'primary_site_id' => $site->id,
        'is_active' => true,
        'start_date' => now()->subMonth()->toDateString(),
        'end_date' => null,
        'created_by' => $user->id,
        'updated_by' => $user->id,
    ]);
}

/**
 * @param  array<int, array<string, mixed>>  $tasks
 */
function jmlTemplate(string $lifecycle, array $tasks, array $overrides = []): ItProvisioningTemplate
{
    $template = ItProvisioningTemplate::query()->create([
        'name' => ucfirst($lifecycle).' default',
        'lifecycle_type' => $lifecycle,
        'position_role' => null,
        'site_id' => null,
        'employment_type' => null,
        'selection_priority' => 0,
        'is_active' => true,
        ...$overrides,
    ]);

    foreach ($tasks as $index => $task) {
        ItProvisioningTemplateTask::query()->create([
            'provisioning_template_id' => $template->id,
            'task_key' => $task['task_key'] ?? 'task-'.($index + 1),
            'title' => $task['title'] ?? 'Provision item '.($index + 1),
            'description' => $task['description'] ?? null,
            'category' => $task['category'] ?? 'account',
            'action' => $task['action'] ?? 'grant',
            'request_type' => $task['request_type'] ?? 'account',
            'responsible_team_id' => $task['responsible_team_id'] ?? null,
            'stage' => $task['stage'] ?? 1,
            'sort_order' => $task['sort_order'] ?? ($index + 1),
            'dependency_task_keys' => $task['dependency_task_keys'] ?? [],
            'trigger_fields' => $task['trigger_fields'] ?? [],
            'approval_required' => $task['approval_required'] ?? false,
            'evidence_required' => $task['evidence_required'] ?? false,
            'due_offset_days' => $task['due_offset_days'] ?? 0,
            'fulfiller_fields' => $task['fulfiller_fields'] ?? ['employee_number', 'work_email', 'position_role', 'primary_site'],
        ]);
    }

    return $template->load('tasks');
}

beforeEach(function () {
    $this->seed(RbacSeeder::class);
    $this->site = Site::factory()->create();
    $this->manager = jmlManager();
    jmlAssignSite($this->manager, $this->site);
    $this->service = app(ItProvisioningWorkflowService::class);
});

test('JML persistence carries templates workflows and governed provisioning task state', function () {
    expect(Schema::hasColumns('it_provisioning_templates', [
        LegacyStorageContext::column(), 'lifecycle_type', 'position_role', 'site_id', 'employment_type',
        'selection_priority', 'is_active',
    ]))->toBeTrue();
    expect(Schema::hasColumns('it_provisioning_template_tasks', [
        'provisioning_template_id', 'task_key', 'category', 'action', 'responsible_team_id',
        'stage', 'dependency_task_keys', 'trigger_fields', 'approval_required',
        'evidence_required', 'due_offset_days', 'fulfiller_fields',
    ]))->toBeTrue();
    expect(Schema::hasColumns('it_provisioning_workflows', [
        LegacyStorageContext::column(), 'employee_profile_id', 'provisioning_template_id', 'lifecycle_type',
        'source_type', 'source_id', 'source_event_key', 'status', 'effective_at',
        'role_snapshot', 'site_id_snapshot', 'employment_type_snapshot', 'changes',
    ]))->toBeTrue();
    expect(Schema::hasColumns('it_provisioning_requests', [
        'provisioning_workflow_id', 'provisioning_template_task_id', 'offboarding_task_id',
        'task_key', 'action', 'category', 'responsible_team_id', 'stage', 'dependency_request_ids',
        'approval_required', 'approval_status', 'approved_by_user_id', 'approved_at',
        'evidence_required', 'evidence_summary', 'failure_reason', 'failed_at',
        'fulfiller_context', 'canonical_target_type', 'canonical_target_id',
    ]))->toBeTrue();
});

test('safe baseline templates make joiner mover and leaver workflows usable immediately', function () {
    $this->seed(ItProvisioningTemplateSeeder::class);

    expect(ItProvisioningTemplate::query()->pluck('lifecycle_type')->all())
        ->toContain('joiner', 'mover', 'leaver');

    $joiner = ItProvisioningTemplate::query()->where('lifecycle_type', 'joiner')->firstOrFail();
    expect($joiner->tasks()->pluck('category')->all())
        ->toContain(
            'account', 'group', 'licence', 'email', 'device', 'peripheral', 'network',
            'access_control', 'telephony', 'vehicle_technology', 'healthcare_access',
        )
        ->and($joiner->tasks()->where('category', 'healthcare_access')->value('approval_required'))->toBeTruthy();
});

test('template resolution chooses the most specific role site and employment match', function () {
    $site = Site::factory()->create();
    $otherSite = Site::factory()->create();
    $profile = jmlProfile($site);

    jmlTemplate('joiner', [['title' => 'Generic account']]);
    jmlTemplate('joiner', [['title' => 'Wrong site']], ['site_id' => $otherSite->id]);
    $specific = jmlTemplate('joiner', [['title' => 'Exact support-worker account']], [
        'position_role' => 'support_worker',
        'site_id' => $site->id,
        'employment_type' => 'full_time',
    ]);

    $resolved = $this->service->resolveTemplate($profile, 'joiner');

    expect($resolved?->is($specific))->toBeTrue();
});

test('a joiner launch expands ordered and parallel work with teams approvals evidence due targets and minimum data', function () {
    Carbon::setTestNow('2026-07-19 09:00:00');
    $site = Site::factory()->create(['name' => 'Sunnyside']);
    $profile = jmlProfile($site);
    $team = ItTeam::factory()->create(['name' => 'Identity & Access']);
    $template = jmlTemplate('joiner', [
        [
            'task_key' => 'identity',
            'title' => 'Create identity and email',
            'category' => 'email',
            'request_type' => 'account',
            'responsible_team_id' => $team->id,
            'stage' => 1,
            'approval_required' => true,
            'evidence_required' => true,
            'due_offset_days' => -3,
        ],
        [
            'task_key' => 'licence',
            'title' => 'Assign approved licence',
            'category' => 'licence',
            'stage' => 2,
            'dependency_task_keys' => ['identity'],
        ],
        [
            'task_key' => 'wifi',
            'title' => 'Configure staff Wi-Fi',
            'category' => 'network',
            'request_type' => 'access',
            'stage' => 2,
            'dependency_task_keys' => ['identity'],
        ],
    ], ['position_role' => 'support_worker', 'site_id' => $site->id]);

    $effectiveAt = Carbon::parse('2026-08-01 09:00:00');
    $workflow = $this->service->launch(
        profile: $profile,
        lifecycleType: 'joiner',
        sourceType: 'hr_onboarding',
        sourceId: 501,
        sourceEventKey: 'onboarding:501:generated',
        actorId: $this->manager->id,
        effectiveAt: $effectiveAt,
    );

    expect($workflow->provisioning_template_id)->toBe($template->id)
        ->and($workflow->status)->toBe('pending')
        ->and($workflow->requests)->toHaveCount(3);

    $identity = $workflow->requests->firstWhere('task_key', 'identity');
    $licence = $workflow->requests->firstWhere('task_key', 'licence');
    $wifi = $workflow->requests->firstWhere('task_key', 'wifi');

    expect($identity->responsible_team_id)->toBe($team->id)
        ->and($identity->approval_status)->toBe('pending')
        ->and($identity->evidence_required)->toBeTrue()
        ->and($identity->due_date?->toDateString())->toBe('2026-07-29')
        ->and($licence->stage)->toBe(2)
        ->and($wifi->stage)->toBe(2)
        ->and($licence->dependency_request_ids)->toBe([$identity->id])
        ->and($wifi->dependency_request_ids)->toBe([$identity->id]);

    expect($identity->fulfiller_context)->toMatchArray([
        'employee_number' => $profile->employee_number,
        'work_email' => $profile->work_email,
        'position_role' => 'support_worker',
        'primary_site' => ['id' => $site->id, 'name' => 'Sunnyside'],
    ]);
    expect($identity->fulfiller_context)
        ->not->toHaveKeys(['date_of_birth', 'personal_email', 'home_address', 'bank_account']);
});

test('HR event replay is idempotent and never duplicates a workflow or its requests', function () {
    $profile = jmlProfile();
    jmlTemplate('joiner', [
        ['task_key' => 'account', 'title' => 'Create account'],
        ['task_key' => 'groups', 'title' => 'Assign groups', 'category' => 'group'],
    ]);

    $first = $this->service->launch(
        $profile, 'joiner', 'hr_onboarding', 88, 'onboarding:88:generated', $this->manager->id,
    );
    $second = $this->service->launch(
        $profile, 'joiner', 'hr_onboarding', 88, 'onboarding:88:generated', $this->manager->id,
    );

    expect($second->id)->toBe($first->id)
        ->and(ItProvisioningWorkflow::query()->count())->toBe(1)
        ->and(ItProvisioningRequest::query()->count())->toBe(2);
});

test('mover workflows include only deltas triggered by changed role site or employment fields', function () {
    $site = Site::factory()->create();
    $profile = jmlProfile($site, ['position_role' => 'team_lead']);
    jmlTemplate('mover', [
        [
            'task_key' => 'groups',
            'title' => 'Reconcile role groups',
            'category' => 'group',
            'action' => 'change',
            'trigger_fields' => ['position_role'],
        ],
        [
            'task_key' => 'door',
            'title' => 'Move door access',
            'category' => 'access_control',
            'action' => 'change',
            'request_type' => 'access',
            'trigger_fields' => ['primary_site_id'],
        ],
        [
            'task_key' => 'licence',
            'title' => 'Reconcile employment licence',
            'category' => 'licence',
            'action' => 'change',
            'trigger_fields' => ['employment_type'],
        ],
    ], ['position_role' => 'team_lead']);

    $workflow = $this->service->launch(
        profile: $profile,
        lifecycleType: 'mover',
        sourceType: 'hr_profile_update',
        sourceId: $profile->id,
        sourceEventKey: 'profile:'.$profile->id.':change-1',
        actorId: $this->manager->id,
        changes: [
            'position_role' => ['from' => 'support_worker', 'to' => 'team_lead'],
            'primary_site_id' => ['from' => null, 'to' => $site->id],
        ],
    );

    expect($workflow->requests->pluck('task_key')->all())->toBe(['groups', 'door'])
        ->and($workflow->changes)->toHaveKeys(['position_role', 'primary_site_id'])
        ->and($workflow->changes)->not->toHaveKeys(['bank_account', 'home_address']);
});

test('the canonical HR profile update launches one matching mover workflow', function () {
    $profile = jmlProfile();
    jmlTemplate('mover', [[
        'task_key' => 'role-access',
        'title' => 'Change access for new role',
        'category' => 'access_control',
        'action' => 'change',
        'request_type' => 'access',
        'trigger_fields' => ['position_role'],
    ]]);

    $this->actingAs($this->manager)
        ->put(route('hr.people.update', $profile), ['position_role' => 'team_lead'])
        ->assertSessionHas('success');

    $workflow = ItProvisioningWorkflow::query()->where('employee_profile_id', $profile->id)->firstOrFail();
    expect($workflow->source_type)->toBe('hr_profile_update')
        ->and($workflow->lifecycle_type)->toBe('mover')
        ->and($workflow->changes['position_role']['from'])->toBe('support_worker')
        ->and($workflow->changes['position_role']['to'])->toBe('team_lead')
        ->and($workflow->requests()->pluck('task_key')->all())->toBe(['role-access']);
});

test('dependencies approvals and evidence gate fulfilment and source HR completion', function () {
    $profile = jmlProfile();
    $template = jmlTemplate('joiner', [
        ['task_key' => 'account', 'title' => 'Create account'],
        [
            'task_key' => 'healthcare',
            'title' => 'Grant approved healthcare application access',
            'category' => 'healthcare_access',
            'request_type' => 'access',
            'dependency_task_keys' => ['account'],
            'approval_required' => true,
            'evidence_required' => true,
        ],
    ]);
    $checklist = HrOnboardingChecklist::query()->create([
        'employee_profile_id' => $profile->id,
        'template_key' => 'support_worker:all',
        'status' => 'in_progress',
        'started_at' => now(),
        'due_date' => now()->addDays(20),
        'created_by' => $this->manager->id,
    ]);
    $sourceTask = HrOnboardingTask::query()->create([
        'checklist_id' => $checklist->id,
        'category' => 'it',
        'title' => 'Grant approved healthcare application access',
        'is_required' => true,
        'sort_order' => 1,
        'status' => 'pending',
    ]);

    $workflow = $this->service->launchFromOnboarding($checklist, $this->manager->id);
    $account = $workflow->requests->firstWhere('task_key', 'account');
    $healthcare = $workflow->requests->firstWhere('task_key', 'healthcare');
    expect($workflow->provisioning_template_id)->toBe($template->id)
        ->and($healthcare->onboarding_task_id)->toBe($sourceTask->id);

    $this->actingAs($this->manager)
        ->post("/it/provisioning/{$healthcare->id}/fulfil")
        ->assertSessionHas('error', 'Complete this request’s dependencies first.');

    $this->actingAs($this->manager)
        ->post("/it/provisioning/{$account->id}/fulfil")
        ->assertSessionHas('success');

    $this->actingAs($this->manager)
        ->post("/it/provisioning/{$healthcare->id}/fulfil")
        ->assertSessionHas('error', 'This request needs approval before fulfilment.');

    $this->actingAs($this->manager)
        ->post("/it/provisioning/{$healthcare->id}/approve", ['decision_note' => 'Approved for the role.'])
        ->assertSessionHas('success');

    $this->actingAs($this->manager)
        ->post("/it/provisioning/{$healthcare->id}/fulfil")
        ->assertSessionHas('error', 'Record fulfilment evidence before completing this request.');

    $this->actingAs($this->manager)
        ->post("/it/provisioning/{$healthcare->id}/fulfil", [
            'evidence_summary' => 'IAM change CHG-1042 verified by second operator.',
        ])
        ->assertSessionHas('success');

    expect($healthcare->fresh()->status)->toBe('done')
        ->and($healthcare->fresh()->approval_status)->toBe('approved')
        ->and($sourceTask->fresh()->status)->toBe('completed')
        ->and($workflow->fresh()->status)->toBe('completed');
});

test('a failed fulfilment is explicit and marks the workflow partially failed without losing other work', function () {
    $profile = jmlProfile();
    jmlTemplate('joiner', [
        ['task_key' => 'email', 'title' => 'Create email'],
        ['task_key' => 'phone', 'title' => 'Configure telephony', 'category' => 'telephony'],
    ]);
    $workflow = $this->service->launch(
        $profile, 'joiner', 'hr_onboarding', 99, 'onboarding:99:generated', $this->manager->id,
    );
    $email = $workflow->requests->firstWhere('task_key', 'email');
    $phone = $workflow->requests->firstWhere('task_key', 'phone');

    $this->actingAs($this->manager)
        ->post("/it/provisioning/{$phone->id}/fail", ['failure_reason' => 'Provider API unavailable.'])
        ->assertSessionHas('success');

    expect($phone->fresh()->status)->toBe('failed')
        ->and($phone->fresh()->failure_reason)->toBe('Provider API unavailable.')
        ->and($workflow->fresh()->status)->toBe('partially_failed')
        ->and($email->fresh()->status)->toBe('pending');

    $this->actingAs($this->manager)
        ->get('/it?tab=provisioning&status=failed')
        ->assertInertia(fn ($page) => $page->where('summary.provisioning.failed', 1));
});

test('leaver launch creates reversal work and canonical asset and device recovery without duplicating ownership', function () {
    $site = Site::factory()->create();
    jmlAssignSite($this->manager, $site);
    $profile = jmlProfile($site);
    jmlTemplate('leaver', [
        [
            'task_key' => 'accounts',
            'title' => 'Revoke accounts and licences',
            'category' => 'account',
            'action' => 'revoke',
            'approval_required' => true,
            'evidence_required' => true,
        ],
        [
            'task_key' => 'doors',
            'title' => 'Revoke door credentials',
            'category' => 'access_control',
            'request_type' => 'access',
            'action' => 'revoke',
        ],
    ]);

    $asset = Asset::factory()->forSite($site)->create(['name' => 'Staff laptop']);
    $assetAssignment = AssetAssignment::query()->create([
        'asset_id' => $asset->id,
        'assignee_type' => 'staff',
        'assignee_id' => $profile->user_id,
        'purpose' => 'Work device',
        'assigned_at' => now()->subMonth(),
    ]);
    $device = Device::factory()->create(['name' => 'Staff safety handset']);
    $deviceAssignment = DeviceAssignment::query()->create([
        'device_id' => $device->id,
        'assignable_type' => DeviceAssignment::TARGET_STAFF,
        'assignable_id' => $profile->user_id,
        'assignment_type' => AssignmentType::Permanent,
        'assigned_at' => now()->subMonth(),
        'assigned_by_user_id' => $this->manager->id,
    ]);
    $checklist = HrOffboardingChecklist::query()->create([
        'employee_profile_id' => $profile->id,
        'template_key' => 'offboarding:support_worker',
        'status' => 'pending',
        'started_at' => now(),
        'due_date' => now()->addWeek(),
        'created_by' => $this->manager->id,
    ]);

    $workflow = $this->service->launchFromOffboarding($checklist, $this->manager->id);

    expect($workflow->requests->pluck('action')->all())->toContain('revoke', 'recover')
        ->and($workflow->requests->pluck('category')->all())->toContain('account', 'access_control', 'equipment')
        ->and($workflow->requests)->toHaveCount(4)
        ->and($assetAssignment->fresh()->released_at)->toBeNull()
        ->and($deviceAssignment->fresh()->released_at)->toBeNull();

    $assetRecovery = $workflow->requests->first(fn ($request) => $request->canonical_target_type === 'asset_assignment');
    $deviceRecovery = $workflow->requests->first(fn ($request) => $request->canonical_target_type === 'device_assignment');

    $this->actingAs($this->manager)
        ->post("/it/provisioning/{$assetRecovery->id}/fulfil", ['evidence_summary' => 'Laptop checked into stores.'])
        ->assertSessionHas('success');
    $this->actingAs($this->manager)
        ->post("/it/provisioning/{$deviceRecovery->id}/fulfil", ['evidence_summary' => 'Handset returned to pool.'])
        ->assertSessionHas('success');

    expect($assetAssignment->fresh()->released_at)->not->toBeNull()
        ->and($deviceAssignment->fresh()->released_at)->not->toBeNull()
        ->and(AssetAssignment::query()->where('asset_id', $asset->id)->count())->toBe(1)
        ->and(DeviceAssignment::query()->where('device_id', $device->id)->count())->toBe(1);
});

test('template administration is application-wide and the IT workspace exposes JML progress', function () {
    $team = ItTeam::factory()->create();

    $this->actingAs($this->manager)
        ->post('/it/setup/provisioning-templates', [
            'name' => 'Support worker joiner',
            'lifecycle_type' => 'joiner',
            'position_role' => 'support_worker',
            'site_id' => null,
            'employment_type' => 'full_time',
            'selection_priority' => 10,
            'is_active' => true,
            'tasks' => [[
                'task_key' => 'account',
                'title' => 'Create identity, email, groups and licence',
                'category' => 'email',
                'action' => 'grant',
                'request_type' => 'account',
                'responsible_team_id' => $team->id,
                'stage' => 1,
                'sort_order' => 1,
                'dependency_task_keys' => [],
                'trigger_fields' => [],
                'approval_required' => false,
                'evidence_required' => true,
                'due_offset_days' => -2,
                'fulfiller_fields' => ['employee_number', 'work_email', 'position_role', 'primary_site'],
            ]],
        ])
        ->assertRedirect()
        ->assertSessionHas('success');

    $template = ItProvisioningTemplate::query()->firstWhere('name', 'Support worker joiner');
    expect($template)->not->toBeNull()
        ->and($template->tasks)->toHaveCount(1)
        ->and($template->tasks->first()->responsible_team_id)->toBe($team->id);

    $profile = jmlProfile();
    $workflow = $this->service->launch(
        $profile, 'joiner', 'manual', 777, 'manual:777', $this->manager->id,
    );

    $this->actingAs($this->manager)
        ->get('/it?tab=provisioning')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('it/index')
            ->has('provisioningWorkflows', 1)
            ->where('provisioningWorkflows.0.lifecycle_type', 'joiner')
            ->where('provisioningWorkflows.0.progress.total', 1));

    $this->actingAs($this->manager)
        ->get('/it/setup')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('it/setup/index')
            ->has('provisioningTemplates', 1)
            ->where('provisioningTemplates.0.tasks.0.task_key', 'account'));

    $otherManager = jmlManager();

    $this->actingAs($otherManager)
        ->get('/it/setup')
        ->assertOk()
        ->assertInertia(fn ($page) => $page->has('provisioningTemplates', 1));

    $team->members()->attach($otherManager->id, ['role' => 'member']);
    $request = $workflow->requests()->sole();

    $this->actingAs($otherManager)
        ->post("/it/provisioning/{$request->id}/assign", [
            'assigned_to_user_id' => $otherManager->id,
        ])
        ->assertSessionHas('success');

    $this->actingAs($otherManager)
        ->get('/it?tab=provisioning')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('provisioningWorkflows', 1)
            ->where('provisioningWorkflows.0.id', $workflow->id));
});
