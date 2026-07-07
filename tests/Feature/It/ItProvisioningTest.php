<?php

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Domain\Hr\Models\HrOnboardingChecklist;
use App\Domain\Hr\Models\HrOnboardingTask;
use App\Domain\Hr\Models\HrOnboardingTemplate;
use App\Domain\Hr\Services\OnboardingService;
use App\Models\ItProvisioningRequest;
use App\Models\ItTicket;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RbacSeeder;

function itProfile(): HrEmployeeProfile
{
    $user = User::factory()->create();

    return HrEmployeeProfile::query()->create([
        'tenant_id' => 1,
        'user_id' => $user->id,
        'employee_number' => 'EMP-IT-'.$user->id,
        'work_email' => $user->email,
        'position_title' => 'Support Worker',
        'position_role' => 'support_worker',
        'employment_type' => 'full_time',
        'start_date' => now()->addDays(10)->toDateString(),
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

beforeEach(function () {
    $this->seed(RbacSeeder::class);
    $this->hr = User::factory()->create(['role' => 'hr', 'approved_at' => now()]);
    $this->hr->roles()->syncWithoutDetaching([
        Role::query()->where('name', 'hr')->first()->id,
    ]);
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

    $profile = itProfile();
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
    $profile = itProfile();
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

test('tickets can be created and resolved from the helpdesk queue', function () {
    $this->actingAs($this->hr)
        ->post('/it/tickets', [
            'title' => 'Printer offline — Sunnyside Lodge',
            'description' => 'Kyocera in the office is not responding.',
            'category' => 'hardware',
            'priority' => 'high',
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
