<?php

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Jobs\DispatchIncidentLifecycleSignalOutbox;
use App\Models\AuditLog;
use App\Models\Client;
use App\Models\ClientIncident;
use App\Models\ControlRoom\EvidencePack;
use App\Models\ControlRoom\Shift;
use App\Models\ControlRoom\SlaDefinition;
use App\Models\ControlRoomAlert;
use App\Models\HsEvent;
use App\Models\IncidentLifecycleSignal;
use App\Models\IncidentLifecycleSignalOutbox;
use App\Models\MedicationError;
use App\Models\Shift as ClinicalShift;
use App\Models\Site;
use App\Models\User;
use App\Services\ControlRoom\ControlRoomAlertLifecycleService;
use App\Services\ControlRoom\ControlRoomHandoverScopeService;
use App\Services\HealthSafety\HsEventClosureService;
use Database\Seeders\IncidentHandoverE2ESeeder;
use Database\Seeders\RbacSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

it('seeds one bounded seven-persona incident handover fixture without active duplicates', function () {
    $this->seed(RbacSeeder::class);
    $this->seed(IncidentHandoverE2ESeeder::class);

    $emails = [
        'incident-e2e-operator@demo.test',
        'incident-e2e-reviewer@demo.test',
        'incident-e2e-owner@demo.test',
        'incident-e2e-action-owner@demo.test',
        'incident-e2e-verifier@demo.test',
        'incident-e2e-incoming@demo.test',
        'incident-e2e-worker@demo.test',
    ];
    $firstUserIds = User::query()
        ->whereIn('email', $emails)
        ->pluck('id', 'email');

    expect($firstUserIds)->toHaveCount(7)
        ->and($firstUserIds->unique())->toHaveCount(7);
    $worker = User::query()->findOrFail(
        $firstUserIds['incident-e2e-worker@demo.test'],
    );
    expect($worker->canDo('hazards.view'))->toBeTrue()
        ->and($worker->canDo('hazards.manage'))->toBeFalse();

    $site = Site::query()->findOrFail(IncidentHandoverE2ESeeder::SITE_ID);
    $client = Client::query()->findOrFail(IncidentHandoverE2ESeeder::CLIENT_ID);
    expect($site->name)->toBe('Playwright Incident Handover House')
        ->and($client->site_id)->toBe($site->id);

    $activeShift = Shift::query()
        ->where('name', IncidentHandoverE2ESeeder::SHIFT_NAME)
        ->active()
        ->sole();
    expect($activeShift->shift_lead_user_id)
        ->toBe($firstUserIds['incident-e2e-operator@demo.test'])
        ->and($activeShift->memberUserIds())
        ->toContain(
            $firstUserIds['incident-e2e-operator@demo.test'],
            $firstUserIds['incident-e2e-incoming@demo.test'],
        );

    $assertBoundedAlerts = function () use ($site, $client): void {
        $alerts = ControlRoomAlert::query()
            ->where('context->fixture_marker', IncidentHandoverE2ESeeder::MARKER)
            ->orderBy('reference_number')
            ->get();

        expect($alerts)->toHaveCount(2)
            ->and($alerts->pluck('reference_number')->all())
            ->toBe(IncidentHandoverE2ESeeder::REQUIRED_ALERT_REFERENCES)
            ->and($alerts->pluck('site_id')->unique()->all())
            ->toBe([$site->id])
            ->and($alerts->pluck('client_id')->unique()->all())
            ->toBe([$client->id]);
    };
    $assertBoundedAlerts();

    $reportingShift = ClinicalShift::query()->where('notes', IncidentHandoverE2ESeeder::MEDICATION_SHIFT_NOTES)->sole();
    expect($reportingShift->user_id)->toBe($firstUserIds[IncidentHandoverE2ESeeder::OPERATOR_EMAIL])
        ->and($reportingShift->client_id)->toBe($client->id)
        ->and($reportingShift->site_id)->toBe($site->id)
        ->and($reportingShift->status)->toBe('in_progress')
        ->and($reportingShift->actual_starts_at)->not->toBeNull()
        ->and($reportingShift->actual_ends_at)->toBeNull()
        ->and($client->supportWorkers()->whereKey($reportingShift->user_id)->exists())->toBeTrue();
    $unrelatedShift = ClinicalShift::factory()->create(['notes' => 'Unrelated synthetic clinical cover']);
    $beforeUnrelatedShift = $unrelatedShift->refresh()->getRawOriginal();

    $this->seed(IncidentHandoverE2ESeeder::class);

    expect(User::query()->whereIn('email', $emails)->pluck('id', 'email')->all())
        ->toBe($firstUserIds->all())
        ->and(Shift::query()
            ->where('name', IncidentHandoverE2ESeeder::SHIFT_NAME)
            ->active()
            ->count())
        ->toBe(1)
        ->and(Shift::query()
            ->where('name', IncidentHandoverE2ESeeder::SHIFT_NAME)
            ->where('status', '!=', 'active')
            ->count())
        ->toBe(1);
    $assertBoundedAlerts();
    expect(ClinicalShift::query()->where('notes', IncidentHandoverE2ESeeder::MEDICATION_SHIFT_NOTES)->sole()->id)
        ->toBe($reportingShift->id)
        ->and($unrelatedShift->refresh()->getRawOriginal())->toBe($beforeUnrelatedShift);
});

