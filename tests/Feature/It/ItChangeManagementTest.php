<?php

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Domain\SecurityDevices\Models\Device;
use App\Domain\SecurityDevices\Models\DeviceAssignment;
use App\Models\AuditLog;
use App\Models\ControlRoomAlert;
use App\Models\ItChange;
use App\Models\ItService;
use App\Models\ItTicket;
use App\Models\ItTicketApproval;
use App\Models\ItWorkTask;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Database\Seeders\SecurityDevicesPermissionsSeeder;

function changeUser(string $role, ?Site $site = null): User
{
    $user = User::factory()->create([
        'role' => $role,
        'approved_at' => now(),
    ]);
    $user->roles()->syncWithoutDetaching([
        Role::query()->where('name', $role)->first()->id,
    ]);

    if ($site) {
        HrEmployeeProfile::factory()->create([
            'user_id' => $user->id,
            'created_by' => $user->id,
            'updated_by' => $user->id,
            'primary_site_id' => $site->id,
            'secondary_site_ids' => [],
            'is_active' => true,
            'start_date' => now()->subMonth()->toDateString(),
            'end_date' => null,
        ]);
    }

    return $user;
}

/** @param array<string, mixed> $attributes */
function changeAtSite(string $type, Site $site, array $attributes = []): ItChange
{
    $factory = match ($type) {
        'standard' => ItChange::factory()->standard(),
        'emergency' => ItChange::factory()->emergency(),
        default => ItChange::factory()->normal(),
    };
    $change = $factory->create($attributes);
    $change->ticket()->update(['site_id' => $site->id]);

    return $change->refresh();
}

beforeEach(function () {
    $this->seed(RbacSeeder::class);
    $this->seed(SecurityDevicesPermissionsSeeder::class);
    $this->site = Site::factory()->create();
    $this->agent = changeUser('provider_manager', $this->site);
    $this->approver = changeUser('provider_manager', $this->site);
    $this->validator = changeUser('provider_manager', $this->site);
    $this->requester = changeUser('support_worker', $this->site);
});

test('agents create and find canonical standard normal and emergency changes', function (string $type, bool $requiresApproval) {
    $this->actingAs($this->agent)
        ->post('/it/changes', [
            'title' => ucfirst($type).' gateway change',
            'description' => 'Replace the site gateway configuration safely.',
            'category' => 'network',
            'priority' => $type === 'emergency' ? 'urgent' : 'high',
            'change_type' => $type,
            'risk_level' => $type === 'standard' ? 'low' : 'high',
            'impact_summary' => 'Site connectivity may briefly fail over.',
        ])
        ->assertRedirect();

    $change = ItChange::query()->with('ticket')->firstOrFail();
    expect($change->ticket->reference)->toMatch('/^IT-\d{6}$/')
        ->and($change->ticket->work_type)->toBe('change')
        ->and($change->ticket->workflow_state)->toBe('draft')
        ->and($change->ticket->status)->toBe('open')
        ->and($change->ticket->requires_approval)->toBe($requiresApproval)
        ->and($change->change_type)->toBe($type)
        ->and($change->ticket->events()->where('type', 'created')->count())->toBe(1)
        ->and(AuditLog::query()
            ->where('action', 'it.change.created')
            ->where('auditable_id', $change->ticket_id)
            ->where('meta->change_id', $change->id)
            ->exists())->toBeTrue();

    $this->actingAs($this->agent)
        ->get('/it/changes?type='.$type.'&state=draft&q=gateway')
        ->assertInertia(fn ($page) => $page
            ->component('it/changes/index')
            ->has('changes.data', 1)
            ->where('changes.data.0.reference', $change->ticket->reference));
})->with([
    'standard' => ['standard', false],
    'normal' => ['normal', true],
    'emergency' => ['emergency', true],
]);

