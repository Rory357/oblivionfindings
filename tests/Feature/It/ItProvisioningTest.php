<?php

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Domain\Hr\Models\HrOnboardingChecklist;
use App\Domain\Hr\Models\HrOnboardingTask;
use App\Domain\Hr\Models\HrOnboardingTemplate;
use App\Domain\Hr\Services\OnboardingService;
use App\Models\ItProvisioningRequest;
use App\Models\ItTicket;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use Database\Seeders\RbacSeeder;

function itProfile(Site $site, ?User $user = null, bool $current = false): HrEmployeeProfile
{
    $user ??= User::factory()->create();

    return HrEmployeeProfile::query()->create([
        'tenant_id' => 1,
        'user_id' => $user->id,
        'employee_number' => 'EMP-IT-'.$user->id,
        'work_email' => $user->email,
        'position_title' => 'Support Worker',
        'position_role' => 'support_worker',
        'employment_type' => 'full_time',
        'primary_site_id' => $site->id,
        'start_date' => ($current ? now()->subDays(10) : now()->addDays(10))->toDateString(),
        'is_active' => true,
    ]);
}

function itChecklist(HrEmployeeProfile $profile): HrOnboardingChecklist
{
    return HrOnboardingChecklist::query()->create([
        'tenant_id' => 1,
        'employee_profile_id' => $profile->id,
        'template_key' => 'support_worker:all',
        'status' => 'in_progress',
        'started_at' => now(),
        'due_date' => now()->addDays(20),
        'created_by' => $profile->user_id,
    ]);
}

function itProvisioningAgent(Site $site): User
{
    $user = User::factory()->create(['role' => 'hr', 'approved_at' => now()]);
    $user->roles()->syncWithoutDetaching([
        Role::query()->where('name', 'hr')->firstOrFail()->id,
    ]);
    itProfile($site, $user, true);

    return $user;
}

beforeEach(function () {
    $this->seed(RbacSeeder::class);
    $this->site = Site::factory()->create();
    $this->hr = User::factory()->create(['role' => 'hr', 'approved_at' => now()]);
    $this->hr->roles()->syncWithoutDetaching([
        Role::query()->where('name', 'hr')->first()->id,
    ]);
    itProfile($this->site, $this->hr, true);
    $this->svc = app(OnboardingService::class);
});

test('the /it hub is gated on it permissions', function () {
    // No role at all → no it.request/it.view → no page.
    $outsider = User::factory()->create(['approved_at' => now()]);
    $this->actingAs($outsider)->get('/it')->assertForbidden();

    // Self-service: support workers hold it.request and get the requester
    // view — their own tickets only, never the agent queues.
    $worker = User::factory()->create(['role' => 'support_worker', 'approved_at' => now()]);
    $worker->roles()->syncWithoutDetaching([
        Role::query()->where('name', 'support_worker')->first()->id,
    ]);

    $this->actingAs($worker)
        ->get('/it')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('it/index')
            ->has('myTickets')
            ->has('summary.my')
            ->missing('requests')
            ->missing('tickets')
            ->missing('summary.tickets')
            ->missing('summary.provisioning')
            ->missing('assignees')
            ->where('can.view', false)
            ->where('can.manage', false)
            ->where('can.request', true));

    $this->actingAs($this->hr)
        ->get('/it')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('it/index')
            ->has('requests')
            ->has('tickets')
            ->has('summary.tickets')
            ->has('summary.provisioning')
            ->has('myTickets')
            ->where('can.view', true)
            ->has('can.manage'));
});

