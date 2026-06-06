<?php

use App\Models\Client;
use App\Models\ClientMedication;
use App\Models\ClientMedicationAlert;
use App\Models\MedicationAllergy;
use App\Models\RespiteBooking;
use App\Models\RespiteEvidencePack;
use App\Models\RespiteMedicationReconciliation;
use App\Models\RespiteStay;
use App\Models\Role;
use App\Models\SafeguardingAlert;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(\Database\Seeders\RbacSeeder::class);
    $this->admin = User::factory()->create(['role' => 'admin', 'approved_at' => now()]);
    $this->admin->roles()->attach(Role::where('name', 'admin')->first());
});

test('check in requires completed admission medication reconciliation for clients with active medicines', function () {
    $client = Client::factory()->create();
    ClientMedication::factory()->create([
        'client_id' => $client->id,
        'active' => true,
        'state' => 'active',
        'approval_status' => 'verified',
    ]);
    $booking = RespiteBooking::factory()->create(['client_id' => $client->id, 'status' => 'confirmed']);
    $stay = RespiteStay::create([
        'booking_id' => $booking->id,
        'client_id' => $client->id,
        'status' => 'admitted',
        'actual_start' => now(),
        'created_by' => $this->admin->id,
    ]);

    $this->actingAs($this->admin)
        ->post(route('respite.stays.checkin', $stay))
        ->assertSessionHasErrors('medication_reconciliation');

    expect($stay->fresh()->status)->toBe('admitted');
});

test('completed admission medication reconciliation allows check in', function () {
    $client = Client::factory()->create();
    ClientMedication::factory()->create([
        'client_id' => $client->id,
        'active' => true,
        'state' => 'active',
        'approval_status' => 'verified',
    ]);
    $booking = RespiteBooking::factory()->create(['client_id' => $client->id, 'status' => 'confirmed']);
    $stay = RespiteStay::create([
        'booking_id' => $booking->id,
        'client_id' => $client->id,
        'status' => 'admitted',
        'actual_start' => now(),
        'created_by' => $this->admin->id,
    ]);
    RespiteMedicationReconciliation::create([
        'stay_id' => $stay->id,
        'type' => 'admission',
        'status' => 'completed',
        'source' => 'pharmacy_pack',
        'count_received' => 3,
        'reconciled_by_user_id' => $this->admin->id,
        'reconciled_at' => now(),
        'created_by' => $this->admin->id,
    ]);

    $this->actingAs($this->admin)
        ->post(route('respite.stays.checkin', $stay))
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    expect($stay->fresh()->status)->toBe('active');
});

test('life threatening allergies require anaphylaxis acknowledgement before check in and enter the evidence manifest', function () {
    $client = Client::factory()->create();
    MedicationAllergy::create([
        'client_id' => $client->id,
        'allergen' => 'Peanuts',
        'reaction' => 'Anaphylaxis',
        'severity' => 'life_threatening',
        'recorded_by' => $this->admin->id,
    ]);
    $booking = RespiteBooking::factory()->create(['client_id' => $client->id, 'status' => 'confirmed']);
    $stay = RespiteStay::create([
        'booking_id' => $booking->id,
        'client_id' => $client->id,
        'status' => 'admitted',
        'actual_start' => now(),
        'created_by' => $this->admin->id,
    ]);

    $this->actingAs($this->admin)
        ->post(route('respite.stays.checkin', $stay))
        ->assertSessionHasErrors('anaphylaxis_acknowledgement');

    $this->actingAs($this->admin)
        ->post(route('respite.stays.checkin', $stay), [
            'anaphylaxis_acknowledged' => true,
            'epipen_location' => 'Medication cupboard, labelled emergency pouch.',
            'anaphylaxis_escalation_note' => 'Call 111, administer EpiPen, notify on-call nurse and whānau.',
        ])
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    $acknowledgement = $stay->fresh()->admission_risk_screen['anaphylaxis_acknowledgement'] ?? null;

    expect($stay->fresh()->status)->toBe('active');
    expect($acknowledgement['epipen_location'])->toBe('Medication cupboard, labelled emergency pouch.');

    $this->actingAs($this->admin)
        ->post(route('respite.evidence-packs.store'), [
            'stay_id' => $stay->id,
            'summary' => 'Arrival safety evidence.',
        ])
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    $manifest = collect(RespiteEvidencePack::firstOrFail()->items)->keyBy('type');

    expect($manifest)->toHaveKey('anaphylaxis_acknowledgement');
    expect($manifest['anaphylaxis_acknowledgement']['complete'])->toBeTrue();
});