test('a standard change follows assessed scheduled implemented validated reviewed and closed states', function () {
    $change = changeAtSite('standard', $this->site);

    $this->actingAs($this->agent)
        ->patch("/it/changes/{$change->id}", [
            'impact_summary' => 'One site will fail over for up to five minutes.',
            'implementation_plan' => 'Export configuration, apply tested policy, verify routes.',
            'validation_plan' => 'Check WAN, DNS, voice, and remote support probes.',
            'backout_plan' => 'Restore the signed configuration export.',
            'maintenance_starts_at' => now()->addHour()->toIso8601String(),
            'maintenance_ends_at' => now()->addHours(2)->toIso8601String(),
            'next_action' => 'Complete technical assessment.',
        ])
        ->assertRedirect();
    expect(AuditLog::query()
        ->where('action', 'it.change.updated')
        ->where('auditable_id', $change->ticket_id)
        ->exists())->toBeTrue();

    foreach (['assessment', 'approved', 'scheduled', 'implementing'] as $state) {
        $this->actingAs($this->agent)
            ->post("/it/changes/{$change->id}/transitions", [
                'workflow_state' => $state,
                'reason' => "Move change to {$state}.",
            ])
            ->assertRedirect()
            ->assertSessionDoesntHaveErrors();
    }

    $this->actingAs($this->agent)
        ->patch("/it/changes/{$change->id}", [
            'actual_outcome' => 'Policy applied and all services remained reachable.',
        ])
        ->assertRedirect();
    $this->actingAs($this->agent)
        ->post("/it/changes/{$change->id}/transitions", [
            'workflow_state' => 'validation',
            'reason' => 'Implementation completed; validate the outcome.',
        ])
        ->assertRedirect();
    $this->actingAs($this->validator)
        ->patch("/it/changes/{$change->id}", [
            'validation_result' => 'successful',
            'validation_summary' => 'WAN, DNS, voice, and remote support probes passed.',
            'pir_summary' => 'No customer impact and no follow-up actions required.',
        ])
        ->assertRedirect();
    $this->actingAs($this->validator)
        ->post("/it/changes/{$change->id}/transitions", [
            'workflow_state' => 'completed',
            'reason' => 'Independent validation passed.',
            'resolution_code' => 'successful_change',
            'resolution_summary' => 'Gateway policy changed and validated.',
        ])
        ->assertRedirect();
    foreach (['review', 'closed'] as $state) {
        $this->actingAs($this->validator)
            ->post("/it/changes/{$change->id}/transitions", [
                'workflow_state' => $state,
                'reason' => "Move change to {$state}.",
            ])
            ->assertRedirect();
    }

    $change->refresh();
    expect($change->ticket->workflow_state)->toBe('closed')
        ->and($change->implemented_by_user_id)->toBe($this->agent->id)
        ->and($change->validated_by_user_id)->toBe($this->validator->id)
        ->and($change->implemented_at)->not->toBeNull()
        ->and($change->validated_at)->not->toBeNull()
        ->and($change->reviewed_at)->not->toBeNull()
        ->and($change->ticket->closed_at)->not->toBeNull();
});