test('generating a checklist raises provisioning requests for non-equipment IT tasks', function () {
    HrOnboardingTemplate::query()->create([
        'tenant_id' => 1,
        'role' => 'support_worker',
        'site_type' => 'all',
        'is_active' => true,
        'tasks' => [
            ['category' => 'it', 'title' => 'Create Microsoft 365 account', 'is_required' => true, 'sort_order' => 1],
            ['category' => 'it', 'title' => 'Door fob & site access', 'is_required' => true, 'sort_order' => 2],
            ['category' => 'it', 'title' => 'Issue laptop', 'is_required' => false, 'sort_order' => 3],
            ['category' => 'general', 'title' => 'Meet the team', 'is_required' => false, 'sort_order' => 4],
        ],
    ]);

    $profile = itProfile($this->site);
    $checklist = $this->svc->generateChecklist($profile, $this->hr->id);

    $requests = ItProvisioningRequest::query()
        ->where('employee_profile_id', $profile->id)
        ->get();

    // Account + access bridged; the equipment task keeps its asset path.
    expect($requests)->toHaveCount(2);
    expect($requests->pluck('type')->sort()->values()->all())->toBe(['access', 'account']);
    expect($requests->pluck('status')->unique()->all())->toBe(['pending']);

    $accountRequest = $requests->firstWhere('type', 'account');
    expect($accountRequest->item)->toBe('Create Microsoft 365 account');
    expect((int) $accountRequest->tenant_id)->toBe(1);
    expect($accountRequest->onboarding_task_id)->not->toBeNull();

    // Idempotent per task: one request per bridged onboarding task, even if
    // re-run. The equipment task keeps zero (asset path, never bridged).
    $bridgedTasks = $checklist->tasks
        ->where('category', 'it')
        ->reject(fn ($task) => str_contains(strtolower($task->title), 'laptop'));
    $equipmentTask = $checklist->tasks->firstWhere('title', 'Issue laptop');

    $this->svc->generateChecklist($profile, $this->hr->id);

    expect($bridgedTasks)->toHaveCount(2);
    foreach ($bridgedTasks as $task) {
        expect(ItProvisioningRequest::query()->where('onboarding_task_id', $task->id)->count())
            ->toBe(1);
    }
    expect(ItProvisioningRequest::query()->where('onboarding_task_id', $equipmentTask->id)->count())
        ->toBe(0);
});

test('fulfilling a request records the outcome and completes the linked onboarding task', function () {
    $profile = itProfile($this->site);
    $checklist = itChecklist($profile);
    $task = HrOnboardingTask::query()->create([
        'checklist_id' => $checklist->id,
        'category' => 'it',
        'title' => 'Create Microsoft 365 account',
        'is_required' => true,
        'sort_order' => 1,
        'status' => 'pending',
    ]);
    $request = ItProvisioningRequest::query()->create([
        'tenant_id' => 1,
        'employee_profile_id' => $profile->id,
        'onboarding_task_id' => $task->id,
        'type' => 'account',
        'item' => $task->title,
        'status' => 'pending',
        'created_by' => $this->hr->id,
    ]);

    $this->actingAs($this->hr)
        ->post("/it/provisioning/{$request->id}/fulfil", [
            'external_ref' => 'M365-0042',
            'notes' => 'Provisioned via admin centre.',
        ])
        ->assertRedirect();

    $request->refresh();
    expect($request->status)->toBe('done');
    expect($request->external_ref)->toBe('M365-0042');
    expect((int) $request->fulfilled_by)->toBe($this->hr->id);
    expect($request->fulfilled_at)->not->toBeNull();

    expect($task->fresh()->status)->toBe('completed');
    expect((int) $task->fresh()->completed_by)->toBe($this->hr->id);

    // A second fulfil is rejected without clobbering the record.
    $this->actingAs($this->hr)
        ->post("/it/provisioning/{$request->id}/fulfil", ['external_ref' => 'OTHER'])
        ->assertRedirect()
        ->assertSessionHas('error');
    expect($request->fresh()->external_ref)->toBe('M365-0042');
});

test('provisioning lists and direct actions conceal inaccessible Sites', function () {
    $localProfile = itProfile($this->site);
    $local = ItProvisioningRequest::query()->create([
        'tenant_id' => 1,
        'employee_profile_id' => $localProfile->id,
        'type' => 'account',
        'item' => 'Local email',
        'status' => 'pending',
    ]);
    $remoteSite = Site::factory()->create();
    $remoteProfile = itProfile($remoteSite);
    $remote = ItProvisioningRequest::query()->create([
        'tenant_id' => 1,
        'employee_profile_id' => $remoteProfile->id,
        'type' => 'account',
        'item' => 'Remote email',
        'status' => 'pending',
    ]);

    $this->actingAs($this->hr)
        ->get('/it?tab=provisioning')
        ->assertInertia(fn ($page) => $page
            ->where('requests.data.0.id', $local->id)
            ->has('requests.data', 1));

    $this->actingAs($this->hr)
        ->post("/it/provisioning/{$remote->id}/fulfil", ['notes' => 'Forged request'])
        ->assertNotFound();
    expect($remote->fresh()->status)->toBe('pending');
});