it('the seeded medication reporter preserves distinct incident journeys and current work denials', function () {
    Queue::fake();
    Notification::fake();
    $this->seed(RbacSeeder::class);
    $this->seed(IncidentHandoverE2ESeeder::class);
    $operator = User::query()->where('email', IncidentHandoverE2ESeeder::OPERATOR_EMAIL)->sole();
    $incoming = User::query()->where('email', IncidentHandoverE2ESeeder::INCOMING_EMAIL)->sole();
    $client = Client::query()->findOrFail(IncidentHandoverE2ESeeder::CLIENT_ID);
    expect($operator->canDo('medications.administer.record'))->toBeTrue()
        ->and($operator->canDo('medications.errors.manage'))->toBeTrue();

    $manualToken = (string) Str::uuid();
    $this->actingAs($operator)->post('/incidents', [
        'intent' => 'submit', 'report_request_uuid' => $manualToken,
        'client_id' => $client->id, 'site_id' => $client->site_id,
        'type' => 'medication_error', 'severity' => 'high', 'occurred_at' => now()->toIso8601String(),
        'description' => 'Synthetic manually reported medication concern.',
        'immediate_action_taken' => 'Synthetic dose withheld and prescriber contacted.',
    ])->assertRedirect()->assertSessionHasNoErrors();
    $manual = ClientIncident::query()->where('report_request_uuid', $manualToken)->sole();
    $payload = [
        'client_id' => $client->id, 'error_type' => 'wrong_dose', 'reached_client' => 'yes', 'harm_level' => 'moderate',
        'occurred_at' => now('Pacific/Auckland')->format('Y-m-d\TH:i'), 'report_token' => (string) Str::uuid(),
        'description' => 'Synthetic second event independently reported through eMAR.',
        'immediate_action' => 'Synthetic dose withheld and prescriber contacted.',
        'contributing_factors' => 'Synthetic distinct administration event.', 'create_incident' => true,
    ];
    $this->actingAs($operator)->post('/emar/errors', $payload)->assertRedirect()->assertSessionHasNoErrors();
    $error = MedicationError::query()->where('report_token', $payload['report_token'])->sole();
    $medicationIncident = ClientIncident::query()->findOrFail($error->client_incident_id);
    expect($medicationIncident->id)->not->toBe($manual->id)
        ->and($manual->control_room_alert_id)->not->toBeNull()
        ->and($medicationIncident->control_room_alert_id)->not->toBeNull()
        ->and($medicationIncident->control_room_alert_id)->not->toBe($manual->control_room_alert_id);

    $beforeEffects = collect([
        'medication_errors', 'medication_error_entries', 'medication_error_report_receipts',
        'client_incidents', 'hs_events', 'control_room_alerts', 'control_room_signals',
    ])->mapWithKeys(fn (string $table): array => [$table => DB::table($table)->count()])->all();
    $this->actingAs($operator)->post('/emar/errors', $payload)->assertRedirect()->assertSessionHasNoErrors();
    for ($attempt = 0; $attempt < 2; $attempt++) {
        $this->actingAs($operator)->post("/emar/errors/{$error->id}/link-incident")->assertRedirect()->assertSessionHasNoErrors();
    }
    expect($error->refresh()->client_incident_id)->toBe($medicationIncident->id);

    $foreignClient = Client::factory()->create(['site_id' => Site::factory()->create()->id]);
    $this->actingAs($operator)->post('/emar/errors', array_replace($payload, [
        'client_id' => $foreignClient->id, 'report_token' => (string) Str::uuid(),
    ]))->assertNotFound();
    $this->actingAs($incoming)->post('/emar/errors', array_replace($payload, [
        'report_token' => (string) Str::uuid(),
    ]))->assertNotFound();

    $reportingShift = ClinicalShift::query()->where('notes', IncidentHandoverE2ESeeder::MEDICATION_SHIFT_NOTES)->sole();
    $reportingShift->update([
        'status' => 'completed', 'actual_ends_at' => now()->subMinute(), 'completed_by' => $operator->id,
    ]);
    // The support assignment remains, so this denial proves current clinical
    // cover is required independently of the actor's reporting permission.
    expect($client->supportWorkers()->whereKey($operator->id)->exists())->toBeTrue();
    $this->actingAs($operator)->post('/emar/errors', array_replace($payload, [
        'report_token' => (string) Str::uuid(),
    ]))->assertNotFound();
    foreach ($beforeEffects as $table => $count) {
        $this->assertDatabaseCount($table, $count);
    }
    $this->assertDatabaseCount('medication_errors', 1);
    $this->assertDatabaseCount('client_incidents', 2);
    $this->assertDatabaseCount('control_room_alerts', 4);
    $this->assertDatabaseCount('client_medication_administrations', 0);
});

