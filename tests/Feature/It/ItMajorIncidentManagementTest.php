<?php

use App\Models\ControlRoomAlert;
use App\Models\ItMajorIncident;
use App\Models\ItService;
use App\Models\ItTicket;
use App\Models\ItWorkTask;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use App\Notifications\It\MajorIncidentUpdateNotification;
use Database\Seeders\RbacSeeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;

function majorIncidentUser(string $role, int $tenantId = 1): User
{
    $user = User::factory()->create([
        'role' => $role,
        'approved_at' => now(),
        'organization_id' => $tenantId,
    ]);
    $user->roles()->syncWithoutDetaching([
        Role::query()->where('name', $role)->first()->id,
    ]);

    return $user;
}

beforeEach(function () {
    $this->seed(RbacSeeder::class);
    $this->commander = majorIncidentUser('hr');
    $this->communicationsLead = majorIncidentUser('hr');
    $this->requester = majorIncidentUser('support_worker');
});

afterEach(function () {
    Carbon::setTestNow();
});

test('an agent declares and finds a canonical major incident with command accountability', function () {
    $this->actingAs($this->commander)
        ->post('/it/major-incidents', [
            'title' => 'All-site identity outage',
            'description' => 'Staff cannot authenticate to critical systems.',
            'category' => 'account',
            'priority' => 'urgent',
            'severity' => 'sev1',
            'impact_summary' => 'Authentication is unavailable across all active sites.',
            'communications_lead_user_id' => $this->communicationsLead->id,
            'target_update_minutes' => 30,
        ])
        ->assertRedirect();

    $majorIncident = ItMajorIncident::query()->with('ticket')->firstOrFail();
    expect($majorIncident->ticket->reference)->toMatch('/^IT-\d{6}$/')
        ->and($majorIncident->ticket->work_type)->toBe('major_incident')
        ->and($majorIncident->ticket->workflow_state)->toBe('declared')
        ->and($majorIncident->ticket->status)->toBe('open')
        ->and($majorIncident->commander_user_id)->toBe($this->commander->id)
        ->and($majorIncident->communications_lead_user_id)->toBe($this->communicationsLead->id)
        ->and($majorIncident->declared_at)->not->toBeNull()
        ->and($majorIncident->next_update_due_at)->not->toBeNull()
        ->and($majorIncident->ticket->events()->where('type', 'created')->count())->toBe(1);

    $this->actingAs($this->commander)
        ->get('/it/major-incidents?severity=sev1&state=declared&q=identity')
        ->assertInertia(fn ($page) => $page
            ->component('it/major-incidents/index')
            ->has('majorIncidents.data', 1)
            ->where('majorIncidents.data.0.reference', $majorIncident->ticket->reference)
            ->where('majorIncidents.data.0.commander.name', $this->commander->name));
});

test('impacted services sites related incidents and the canonical Control Room alert use typed links', function () {
    $majorIncident = ItMajorIncident::factory()->create();
    $service = ItService::factory()->create();
    $site = Site::factory()->create();
    $alert = ControlRoomAlert::factory()->critical()->create(['site_id' => $site->id]);
    $incident = ItTicket::factory()->create([
        'tenant_id' => 1,
        'work_type' => 'incident',
        'requester_user_id' => $this->requester->id,
    ]);
    $alertCount = ControlRoomAlert::query()->count();

    $this->actingAs($this->commander)
        ->patch("/it/major-incidents/{$majorIncident->id}", [
            'service_ids' => [$service->id],
            'site_ids' => [$site->id],
            'incident_ids' => [$incident->id],
            'control_room_alert_id' => $alert->id,
        ])
        ->assertRedirect()
        ->assertSessionDoesntHaveErrors();

    expect($majorIncident->ticket->links()->where('relationship', 'affected_service')->count())->toBe(1)
        ->and($majorIncident->ticket->links()->where('relationship', 'affected_site')->count())->toBe(1)
        ->and($majorIncident->ticket->links()->where('relationship', 'related_incident')->count())->toBe(1)
        ->and($majorIncident->ticket->links()->where('relationship', 'source_alert')->count())->toBe(1)
        ->and($incident->links()->where('relationship', 'major_incident_member')->where('linkable_id', $majorIncident->ticket_id)->exists())->toBeTrue()
        ->and(ControlRoomAlert::query()->count())->toBe($alertCount);

    $this->actingAs($this->commander)
        ->getJson("/it/tickets/{$incident->id}")
        ->assertOk()
        ->assertJsonPath('linked_context.major_incidents.0.reference', $majorIncident->ticket->reference)
        ->assertJsonPath('linked_context.major_incidents.0.href', "/it/major-incidents/{$majorIncident->id}");
    $this->actingAs($this->requester)
        ->getJson("/it/tickets/{$incident->id}")
        ->assertOk()
        ->assertJsonCount(0, 'linked_context.major_incidents');
});