test('tickets can be created and resolved from the helpdesk queue', function () {
    $this->actingAs($this->hr)
        ->post('/it/tickets', [
            'title' => 'Printer offline — Sunnyside Lodge',
            'description' => 'Kyocera in the office is not responding.',
            'category' => 'hardware',
            'priority' => 'high',
            'site_id' => $this->site->id,
        ])
        ->assertRedirect();

    $ticket = ItTicket::query()->firstWhere('title', 'Printer offline — Sunnyside Lodge');
    expect($ticket)->not->toBeNull();
    expect($ticket->status)->toBe('open');
    expect((int) $ticket->requester_user_id)->toBe($this->hr->id);
    expect($ticket->priority)->toBe('high');

    $this->actingAs($this->hr)
        ->post("/it/tickets/{$ticket->id}/resolve", [
            'note' => 'Power-cycled the Kyocera and cleared the queue.',
        ])
        ->assertRedirect();

    $ticket->refresh();
    expect($ticket->status)->toBe('resolved');
    expect($ticket->resolved_at)->not->toBeNull();

    // Triage mutations stay gated on it.manage — a requester can raise
    // tickets (self-service) but never work the queue.
    $worker = User::factory()->create(['role' => 'support_worker', 'approved_at' => now()]);
    $worker->roles()->syncWithoutDetaching([
        Role::query()->where('name', 'support_worker')->first()->id,
    ]);
    $this->actingAs($worker)
        ->patch("/it/tickets/{$ticket->id}", ['status' => 'open'])
        ->assertForbidden();
    $this->actingAs($worker)
        ->post("/it/tickets/{$ticket->id}/resolve")
        ->assertForbidden();
});

test('provisioning assign, fulfil and cancel each write an activity event', function () {
    $profile = itProfile($this->site);
    $agent = itProvisioningAgent($this->site);

    $request = ItProvisioningRequest::query()->create([
        'tenant_id' => 1,
        'employee_profile_id' => $profile->id,
        'type' => 'account',
        'item' => 'Email account',
        'status' => 'pending',
    ]);

    // Assigning moves pending → in_progress and records an `assigned` event.
    $this->actingAs($this->hr)
        ->post("/it/provisioning/{$request->id}/assign", ['assigned_to_user_id' => $agent->id])
        ->assertRedirect();
    expect($request->refresh()->status)->toBe('in_progress');
    expect($request->events()->where('type', 'assigned')->count())->toBe(1);

    // Fulfilling records a `fulfilled` event (no onboarding task to complete here).
    $this->actingAs($this->hr)
        ->post("/it/provisioning/{$request->id}/fulfil", ['notes' => 'Provisioned'])
        ->assertRedirect();
    expect($request->events()->where('type', 'fulfilled')->count())->toBe(1);

    // Cancelling a fresh request records a `cancelled` event with the reason.
    $toCancel = ItProvisioningRequest::query()->create([
        'tenant_id' => 1,
        'employee_profile_id' => $profile->id,
        'type' => 'access',
        'item' => 'VPN access',
        'status' => 'pending',
    ]);
    $this->actingAs($this->hr)
        ->post("/it/provisioning/{$toCancel->id}/cancel", ['reason' => 'Duplicate'])
        ->assertRedirect();
    $cancelled = $toCancel->events()->where('type', 'cancelled')->first();
    expect($cancelled)->not->toBeNull();
    expect($cancelled->payload['reason'] ?? null)->toBe('Duplicate');
});

test('an agent raises a manual provisioning request; requesters cannot', function () {
    $profile = itProfile($this->site);
    $agent = itProvisioningAgent($this->site);

    // Assigned manual request → in_progress, with a `created` event.
    $this->actingAs($this->hr)
        ->post('/it/provisioning', [
            'employee_profile_id' => $profile->id,
            'type' => 'equipment',
            'item' => 'Replacement laptop',
            'assigned_to_user_id' => $agent->id,
            'priority' => 'high',
            'due_date' => now()->addDays(3)->toDateString(),
            'notes' => 'Old one cracked',
        ])
        ->assertRedirect();

    $req = ItProvisioningRequest::query()->firstWhere('item', 'Replacement laptop');
    expect($req)->not->toBeNull();
    expect($req->status)->toBe('in_progress');
    expect($req->priority)->toBe('high');
    expect((int) $req->assigned_to_user_id)->toBe($agent->id);
    expect($req->events()->where('type', 'created')->count())->toBe(1);

    // Unassigned manual request stays pending.
    $this->actingAs($this->hr)
        ->post('/it/provisioning', [
            'employee_profile_id' => $profile->id,
            'type' => 'account',
            'item' => 'Email setup',
            'priority' => 'normal',
        ])
        ->assertRedirect();
    expect(ItProvisioningRequest::query()->firstWhere('item', 'Email setup')->status)->toBe('pending');

    // Self-service requesters (no it.manage) cannot raise provisioning requests.
    $worker = User::factory()->create(['role' => 'support_worker', 'approved_at' => now()]);
    $worker->roles()->syncWithoutDetaching([
        Role::query()->where('name', 'support_worker')->first()->id,
    ]);
    itProfile($this->site, $worker, true);
    $this->actingAs($worker)
        ->post('/it/provisioning', [
            'employee_profile_id' => $profile->id,
            'type' => 'account',
            'item' => 'Nope',
            'priority' => 'normal',
        ])
        ->assertForbidden();
});