it('the seeded H&S owner has exact closure authority while the independent verifier cannot close their event', function () {
    $this->seed(RbacSeeder::class);
    $this->seed(IncidentHandoverE2ESeeder::class);
    $owner = User::query()->where('email', 'incident-e2e-owner@demo.test')->sole();
    $verifier = User::query()->where('email', 'incident-e2e-verifier@demo.test')->sole();
    expect($owner->canDo('healthSafety.events.close'))->toBeTrue()
        ->and($owner->canDo('healthSafety.events.closeAny'))->toBeFalse()
        ->and($verifier->canDo('hazards.manage'))->toBeTrue()
        ->and($verifier->canDo('healthSafety.events.close'))->toBeFalse()
        ->and($verifier->canDo('healthSafety.events.closeAny'))->toBeFalse();
    $event = HsEvent::factory()->worksafeNotNotifiable($owner)->create([
        'site_id' => IncidentHandoverE2ESeeder::SITE_ID,
        'owner_user_id' => $owner->id,
        'handover_status' => HsEvent::HANDOVER_NOT_REQUIRED,
        'investigation_required' => false,
    ]);
    $beforeEvent = $event->refresh()->getRawOriginal();
    $this->actingAs($verifier)->post("/health-safety/events/{$event->id}/close", [
        'closure_summary' => 'Synthetic verifier must not assume the owner closure responsibility.',
    ])->assertForbidden();
    expect($event->refresh()->getRawOriginal())->toBe($beforeEvent);

    $this->actingAs($owner)->post("/health-safety/events/{$event->id}/close", [
        'closure_summary' => 'Synthetic owner closes their ready event after independent verification.',
    ])->assertRedirect()->assertSessionHas('success');
    expect($event->refresh()->status)->toBe(HsEvent::STATUS_CLOSED)
        ->and($event->closed_by)->toBe($owner->id)
        ->and($event->closed_at)->not->toBeNull();
});