test('update cadence becomes overdue and audience safe communications notify affected requesters', function () {
    Notification::fake();
    Carbon::setTestNow('2026-07-19 10:00:00');
    $majorIncident = ItMajorIncident::factory()->create([
        'target_update_minutes' => 30,
        'next_update_due_at' => now()->subMinute(),
    ]);
    $incident = ItTicket::factory()->create([
        'tenant_id' => 1,
        'work_type' => 'incident',
        'requester_user_id' => $this->requester->id,
    ]);
    $this->actingAs($this->commander)
        ->patch("/it/major-incidents/{$majorIncident->id}", ['incident_ids' => [$incident->id]])
        ->assertRedirect();

    $this->actingAs($this->commander)
        ->get("/it/major-incidents/{$majorIncident->id}")
        ->assertInertia(fn ($page) => $page->where('majorIncident.update_state', 'overdue'));

    $this->actingAs($this->commander)
        ->post("/it/major-incidents/{$majorIncident->id}/updates", [
            'update_kind' => 'command_note',
            'audience' => 'internal',
            'summary' => 'Identity vendor bridge opened with privileged diagnostics.',
            'service_status' => 'investigating',
        ])
        ->assertRedirect();
    Notification::assertNothingSent();

    $this->actingAs($this->communicationsLead)
        ->post("/it/major-incidents/{$majorIncident->id}/updates", [
            'update_kind' => 'stakeholder_update',
            'audience' => 'staff',
            'summary' => 'Authentication remains unavailable. Use the emergency phone process.',
            'service_status' => 'major_outage',
        ])
        ->assertRedirect();
    Notification::assertSentTo($this->requester, MajorIncidentUpdateNotification::class);

    $majorIncident->refresh();
    expect($majorIncident->next_update_due_at?->equalTo(now()->addMinutes(30)))->toBeTrue();

    $this->actingAs($this->requester)
        ->getJson("/it/major-incidents/{$majorIncident->id}/status")
        ->assertOk()
        ->assertJsonCount(1, 'updates')
        ->assertJsonPath('updates.0.audience', 'staff')
        ->assertJsonMissing(['summary' => 'Identity vendor bridge opened with privileged diagnostics.']);
});

