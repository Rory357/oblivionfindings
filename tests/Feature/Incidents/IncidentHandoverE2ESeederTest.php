<?php

use App\Models\Client;
use App\Models\ClientIncident;
use App\Models\ControlRoom\Shift;
use App\Models\ControlRoomAlert;
use App\Models\HsEvent;
use App\Models\MedicationError;
use App\Models\Shift as ClinicalShift;
use App\Models\Site;
use App\Models\User;
use Database\Seeders\IncidentHandoverE2ESeeder;
use Database\Seeders\RbacSeeder;
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