it('reseeds after a canonical synthetic medication report while preserving unrelated person evidence', function () {
    Notification::fake();
    Queue::fake();
    $this->seed(RbacSeeder::class);
    $this->seed(IncidentHandoverE2ESeeder::class);
    $operator = User::query()->where('email', IncidentHandoverE2ESeeder::OPERATOR_EMAIL)->sole();
    $client = Client::query()->findOrFail(IncidentHandoverE2ESeeder::CLIENT_ID);
    $payload = [
        'client_id' => $client->id,
        'error_type' => 'wrong_dose',
        'reached_client' => 'yes',
        'harm_level' => 'moderate',
        'occurred_at' => now('Pacific/Auckland')->format('Y-m-d\TH:i'),
        'report_token' => (string) Str::uuid(),
        'description' => 'Synthetic medication report before the next handover fixture reset.',
        'immediate_action' => 'Synthetic dose withheld and prescriber contacted.',
        'contributing_factors' => 'Synthetic fixture reset regression.',
        'create_incident' => true,
    ];
    $this->actingAs($operator)->post('/emar/errors', $payload)
        ->assertRedirect()->assertSessionHasNoErrors();
    $error = MedicationError::query()->where('report_token', $payload['report_token'])->sole();
    $errorId = $error->id;
    $incidentId = $error->client_incident_id;
    expect($incidentId)->not->toBeNull()
        ->and(DB::table('medication_error_entries')->where('medication_error_id', $errorId)->count())->toBeGreaterThan(0)
        ->and(DB::table('medication_error_report_receipts')->where('medication_error_id', $errorId)->count())->toBe(1);

    // The clinical parent cannot be deleted while its durable evidence exists.
    try {
        MedicationError::withTrashed()->whereKey($errorId)->forceDelete();
        $this->fail('The restrictive medication evidence allowed its parent to be deleted.');
    } catch (QueryException $exception) {
        expect($exception->errorInfo[0])->toBe('23000')
            ->and((int) $exception->errorInfo[1])->toBe(1451);
    }

    $otherClient = Client::factory()->create(['site_id' => $client->site_id]);
    $otherClient->supportWorkers()->attach($operator->id);
    $otherCover = ClinicalShift::query()->where('notes', IncidentHandoverE2ESeeder::MEDICATION_SHIFT_NOTES)
        ->sole()->replicate();
    $otherCover->forceFill([
        'client_id' => $otherClient->id,
        'service_context_id' => $otherClient->service_context_id,
        'notes' => 'Synthetic other-person cover outside the handover reset.',
    ])->save();
    $otherPayload = array_replace($payload, [
        'client_id' => $otherClient->id,
        'report_token' => (string) Str::uuid(),
        'description' => 'Synthetic unrelated person evidence must survive the handover reset.',
    ]);
    $this->actingAs($operator)->post('/emar/errors', $otherPayload)
        ->assertRedirect()->assertSessionHasNoErrors();
    $otherError = MedicationError::query()->where('report_token', $otherPayload['report_token'])->sole();
    $otherOriginal = $otherError->refresh()->getRawOriginal();
    $otherEntries = DB::table('medication_error_entries')->where('medication_error_id', $otherError->id)
        ->orderBy('id')->get()->map(fn ($row): array => (array) $row)->all();
    $otherReceipts = DB::table('medication_error_report_receipts')->where('medication_error_id', $otherError->id)
        ->orderBy('token')->get()->map(fn ($row): array => (array) $row)->all();
    expect($otherEntries)->not->toBeEmpty()->and($otherReceipts)->toHaveCount(1);

    $this->seed(IncidentHandoverE2ESeeder::class);

    expect(MedicationError::withTrashed()->whereKey($errorId)->exists())->toBeFalse()
        ->and(ClientIncident::query()->whereKey($incidentId)->exists())->toBeFalse()
        ->and(DB::table('medication_error_entries')->where('medication_error_id', $errorId)->count())->toBe(0)
        ->and(DB::table('medication_error_actions')->where('medication_error_id', $errorId)->count())->toBe(0)
        ->and(DB::table('medication_error_report_receipts')->where('medication_error_id', $errorId)->count())->toBe(0)
        ->and($otherError->fresh()->getRawOriginal())->toBe($otherOriginal)
        ->and(DB::table('medication_error_entries')->where('medication_error_id', $otherError->id)
            ->orderBy('id')->get()->map(fn ($row): array => (array) $row)->all())->toBe($otherEntries)
        ->and(DB::table('medication_error_report_receipts')->where('medication_error_id', $otherError->id)
            ->orderBy('token')->get()->map(fn ($row): array => (array) $row)->all())->toBe($otherReceipts)
        ->and(Shift::query()->where('name', IncidentHandoverE2ESeeder::SHIFT_NAME)->active()->count())->toBe(1)
        ->and(ControlRoomAlert::query()->where('context->fixture_marker', IncidentHandoverE2ESeeder::MARKER)->count())->toBe(2);
});