test('restoration resolution review and closure require explicit evidence', function () {
    $majorIncident = ItMajorIncident::factory()->create([
        'impact_summary' => 'Authentication is unavailable.',
    ]);

    $this->actingAs($this->commander)
        ->post("/it/major-incidents/{$majorIncident->id}/transitions", ['workflow_state' => 'responding', 'reason' => 'Command structure active.'])
        ->assertRedirect();
    $this->actingAs($this->commander)
        ->post("/it/major-incidents/{$majorIncident->id}/transitions", ['workflow_state' => 'restored', 'reason' => 'Try to restore without evidence.'])
        ->assertRedirect()
        ->assertSessionHas('error');

    $this->actingAs($this->commander)
        ->patch("/it/major-incidents/{$majorIncident->id}", ['restoration_summary' => 'Primary authentication restored; queued sessions drained.'])
        ->assertRedirect();
    $this->actingAs($this->commander)
        ->post("/it/major-incidents/{$majorIncident->id}/transitions", ['workflow_state' => 'restored', 'reason' => 'Service availability confirmed.'])
        ->assertRedirect();
    $this->actingAs($this->commander)
        ->post("/it/major-incidents/{$majorIncident->id}/transitions", [
            'workflow_state' => 'resolved',
            'reason' => 'Try to resolve before root cause.',
            'resolution_code' => 'service_restored',
            'resolution_summary' => 'Authentication restored.',
        ])
        ->assertRedirect()
        ->assertSessionHas('error');

    $this->actingAs($this->commander)
        ->patch("/it/major-incidents/{$majorIncident->id}", ['root_cause_summary' => 'Expired identity-provider signing key.'])
        ->assertRedirect();
    $this->actingAs($this->commander)
        ->post("/it/major-incidents/{$majorIncident->id}/transitions", [
            'workflow_state' => 'resolved',
            'reason' => 'Technical resolution confirmed.',
            'resolution_code' => 'service_restored',
            'resolution_summary' => 'Signing key replaced and authentication validated.',
        ])
        ->assertRedirect();
    $this->actingAs($this->commander)
        ->post("/it/major-incidents/{$majorIncident->id}/transitions", ['workflow_state' => 'review', 'reason' => 'Try review without PIR.'])
        ->assertRedirect()
        ->assertSessionHas('error');
    $this->actingAs($this->commander)
        ->patch("/it/major-incidents/{$majorIncident->id}", ['review_summary' => 'Key-expiry monitoring and automated rotation are required.'])
        ->assertRedirect();
    foreach (['review', 'closed'] as $state) {
        $this->actingAs($this->commander)
            ->post("/it/major-incidents/{$majorIncident->id}/transitions", ['workflow_state' => $state, 'reason' => "Move incident to {$state}."])
            ->assertRedirect();
    }

    $majorIncident->refresh();
    expect($majorIncident->ticket->workflow_state)->toBe('closed')
        ->and($majorIncident->restored_at)->not->toBeNull()
        ->and($majorIncident->reviewed_at)->not->toBeNull()
        ->and($majorIncident->ticket->closed_at)->not->toBeNull();
});

test('the live workspace reuses shared ticket work and exposes communications state', function () {
    $majorIncident = ItMajorIncident::factory()->create();
    $majorIncident->ticket->comments()->create([
        'tenant_id' => 1,
        'author_user_id' => $this->commander->id,
        'body' => 'Command bridge opened.',
        'is_internal' => true,
    ]);
    ItWorkTask::factory()->create(['tenant_id' => 1, 'ticket_id' => $majorIncident->ticket_id]);
    $majorIncident->updates()->create([
        'tenant_id' => 1,
        'update_kind' => 'stakeholder_update',
        'audience' => 'staff',
        'summary' => 'Investigation is active.',
        'service_status' => 'investigating',
        'published_at' => now(),
        'author_user_id' => $this->communicationsLead->id,
    ]);

    $this->actingAs($this->commander)
        ->get("/it/major-incidents/{$majorIncident->id}")
        ->assertInertia(fn ($page) => $page
            ->component('it/major-incidents/show')
            ->where('ticket.href', "/it/tickets/{$majorIncident->ticket_id}")
            ->where('ticket.comments_count', 1)
            ->where('ticket.tasks_count', 1)
            ->has('updates', 1)
            ->where('ticket.events_count', $majorIncident->ticket->events()->count()));
});

test('major incident command is agent only tenant concealed and rejects cross tenant impact', function () {
    $majorIncident = ItMajorIncident::factory()->create(['tenant_id' => 1]);

    $this->actingAs($this->requester)->get('/it/major-incidents')->assertForbidden();
    $this->actingAs($this->requester)
        ->patch("/it/major-incidents/{$majorIncident->id}", ['severity' => 'sev4'])
        ->assertForbidden();

    $foreignAgent = majorIncidentUser('hr', 2);
    $this->actingAs($foreignAgent)
        ->get("/it/major-incidents/{$majorIncident->id}")
        ->assertNotFound();

    $foreignService = ItService::factory()->create(['tenant_id' => 2]);
    $this->actingAs($this->commander)
        ->patch("/it/major-incidents/{$majorIncident->id}", ['service_ids' => [$foreignService->id]])
        ->assertRedirect()
        ->assertSessionHas('error');
});