test('normal high risk and restricted changes require approval and independent validation', function () {
    $change = changeAtSite('normal', $this->site, [
        'risk_level' => 'critical',
        'is_restricted' => true,
        'implementation_plan' => 'Rotate privileged identity provider keys.',
        'validation_plan' => 'Validate sign-in through a non-implementer account.',
        'backout_plan' => 'Restore previous signing key and metadata.',
        'impact_summary' => 'All workforce sign-in could be affected.',
        'maintenance_starts_at' => now()->addHour(),
        'maintenance_ends_at' => now()->addHours(2),
    ]);

    $this->actingAs($this->agent)
        ->post("/it/changes/{$change->id}/transitions", ['workflow_state' => 'assessment', 'reason' => 'Assess risk.'])
        ->assertRedirect();
    $this->actingAs($this->agent)
        ->post("/it/changes/{$change->id}/transitions", ['workflow_state' => 'approved', 'reason' => 'Try to bypass approval.'])
        ->assertRedirect()
        ->assertSessionHas('error');

    $this->actingAs($this->agent)
        ->post("/it/tickets/{$change->ticket_id}/approvals", ['reason' => 'Critical identity change.'])
        ->assertRedirect();
    $approval = $change->ticket->approvals()->firstOrFail();
    $this->actingAs($this->agent)
        ->post("/it/approvals/{$approval->id}/decide", ['decision' => 'approve'])
        ->assertForbidden();
    $this->actingAs($this->approver)
        ->post("/it/approvals/{$approval->id}/decide", ['decision' => 'approve'])
        ->assertRedirect();

    foreach (['approved', 'scheduled', 'implementing'] as $state) {
        $this->actingAs($this->agent)
            ->post("/it/changes/{$change->id}/transitions", ['workflow_state' => $state, 'reason' => "Move to {$state}."])
            ->assertRedirect();
    }
    $this->actingAs($this->agent)
        ->patch("/it/changes/{$change->id}", ['actual_outcome' => 'New key active; old key retained for backout.'])
        ->assertRedirect();
    $this->actingAs($this->agent)
        ->post("/it/changes/{$change->id}/transitions", ['workflow_state' => 'validation', 'reason' => 'Ready for independent validation.'])
        ->assertRedirect();
    $this->actingAs($this->agent)
        ->patch("/it/changes/{$change->id}", [
            'validation_result' => 'successful',
            'validation_summary' => 'Implementer reports sign-in success.',
        ])
        ->assertRedirect();
    $this->actingAs($this->agent)
        ->post("/it/changes/{$change->id}/transitions", [
            'workflow_state' => 'completed',
            'reason' => 'Self-validation attempt.',
            'resolution_code' => 'successful_change',
            'resolution_summary' => 'Identity keys rotated.',
        ])
        ->assertRedirect()
        ->assertSessionHas('error');
    $this->actingAs($this->approver)
        ->post("/it/changes/{$change->id}/transitions", [
            'workflow_state' => 'completed',
            'reason' => 'Approver validation attempt.',
            'resolution_code' => 'successful_change',
            'resolution_summary' => 'Identity keys rotated.',
        ])
        ->assertRedirect()
        ->assertSessionHas('error');
    $this->actingAs($this->validator)
        ->post("/it/changes/{$change->id}/transitions", [
            'workflow_state' => 'completed',
            'reason' => 'Independent validation passed.',
            'resolution_code' => 'successful_change',
            'resolution_summary' => 'Identity keys rotated and independently validated.',
        ])
        ->assertRedirect()
        ->assertSessionDoesntHaveErrors();

    expect($change->fresh()->validated_by_user_id)->toBe($this->validator->id);
});

test('emergency changes still need approval and failed implementations can be backed out and reviewed', function () {
    $change = changeAtSite('emergency', $this->site, [
        'implementation_plan' => 'Apply vendor mitigation immediately.',
        'validation_plan' => 'Confirm exploit traffic is blocked.',
        'backout_plan' => 'Remove mitigation and restore previous policy.',
        'impact_summary' => 'Active exploitation threatens all sites.',
    ]);
    ItTicketApproval::query()->create([
        'it_ticket_id' => $change->ticket_id,
        'requested_by' => $this->agent->id,
        'approver_id' => $this->approver->id,
        'status' => 'approved',
        'decided_at' => now(),
    ]);

    foreach (['assessment', 'approved', 'implementing'] as $state) {
        $this->actingAs($this->agent)
            ->post("/it/changes/{$change->id}/transitions", ['workflow_state' => $state, 'reason' => "Emergency move to {$state}."])
            ->assertRedirect();
    }
    $this->actingAs($this->agent)
        ->patch("/it/changes/{$change->id}", [
            'actual_outcome' => 'Vendor mitigation interrupted the identity callback.',
            'backout_summary' => 'Previous policy restored in three minutes.',
            'pir_summary' => 'Mitigation rejected; vendor escalation and new test case opened.',
        ])
        ->assertRedirect();
    $this->actingAs($this->agent)
        ->post("/it/changes/{$change->id}/transitions", ['workflow_state' => 'backed_out', 'reason' => 'Mitigation caused regression.'])
        ->assertRedirect();
    $this->actingAs($this->agent)
        ->post("/it/changes/{$change->id}/transitions", ['workflow_state' => 'review', 'reason' => 'Review failed emergency change.'])
        ->assertRedirect();

    $change->refresh();
    expect($change->ticket->workflow_state)->toBe('review')
        ->and($change->backed_out_at)->not->toBeNull()
        ->and($change->reviewed_at)->not->toBeNull();
});