test('admission medication reconciliation can be completed before check in without using override', function () {
    $client = Client::factory()->create();
    ClientMedication::factory()->create([
        'client_id' => $client->id,
        'active' => true,
        'state' => 'active',
        'approval_status' => 'verified',
    ]);
    $booking = RespiteBooking::factory()->create(['client_id' => $client->id, 'status' => 'confirmed']);
    $stay = RespiteStay::create([
        'booking_id' => $booking->id,
        'client_id' => $client->id,
        'status' => 'admitted',
        'actual_start' => now(),
        'created_by' => $this->admin->id,
    ]);

    $this->actingAs($this->admin)
        ->post("/respite/stays/{$stay->id}/medication-reconciliation", [
            'type' => 'admission',
            'status' => 'completed',
            'source' => 'pharmacy_pack',
            'count_received' => 4,
            'discrepancies' => [
                ['medicine' => 'Paracetamol', 'issue' => 'Extra blister pack noted and counted.'],
            ],
            'first_dose_due_at' => now()->addHours(2)->format('Y-m-d H:i:s'),
        ])
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    expect(RespiteMedicationReconciliation::where('stay_id', $stay->id)->where('type', 'admission')->first()?->status)
        ->toBe('completed');
    expect($booking->fresh()->medications_reconciled)->toBeTrue();

    $this->actingAs($this->admin)
        ->post(route('respite.stays.checkin', $stay))
        ->assertRedirect()
        ->assertSessionHasNoErrors();
});

test('workspace stay payload includes critical medication and safeguarding alerts', function () {
    $client = Client::factory()->create();
    $booking = RespiteBooking::factory()->create(['client_id' => $client->id, 'status' => 'confirmed']);
    $stay = RespiteStay::create([
        'booking_id' => $booking->id,
        'client_id' => $client->id,
        'status' => 'admitted',
        'actual_start' => now(),
        'created_by' => $this->admin->id,
    ]);
    MedicationAllergy::create([
        'client_id' => $client->id,
        'allergen' => 'Penicillin',
        'reaction' => 'Anaphylaxis',
        'severity' => 'life_threatening',
        'recorded_by' => $this->admin->id,
    ]);
    ClientMedicationAlert::create([
        'client_id' => $client->id,
        'type' => 'interaction',
        'title' => 'High-risk medication interaction',
        'detail' => 'Check GP plan before first dose.',
        'enabled' => true,
        'prompt_on_open' => true,
        'created_by' => $this->admin->id,
    ]);
    SafeguardingAlert::create([
        'alertable_type' => Client::class,
        'alertable_id' => $client->id,
        'alert_type' => 'capacity_concerns',
        'alert_summary' => 'Capacity concern',
        'alert_details' => 'Use supported decision-making notes.',
        'severity' => 'high',
        'active' => true,
        'created_by' => $this->admin->id,
    ]);

    $this->actingAs($this->admin)
        ->get(route('respite.index'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('respite/index')
            ->where('stays.0.id', $stay->id)
            ->has('stays.0.criticalAlerts', 3)
            ->where('stays.0.criticalAlerts.0.label', 'Penicillin')
            ->where('stays.0.criticalAlerts.1.label', 'High-risk medication interaction')
            ->where('stays.0.criticalAlerts.2.label', 'Capacity concern')
        );
});