it('retains immutable canonical journey evidence and reseeds an isolated current generation', function (bool $reopen) {
    Notification::fake();
    Queue::fake();
    $this->seed(RbacSeeder::class);
    $this->seed(IncidentHandoverE2ESeeder::class);
    $operator = User::query()->where('email', IncidentHandoverE2ESeeder::OPERATOR_EMAIL)->sole();
    $reviewer = User::query()->where('email', 'incident-e2e-reviewer@demo.test')->sole();
    $owner = User::query()->where('email', 'incident-e2e-owner@demo.test')->sole();
    $oldClient = Client::query()->findOrFail(IncidentHandoverE2ESeeder::CLIENT_ID);
    $oldSite = $oldClient->site;
    $alert = ControlRoomAlert::query()->where('reference_number', IncidentHandoverE2ESeeder::REQUIRED_ALERT_REFERENCES[1])->sole();
    $this->actingAs($operator)->post("/control-room/alerts/{$alert->id}/acknowledge", [
        'notes' => 'Synthetic lifecycle concern acknowledged through the real operator route.',
    ])->assertRedirect()->assertSessionHas('success')->assertSessionHasNoErrors();
    expect($alert->refresh()->status)->toBe(ControlRoomAlert::STATUS_ACK)
        ->and($alert->acknowledged_by_user_id)->toBe($operator->id);
    $this->actingAs($operator)->post("/control-room/alerts/{$alert->id}/triage", [
        'notes' => 'Synthetic lifecycle concern triaged through the real operator route.',
    ])->assertRedirect()->assertSessionHas('success')->assertSessionHasNoErrors();
    expect($alert->refresh()->status)->toBe(ControlRoomAlert::STATUS_TRIAGING);
    $this->actingAs($operator)->post("/control-room/alerts/{$alert->id}/evidence", [
        'title' => 'Synthetic original lifecycle evidence',
    ])->assertRedirect()->assertSessionHasNoErrors();
    $pack = EvidencePack::query()->where('alert_id', $alert->id)->sole();
    $this->actingAs($operator)->post("/control-room/alerts/{$alert->id}/evidence/{$pack->id}/items", [
        'item_type' => 'note', 'content' => 'Synthetic evidence must remain attached after the reset.',
    ])->assertRedirect()->assertSessionHasNoErrors();
    $this->actingAs($operator)->postJson("/control-room/alerts/{$alert->id}/create-incident", [
        'type' => 'near_miss', 'severity' => 'medium', 'title' => 'Synthetic lifecycle reset regression',
        'description' => 'Synthetic no-injury concern with retained clinical provenance.',
        'immediate_action_taken' => 'Synthetic immediate controls recorded.',
    ])->assertOk()->assertJsonPath('journey.alert.id', $alert->id);
    $incident = ClientIncident::query()->where('control_room_alert_id', $alert->id)->sole();
    $event = HsEvent::query()->where('control_room_alert_id', $alert->id)->sole();
    $incidentReference = $incident->reference_number;
    expect($incident->status)->toBe('submitted')->and($incidentReference)->not->toBe('');
    // The established browser C journey configures the exact alert SLA before
    // reopening. This is fixture configuration, not a lifecycle state bypass.
    $definition = SlaDefinition::query()->create([
        'code' => 'incident-handover-generation-reopen', 'name' => 'Synthetic incident handover generation reopen',
        'alert_types' => [$alert->alert_type], 'severities' => ['medium'], 'sources' => ['manual'],
        'acknowledge_target_minutes' => 5, 'response_target_minutes' => 15, 'resolution_target_minutes' => 60,
        'is_active' => true,
    ]);
    expect(SlaDefinition::findForAlert($alert->alert_type, $alert->severity, $alert->source)?->id)->toBe($definition->id);
    $this->actingAs($operator)->post("/control-room/alerts/{$alert->id}/resolve", [
        'resolution_notes' => 'Synthetic initial clinical and operational review complete.',
        'resolution_code' => 'initial_review_complete',
    ])->assertRedirect()->assertSessionHasNoErrors();
    expect($alert->refresh()->status)->toBe(ControlRoomAlert::STATUS_RESOLVED)
        ->and($alert->resolved_by_user_id)->toBe($operator->id)
        ->and($alert->resolved_at)->not->toBeNull();
    $this->actingAs($owner)->post("/health-safety/events/{$event->id}/accept-handover", [
        'owner_user_id' => $owner->id, 'acceptance_notes' => 'Synthetic owner reviewed this no-injury journey.',
    ])->assertRedirect()->assertSessionHasNoErrors();
    expect($event->refresh()->handover_status)->toBe(HsEvent::HANDOVER_ACCEPTED)
        ->and($event->owner_user_id)->toBe($owner->id)
        ->and($event->accepted_by_user_id)->toBe($owner->id)
        ->and($event->accepted_at)->not->toBeNull();
    $this->actingAs($owner)->post("/health-safety/events/{$event->id}/worksafe/decision", [
        'notifiable' => false, 'source' => 'manual',
        'reason' => 'Synthetic no-injury concern does not meet a WorkSafe notification threshold.',
    ])->assertRedirect()->assertSessionHasNoErrors();
    expect($event->refresh()->worksafe_notifiable)->toBeFalse()
        ->and($event->worksafe_decided_by_user_id)->toBe($owner->id)
        ->and($event->worksafe_decided_at)->not->toBeNull();
    $readiness = app(HsEventClosureService::class)->readiness($event->fresh());
    expect($readiness->blockers())->toBe([])->and($readiness->ordinaryAllowed())->toBeTrue();
    $this->actingAs($owner)->post("/health-safety/events/{$event->id}/close", [
        'closure_summary' => 'Synthetic owner completed the ordinary governance readiness checks.',
    ])->assertRedirect()->assertSessionHas('success')->assertSessionHasNoErrors();
    $this->actingAs($reviewer)->post("/incidents/{$incident->id}/review", [
        'review_notes' => 'Synthetic independent reviewer completed the incident review.',
    ])->assertRedirect()->assertSessionHasNoErrors();
    expect($incident->refresh()->status)->toBe('reviewed')
        ->and($incident->reviewed_by)->toBe($reviewer->id)
        ->and($incident->reviewed_at)->not->toBeNull();
    $this->actingAs($reviewer)->post("/incidents/{$incident->id}/close", [
        'closed_outcome' => 'Synthetic controls verified', 'closed_notes' => 'Linked H&S event closed through the real workflow.',
    ])->assertRedirect()->assertSessionHas('success')->assertSessionHasNoErrors();
    expect($incident->refresh()->status)->toBe('closed')
        ->and($incident->closed_by)->toBe($reviewer->id)
        ->and($incident->closed_at)->not->toBeNull()
        ->and($incident->reference_number)->toBe($incidentReference)
        ->and($event->refresh()->status)->toBe(HsEvent::STATUS_CLOSED)
        ->and($event->closed_by)->toBe($owner->id)
        ->and($event->closed_at)->not->toBeNull()
        ->and(AuditLog::query()->where('auditable_type', $event->getMorphClass())->where('auditable_id', $event->id)
            ->where('action', 'healthSafety.event.closed')->count())->toBe(1);
    $closeOutbox = IncidentLifecycleSignalOutbox::query()->sole();
    (new DispatchIncidentLifecycleSignalOutbox($closeOutbox->id))->handle(app(ControlRoomAlertLifecycleService::class));
    expect($closeOutbox->fresh()->status)->toBe('sent')->and($closeOutbox->fresh()->resulting_alert_id)->toBe($alert->id);
    if ($reopen) {
        $this->actingAs($reviewer)->post("/incidents/{$incident->id}/reopen", [
            'reopened_reason' => 'Synthetic new witness evidence changes the immediate risk picture.',
        ])->assertRedirect()->assertSessionHasNoErrors();
        $reopenSignal = IncidentLifecycleSignal::query()->where('signal_type', IncidentLifecycleSignal::TYPE_REOPENED)->sole();
        (new DispatchIncidentLifecycleSignalOutbox($reopenSignal->outbox->id))->handle(app(ControlRoomAlertLifecycleService::class));
        expect($incident->refresh()->status)->toBe('reviewed')
            ->and($incident->reference_number)->toBe($incidentReference)
            ->and($incident->reopened_by)->toBe($reviewer->id)
            ->and($event->refresh()->status)->toBe(HsEvent::STATUS_CLOSED)
            ->and($alert->refresh()->status)->toBe(ControlRoomAlert::STATUS_TRIAGING)
            ->and($alert->sla->sla_definition_id)->toBe($definition->id)
            ->and($reopenSignal->outbox->fresh()->status)->toBe('sent')
            ->and(AuditLog::query()->where('auditable_type', $alert->getMorphClass())->where('auditable_id', $alert->id)
                ->where('action', 'controlRoom.alert.reopenFromIncidentSignal')->count())->toBe(1);
    }
    expect(IncidentLifecycleSignal::query()->where('client_id', $oldClient->id)->count())->toBe($reopen ? 2 : 1);

    $unrelatedSite = Site::factory()->create();
    $unrelatedClient = Client::factory()->create(['site_id' => $unrelatedSite->id]);
    $unrelatedAlert = ControlRoomAlert::factory()->create([
        'site_id' => $unrelatedSite->id, 'client_id' => $unrelatedClient->id,
        'status' => ControlRoomAlert::STATUS_OPEN,
    ]);
    $unrelatedBefore = [$unrelatedSite->fresh()->getRawOriginal(), $unrelatedClient->fresh()->getRawOriginal(), $unrelatedAlert->fresh()->getRawOriginal()];
    $oldGraph = function () use ($oldClient, $oldSite, $alert, $pack, $incident, $event, $definition): array {
        $tables = [
            'client_incidents' => ['client_id', $oldClient->id],
            'hs_events' => ['client_id', $oldClient->id],
            'control_room_alerts' => ['client_id', $oldClient->id],
            'shifts' => ['client_id', $oldClient->id],
            'incident_lifecycle_signals' => ['client_id', $oldClient->id],
            'control_room_evidence_packs' => ['alert_id', $alert->id],
            'control_room_evidence_items' => ['evidence_pack_id', $pack->id],
        ];
        $graph = ['site' => $oldSite->fresh()->getRawOriginal(), 'client' => $oldClient->fresh()->getRawOriginal()];
        foreach ($tables as $table => [$column, $id]) {
            $graph[$table] = DB::table($table)->where($column, $id)->orderBy('id')->get()->map(fn ($row): array => (array) $row)->all();
        }
        $graph['outbox'] = DB::table('incident_lifecycle_signal_outbox')
            ->whereIn('incident_lifecycle_signal_id', array_column($graph['incident_lifecycle_signals'], 'id'))
            ->orderBy('id')->get()->map(fn ($row): array => (array) $row)->all();

        $graph['sla_definition'] = $definition->fresh()->getRawOriginal();
        $graph['clinical_audit'] = DB::table('audit_logs')->where(function ($query) use ($incident, $event, $alert): void {
            foreach ([$incident, $event, $alert] as $record) {
                $query->orWhere(fn ($scope) => $scope->where('auditable_type', $record->getMorphClass())->where('auditable_id', $record->id));
            }
        })->orderBy('id')->get()->map(fn ($row): array => (array) $row)->all();

        return $graph;
    };
    $before = $oldGraph();
    expect($before['control_room_evidence_items'])->toHaveCount(1)
        ->and($before['outbox'])->toHaveCount($reopen ? 2 : 1);
    // This is the original reset's exact parent deletion, after a real close.
    // Immutable source evidence must continue to reject it at the database.
    try {
        ControlRoomAlert::query()->whereKey($alert->id)->delete();
        $this->fail('Immutable incident lifecycle evidence allowed its alert parent to be deleted.');
    } catch (QueryException $exception) {
        expect($exception->errorInfo[0])->toBe('23000')
            ->and((int) $exception->errorInfo[1])->toBe(1451)
            ->and($exception->getMessage())->toMatch('/CONSTRAINT `(incident_lifecycle_signals_control_room_alert_id_foreign|incident_lifecycle_signal_outbox_resulting_alert_id_foreign)` FOREIGN KEY/');
    }
    expect($oldGraph())->toBe($before);
    $readManifest = function (): array {
        expect(Artisan::call('db:seed', ['--class' => IncidentHandoverE2ESeeder::class, '--force' => true]))->toBe(0);
        preg_match('/^INCIDENT_HANDOVER_MANIFEST=(.+)$/m', Artisan::output(), $matches);
        expect($matches)->toHaveCount(2);

        return json_decode($matches[1], true, 512, JSON_THROW_ON_ERROR);
    };
    $manifest = $readManifest();
    $currentSiteId = $manifest['site']['id'];
    $currentClientId = $manifest['client']['id'];
    $expectedReferences = array_map(fn (string $reference): string => $reference.'-G'.$currentSiteId, IncidentHandoverE2ESeeder::REQUIRED_ALERT_REFERENCES);
    expect($currentSiteId)->not->toBe($oldSite->id)
        ->and($currentClientId)->not->toBe($oldClient->id)
        ->and($manifest['marker'])->toBe(IncidentHandoverE2ESeeder::MARKER)
        ->and($manifest['site']['name'])->toBe('Playwright Incident Handover House ['.$currentSiteId.']')
        ->and($manifest['client']['name'])->toBe('Playwright Aroha Handover ['.$currentSiteId.']')
        ->and($manifest['records']['required_alert_references'])->toBe($expectedReferences)
        ->and($manifest['records']['required_alert_ids'])->toHaveCount(2)
        ->and($oldGraph())->toBe($before);
    $assertCurrentScope = function (array $activeManifest) use ($operator, $alert): void {
        $actor = $operator->fresh();
        expect($actor->canDo('healthSafety.viewAllSites'))->toBeFalse()
            ->and($actor->canDo('reports.viewAny'))->toBeFalse();
        foreach ($activeManifest['users'] as $user) {
            $profile = HrEmployeeProfile::query()->where('user_id', $user['id'])->sole();
            expect($profile->primary_site_id)->toBe($activeManifest['site']['id'])
                ->and($profile->secondary_site_ids)->toBe([]);
        }
        $activeShift = Shift::query()->active()->sole();
        $scope = app(ControlRoomHandoverScopeService::class)->build($activeShift, $actor);
        expect(array_column($scope['required_alerts'], 'id'))->toEqualCanonicalizing($activeManifest['records']['required_alert_ids'])
            ->and($scope['carry_forward_alert_ids'])->toBe([])
            ->and(ControlRoomAlert::query()->where('client_id', $activeManifest['client']['id'])->count())->toBe(2)
            ->and($activeShift->id)->toBe($activeManifest['shift']['id']);
        $this->actingAs($actor)->getJson('/control-room/alerts/'.$activeManifest['records']['required_alert_ids'][0])->assertOk();
        $this->actingAs($actor)->getJson('/control-room/alerts/'.$alert->id)->assertNotFound();
    };
    $assertCurrentScope($manifest);
    $again = $readManifest();
    expect($again['site'])->toBe($manifest['site'])
        ->and($again['client'])->toBe($manifest['client'])
        ->and($again['users'])->toBe($manifest['users'])
        ->and($again['records']['required_alert_references'])->toBe($expectedReferences)
        ->and(Site::query()->where('email', 'like', 'incident-handover-house-generation-%@demo.test')->count())->toBe(1)
        ->and($oldGraph())->toBe($before)
        ->and([$unrelatedSite->fresh()->getRawOriginal(), $unrelatedClient->fresh()->getRawOriginal(), $unrelatedAlert->fresh()->getRawOriginal()])->toBe($unrelatedBefore);
    $assertCurrentScope($again);
})->with(['closed journey' => [false], 'reopened journey' => [true]]);