test('affected services Sites devices alerts incidents and problems use canonically scoped typed links', function () {
    $change = changeAtSite('standard', $this->site);
    $service = ItService::factory()->create();
    $site = $this->site;
    $device = Device::factory()->itInfrastructure()->create();
    DeviceAssignment::query()->create([
        'device_id' => $device->id,
        'assignable_type' => DeviceAssignment::TARGET_SITE,
        'assignable_id' => $site->id,
        'assignment_type' => 'permanent',
        'assigned_at' => now(),
        'assigned_by_user_id' => $this->agent->id,
    ]);
    $alert = ControlRoomAlert::factory()->create(['site_id' => $site->id]);
    $incident = ItTicket::factory()->create(['site_id' => $site->id, 'work_type' => 'incident']);
    $problem = ItTicket::factory()->create(['site_id' => $site->id, 'work_type' => 'problem']);

    $this->actingAs($this->agent)
        ->patch("/it/changes/{$change->id}", [
            'service_ids' => [$service->id],
            'site_ids' => [$site->id],
            'device_ids' => [$device->id],
            'alert_ids' => [$alert->id],
            'incident_ids' => [$incident->id],
            'problem_ids' => [$problem->id],
        ])
        ->assertRedirect()
        ->assertSessionDoesntHaveErrors();

    expect($change->ticket->links()->where('relationship', 'affected_service')->count())->toBe(1)
        ->and($change->ticket->links()->where('relationship', 'affected_site')->count())->toBe(1)
        ->and($change->ticket->links()->where('relationship', 'affected_device')->count())->toBe(1)
        ->and($change->ticket->links()->where('relationship', 'source_alert')->count())->toBe(1)
        ->and($change->ticket->links()->where('relationship', 'related_incident')->count())->toBe(1)
        ->and($change->ticket->links()->where('relationship', 'related_problem')->count())->toBe(1)
        ->and($incident->links()->where('relationship', 'related_change')->where('linkable_id', $change->ticket_id)->exists())->toBeTrue()
        ->and($problem->links()->where('relationship', 'related_change')->where('linkable_id', $change->ticket_id)->exists())->toBeTrue();

    $this->actingAs($this->agent)
        ->getJson("/it/tickets/{$incident->id}")
        ->assertOk()
        ->assertJsonPath('linked_context.changes.0.reference', $change->ticket->reference)
        ->assertJsonPath('linked_context.changes.0.change_type', 'standard')
        ->assertJsonPath('linked_context.changes.0.href', "/it/changes/{$change->id}");

    $incident->update(['requester_user_id' => $this->requester->id]);
    $this->actingAs($this->requester)
        ->getJson("/it/tickets/{$incident->id}")
        ->assertOk()
        ->assertJsonCount(0, 'linked_context.changes');

    $this->actingAs($this->agent)
        ->get("/it/changes/{$change->id}")
        ->assertInertia(fn ($page) => $page
            ->component('it/changes/show')
            ->has('links.services', 1)
            ->has('links.sites', 1)
            ->has('links.devices', 1)
            ->has('links.alerts', 1)
            ->has('links.incidents', 1)
            ->has('links.problems', 1));

    $inactiveService = ItService::factory()->create(['is_active' => false]);
    $this->actingAs($this->agent)
        ->patch("/it/changes/{$change->id}", ['service_ids' => [$inactiveService->id]])
        ->assertRedirect()
        ->assertSessionHas('error');
});

test('the change workspace reuses shared ticket work and is agent only site concealed', function () {
    $change = changeAtSite('normal', $this->site);
    $change->ticket->comments()->create([
        'author_user_id' => $this->agent->id,
        'body' => 'Assessment started.',
        'is_internal' => true,
    ]);
    ItWorkTask::factory()->create(['ticket_id' => $change->ticket_id]);

    $this->actingAs($this->agent)
        ->get("/it/changes/{$change->id}")
        ->assertInertia(fn ($page) => $page
            ->component('it/changes/show')
            ->where('ticket.href', "/it/tickets/{$change->ticket_id}")
            ->where('ticket.comments_count', 1)
            ->where('ticket.tasks_count', 1)
            ->where('ticket.events_count', $change->ticket->events()->count()));

    $this->actingAs($this->requester)->get('/it/changes')->assertForbidden();
    $this->actingAs($this->requester)
        ->patch("/it/changes/{$change->id}", ['risk_level' => 'low'])
        ->assertForbidden();

    $otherSite = Site::factory()->create();
    $remoteSiteAgent = changeUser('provider_manager', $otherSite);
    $this->actingAs($remoteSiteAgent)
        ->get("/it/changes/{$change->id}")
        ->assertNotFound();
});
