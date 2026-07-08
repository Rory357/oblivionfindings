<?php

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Domain\Hr\Models\HrOnboardingChecklist;
use App\Domain\Hr\Models\HrOnboardingTask;
use App\Models\ItProvisioningRequest;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RbacSeeder;

function provBulkUser(string $role): User
{
    $user = User::factory()->create(['role' => $role, 'approved_at' => now()]);
    $user->roles()->syncWithoutDetaching([
        Role::query()->where('name', $role)->first()->id,
    ]);

    return $user;
}

function provBulkProfile(): HrEmployeeProfile
{
    $user = User::factory()->create();

    return HrEmployeeProfile::query()->create([
        'tenant_id' => 1,
        'user_id' => $user->id,
        'employee_number' => 'EMP-PB-'.$user->id,
        'work_email' => $user->email,
        'position_title' => 'Support Worker',
        'position_role' => 'support_worker',
        'employment_type' => 'full_time',
        'start_date' => now()->addDays(10)->toDateString(),
        'is_active' => true,
    ]);
}

beforeEach(function () {
    $this->seed(RbacSeeder::class);
    $this->hr = provBulkUser('hr');
    $this->agent = provBulkUser('provider_manager');
    $this->worker = provBulkUser('support_worker');
});

test('bulk assign moves pending requests to in progress and records an event', function () {
    $profile = provBulkProfile();
    $pendingA = ItProvisioningRequest::query()->create([
        'tenant_id' => 1, 'employee_profile_id' => $profile->id,
        'type' => 'account', 'item' => 'Email account', 'status' => 'pending',
    ]);
    $pendingB = ItProvisioningRequest::query()->create([
        'tenant_id' => 1, 'employee_profile_id' => $profile->id,
        'type' => 'access', 'item' => 'VPN access', 'status' => 'pending',
    ]);
    // A settled request in the same batch keeps its history.
    $done = ItProvisioningRequest::query()->create([
        'tenant_id' => 1, 'employee_profile_id' => $profile->id,
        'type' => 'equipment', 'item' => 'Laptop', 'status' => 'done',
    ]);

    $this->actingAs($this->hr)
        ->post('/it/provisioning/bulk', [
            'ids' => [$pendingA->id, $pendingB->id, $done->id],
            'action' => 'assign',
            'assigned_to_user_id' => $this->agent->id,
        ])
        ->assertRedirect()
        ->assertSessionHas('success', '2 request(s) assigned · 1 unchanged.');

    foreach ([$pendingA, $pendingB] as $r) {
        $r->refresh();
        expect($r->status)->toBe('in_progress');
        expect((int) $r->assigned_to_user_id)->toBe($this->agent->id);
        expect($r->events()->where('type', 'assigned')->count())->toBe(1);
    }
    expect($done->refresh()->status)->toBe('done');
    expect($done->assigned_to_user_id)->toBeNull();
    expect($done->events()->where('type', 'assigned')->count())->toBe(0);

    // Re-running the same selection changes nothing (already assigned + settled).
    $this->actingAs($this->hr)->post('/it/provisioning/bulk', [
        'ids' => [$pendingA->id, $pendingB->id, $done->id],
        'action' => 'assign',
        'assigned_to_user_id' => $this->agent->id,
    ])->assertSessionHas('success', '0 request(s) assigned · 3 unchanged.');
});

test('bulk fulfil marks requests done and completes the linked onboarding task', function () {
    $profile = provBulkProfile();
    $checklist = HrOnboardingChecklist::query()->create([
        'tenant_id' => 1,
        'employee_profile_id' => $profile->id,
        'template_key' => 'support_worker:all',
        'status' => 'in_progress',
        'started_at' => now(),
        'due_date' => now()->addDays(20),
        'created_by' => $this->hr->id,
    ]);
    $task = HrOnboardingTask::query()->create([
        'checklist_id' => $checklist->id,
        'category' => 'it',
        'title' => 'Create Microsoft 365 account',
        'is_required' => true,
        'sort_order' => 1,
        'status' => 'pending',
    ]);
    $linked = ItProvisioningRequest::query()->create([
        'tenant_id' => 1, 'employee_profile_id' => $profile->id,
        'onboarding_task_id' => $task->id, 'type' => 'account',
        'item' => $task->title, 'status' => 'in_progress', 'created_by' => $this->hr->id,
    ]);
    // Manual request (no onboarding task) fulfils too.
    $manual = ItProvisioningRequest::query()->create([
        'tenant_id' => 1, 'employee_profile_id' => $profile->id,
        'type' => 'access', 'item' => 'VPN access', 'status' => 'in_progress',
    ]);
    // Cancelled request is skipped, never re-opened.
    $cancelled = ItProvisioningRequest::query()->create([
        'tenant_id' => 1, 'employee_profile_id' => $profile->id,
        'type' => 'other', 'item' => 'Old kit', 'status' => 'cancelled',
    ]);

    $this->actingAs($this->hr)
        ->post('/it/provisioning/bulk', [
            'ids' => [$linked->id, $manual->id, $cancelled->id],
            'action' => 'fulfil',
        ])
        ->assertRedirect()
        ->assertSessionHas('success', '2 request(s) fulfilled · 1 unchanged.');

    foreach ([$linked, $manual] as $r) {
        $r->refresh();
        expect($r->status)->toBe('done');
        expect($r->fulfilled_at)->not->toBeNull();
        expect((int) $r->fulfilled_by)->toBe($this->hr->id);
        expect($r->events()->where('type', 'fulfilled')->count())->toBe(1);
    }
    // Cross-loop bridge: the linked onboarding task is completed by fulfilment.
    expect($task->fresh()->status)->toBe('completed');
    expect((int) $task->fresh()->completed_by)->toBe($this->hr->id);
    // Cancelled request untouched.
    expect($cancelled->refresh()->status)->toBe('cancelled');
});

test('provisioning bulk is agent-only and tenant-scoped', function () {
    $profile = provBulkProfile();
    $mine = ItProvisioningRequest::query()->create([
        'tenant_id' => 1, 'employee_profile_id' => $profile->id,
        'type' => 'account', 'item' => 'Email', 'status' => 'pending',
    ]);
    $foreign = ItProvisioningRequest::query()->create([
        'tenant_id' => 2, 'employee_profile_id' => $profile->id,
        'type' => 'account', 'item' => 'Foreign email', 'status' => 'pending',
    ]);

    // Self-service requesters (no it.manage) cannot bulk-act.
    $this->actingAs($this->worker)->post('/it/provisioning/bulk', [
        'ids' => [$mine->id],
        'action' => 'assign',
        'assigned_to_user_id' => $this->agent->id,
    ])->assertForbidden();

    // A foreign-tenant id silently drops out of the tenant-scoped fetch.
    $this->actingAs($this->hr)->post('/it/provisioning/bulk', [
        'ids' => [$mine->id, $foreign->id],
        'action' => 'assign',
        'assigned_to_user_id' => $this->agent->id,
    ])->assertSessionHas('success', '1 request(s) assigned · 1 unchanged.');

    expect($mine->refresh()->status)->toBe('in_progress');
    expect($foreign->refresh()->status)->toBe('pending'); // other tenant untouched
    expect($foreign->assigned_to_user_id)->toBeNull();
});
