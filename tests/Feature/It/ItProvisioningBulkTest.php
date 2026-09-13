<?php

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Domain\Hr\Models\HrOnboardingChecklist;
use App\Domain\Hr\Models\HrOnboardingTask;
use App\Models\ItProvisioningRequest;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Illuminate\Support\Facades\Event;

function provBulkUser(string $role): User
{
    $user = User::factory()->create(['role' => $role, 'approved_at' => now()]);
    $user->roles()->syncWithoutDetaching([
        Role::query()->where('name', $role)->first()->id,
    ]);

    return $user;
}

function provBulkProfile(Site $site, ?User $user = null, bool $current = false): HrEmployeeProfile
{
    $user ??= User::factory()->create();

    return HrEmployeeProfile::factory()->create([
        'user_id' => $user->id,
        'employee_number' => 'EMP-PB-'.$user->id,
        'work_email' => $user->email,
        'position_title' => 'Support Worker',
        'position_role' => 'support_worker',
        'employment_type' => 'full_time',
        'primary_site_id' => $site->id,
        'start_date' => ($current ? now()->subDays(10) : now()->addDays(10))->toDateString(),
        'is_active' => true,
    ]);
}

beforeEach(function () {
    $this->seed(RbacSeeder::class);
    $this->site = Site::factory()->create();
    $this->hr = provBulkUser('hr');
    $this->agent = provBulkUser('provider_manager');
    $this->worker = provBulkUser('support_worker');
    provBulkProfile($this->site, $this->hr, true);
    provBulkProfile($this->site, $this->agent, true);
    provBulkProfile($this->site, $this->worker, true);
});

test('bulk assign moves pending requests to in progress and records an event', function () {
    $profile = provBulkProfile($this->site);
    $pendingA = ItProvisioningRequest::query()->create([
        'employee_profile_id' => $profile->id,
        'type' => 'account', 'item' => 'Email account', 'status' => 'pending',
    ]);
    $pendingB = ItProvisioningRequest::query()->create([
        'employee_profile_id' => $profile->id,
        'type' => 'access', 'item' => 'VPN access', 'status' => 'pending',
    ]);
    // A settled request in the same batch keeps its history.
    $done = ItProvisioningRequest::query()->create([
        'employee_profile_id' => $profile->id,
        'type' => 'equipment', 'item' => 'Laptop', 'status' => 'done',
    ]);

    $this->actingAs($this->hr)
        ->post('/it/provisioning/bulk', [
            'ids' => [$pendingA->id, $pendingB->id, $done->id],
            'action' => 'assign',
            'assigned_to_user_id' => $this->agent->id,
        ])
        ->assertRedirect()
        ->assertSessionHas('warning', '2 request(s) assigned · 1 unchanged.');

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
    ])->assertSessionHas('warning', '0 request(s) assigned · 3 unchanged.')
        ->assertSessionMissing('success');
});