test('an agent raises a ticket linked to a provisioning request; requesters cannot link', function () {
    $profile = itProfile($this->site);
    $req = ItProvisioningRequest::query()->create([
        'tenant_id' => 1,
        'employee_profile_id' => $profile->id,
        'type' => 'equipment',
        'item' => 'Laptop',
        'status' => 'done',
    ]);

    // Agent links the ticket both ways.
    $this->actingAs($this->hr)
        ->post('/it/tickets', [
            'title' => 'Laptop arrived cracked',
            'category' => 'hardware',
            'priority' => 'high',
            'site_id' => $this->site->id,
            'provisioning_request_id' => $req->id,
        ])
        ->assertRedirect();

    $ticket = ItTicket::query()->firstWhere('title', 'Laptop arrived cracked');
    expect((int) $ticket->provisioning_request_id)->toBe($req->id);
    expect($req->linkedTickets()->whereKey($ticket->id)->exists())->toBeTrue();

    // Self-service requesters can raise tickets but never attach a provisioning
    // link — the agent-only field is dropped server-side.
    $worker = User::factory()->create(['role' => 'support_worker', 'approved_at' => now()]);
    $worker->roles()->syncWithoutDetaching([
        Role::query()->where('name', 'support_worker')->first()->id,
    ]);
    itProfile($this->site, $worker, true);
    $this->actingAs($worker)
        ->post('/it/tickets', [
            'title' => 'Requester link attempt',
            'category' => 'hardware',
            'priority' => 'normal',
            'provisioning_request_id' => $req->id,
        ])
        ->assertRedirect();
    expect(ItTicket::query()->firstWhere('title', 'Requester link attempt')->provisioning_request_id)->toBeNull();
});

test('an agent exports the provisioning queue as CSV; requesters cannot', function () {
    $profile = itProfile($this->site);
    ItProvisioningRequest::query()->create([
        'tenant_id' => 1, 'employee_profile_id' => $profile->id,
        'type' => 'account', 'item' => 'Email account', 'status' => 'pending', 'priority' => 'high',
    ]);
    // A CSV formula-injection payload in a user-controlled field is neutralised.
    ItProvisioningRequest::query()->create([
        'tenant_id' => 1, 'employee_profile_id' => $profile->id,
        'type' => 'other', 'item' => '=cmd|calc', 'status' => 'pending',
    ]);
    // A request from an inaccessible Site never leaks into the export.
    $remoteSite = Site::factory()->create();
    $remoteProfile = itProfile($remoteSite);
    ItProvisioningRequest::query()->create([
        'tenant_id' => 1, 'employee_profile_id' => $remoteProfile->id,
        'type' => 'account', 'item' => 'Remote Site secret', 'status' => 'pending',
    ]);

    $response = $this->actingAs($this->hr)->get('/it/provisioning/export');
    $response->assertOk();
    $response->assertDownload();
    $csv = $response->streamedContent();

    expect($csv)->toContain('Employee', 'Item', 'Status');
    expect($csv)->toContain('Email account');
    // Formula-injection guard: the leading `=` is prefixed with an apostrophe.
    expect($csv)->toContain("'=cmd|calc");
    expect($csv)->not->toContain(',=cmd|calc');
    // Site scope holds.
    expect($csv)->not->toContain('Remote Site secret');

    // Self-service requesters (no it.view) cannot export the agent queue.
    $worker = User::factory()->create(['role' => 'support_worker', 'approved_at' => now()]);
    $worker->roles()->syncWithoutDetaching([
        Role::query()->where('name', 'support_worker')->first()->id,
    ]);
    $this->actingAs($worker)->get('/it/provisioning/export')->assertForbidden();
});

test('the provisioning export respects the status filter', function () {
    $profile = itProfile($this->site);
    ItProvisioningRequest::query()->create([
        'tenant_id' => 1, 'employee_profile_id' => $profile->id,
        'type' => 'account', 'item' => 'Pending item', 'status' => 'pending',
    ]);
    ItProvisioningRequest::query()->create([
        'tenant_id' => 1, 'employee_profile_id' => $profile->id,
        'type' => 'account', 'item' => 'Done item', 'status' => 'done',
    ]);

    $csv = $this->actingAs($this->hr)
        ->get('/it/provisioning/export?status=pending')
        ->streamedContent();

    expect($csv)->toContain('Pending item');
    expect($csv)->not->toContain('Done item');
});