test('bulk fulfil marks requests done and completes the linked onboarding task', function () {
    $profile = provBulkProfile($this->site);
    $checklist = HrOnboardingChecklist::query()->create([
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
        'employee_profile_id' => $profile->id,
        'onboarding_task_id' => $task->id, 'type' => 'account',
        'item' => $task->title, 'status' => 'in_progress', 'created_by' => $this->hr->id,
    ]);
    // Manual request (no onboarding task) fulfils too.
    $manual = ItProvisioningRequest::query()->create([
        'employee_profile_id' => $profile->id,
        'type' => 'access', 'item' => 'VPN access', 'status' => 'in_progress',
    ]);
    // Cancelled request is skipped, never re-opened.
    $cancelled = ItProvisioningRequest::query()->create([
        'employee_profile_id' => $profile->id,
        'type' => 'other', 'item' => 'Old kit', 'status' => 'cancelled',
    ]);

    $this->actingAs($this->hr)
        ->post('/it/provisioning/bulk', [
            'ids' => [$linked->id, $manual->id, $cancelled->id],
            'action' => 'fulfil',
        ])
        ->assertRedirect()
        ->assertSessionHas('warning', '2 request(s) fulfilled · 1 unchanged.');

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

test('provisioning bulk is agent-only and Site-scoped', function () {
    $profile = provBulkProfile($this->site);
    $mine = ItProvisioningRequest::query()->create([
        'employee_profile_id' => $profile->id,
        'type' => 'account', 'item' => 'Email', 'status' => 'pending',
    ]);
    $remoteSite = Site::factory()->create();
    $remoteProfile = provBulkProfile($remoteSite);
    $remote = ItProvisioningRequest::query()->create([
        'employee_profile_id' => $remoteProfile->id,
        'type' => 'account', 'item' => 'Remote Site email', 'status' => 'pending',
    ]);

    // Self-service requesters (no it.manage) cannot bulk-act.
    $this->actingAs($this->worker)->post('/it/provisioning/bulk', [
        'ids' => [$mine->id],
        'action' => 'assign',
        'assigned_to_user_id' => $this->agent->id,
    ])->assertForbidden();

    // An inaccessible Site id silently drops out of the canonical fetch.
    $this->actingAs($this->hr)->post('/it/provisioning/bulk', [
        'ids' => [$mine->id, $remote->id],
        'action' => 'assign',
        'assigned_to_user_id' => $this->agent->id,
    ])->assertSessionHas('warning', '1 request(s) assigned · 1 unchanged.');

    expect($mine->refresh()->status)->toBe('in_progress');
    expect($remote->refresh()->status)->toBe('pending');
    expect($remote->assigned_to_user_id)->toBeNull();
});

test('provisioning bulk outcomes separate blockers from unavailable records without disclosing private context', function () {
    $profile = provBulkProfile($this->site);
    $pending = ItProvisioningRequest::query()->create(['employee_profile_id' => $profile->id, 'type' => 'account', 'item' => 'Account', 'status' => 'pending']);
    $blocked = ItProvisioningRequest::query()->create(['employee_profile_id' => $profile->id, 'type' => 'equipment', 'item' => 'Device', 'status' => 'pending', 'evidence_required' => true]);
    $outside = ItProvisioningRequest::query()->create([
        'employee_profile_id' => provBulkProfile(Site::factory()->create())->id,
        'type' => 'access', 'item' => 'Private outside item', 'status' => 'pending',
    ]);
    $missingId = $outside->id + 10000;
    $ids = [$missingId, $pending->id, $blocked->id, $outside->id];
    $response = $this->actingAs($this->hr)->postJson('/it/provisioning/bulk', ['ids' => $ids, 'action' => 'fulfil'])
        ->assertOk()->assertJsonPath('result.selected', 4)->assertJsonPath('result.updated', 1)
        ->assertJsonPath('result.rejected', 3);
    $items = $response->json('result.items');
    expect(array_column($items, 'id'))->toBe($ids)
        ->and(array_column($items, 'status'))->toBe(['unavailable', 'updated', 'blocked', 'unavailable'])
        ->and($items[0]['message'])->toBe($items[3]['message'])
        ->and($response->getContent())->not->toContain('Private outside item')
        ->and($blocked->fresh()->status)->toBe('pending')
        ->and($outside->events()->count())->toBe(0);
    foreach ($items as $item) {
        expect(array_keys($item))->toBe(['id', 'status', 'message']);
    }
    $this->postJson('/it/provisioning/bulk', ['ids' => [$pending->id, $pending->id], 'action' => 'fulfil'])
        ->assertUnprocessable()->assertJsonValidationErrors('ids.0');
});

test('provisioning bulk rechecks lost actor access between locked item writes', function () {
    $profile = provBulkProfile($this->site);
    $first = ItProvisioningRequest::query()->create(['employee_profile_id' => $profile->id, 'type' => 'account', 'item' => 'First', 'status' => 'pending']);
    $next = ItProvisioningRequest::query()->create(['employee_profile_id' => $profile->id, 'type' => 'account', 'item' => 'Next', 'status' => 'pending']);
    $permission = Permission::query()->where('key', 'it.manage')->firstOrFail();
    Event::listen('eloquent.updated: '.ItProvisioningRequest::class, function (ItProvisioningRequest $request) use ($first, $permission): void {
        if ($request->id === $first->id) {
            $this->hr->permissionOverrides()->syncWithoutDetaching([$permission->id => ['allowed' => false]]);
        }
    });
    $this->actingAs($this->hr)->postJson('/it/provisioning/bulk', [
        'ids' => [$first->id, $next->id], 'action' => 'assign', 'assigned_to_user_id' => $this->agent->id,
    ])->assertOk()->assertJsonPath('result.items.0.status', 'updated')->assertJsonPath('result.items.1.status', 'unavailable');
    expect($first->fresh()->status)->toBe('in_progress')->and($next->fresh()->status)->toBe('pending')
        ->and($next->events()->count())->toBe(0);
});
