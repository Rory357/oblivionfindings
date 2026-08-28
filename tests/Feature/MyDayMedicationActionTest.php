<?php

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Models\Client;
use App\Models\ClientMedication;
use App\Models\ClientMedicationAdministration;
use App\Models\ClientMedicationStock;
use App\Models\MedicationCompetencyAssessment;
use App\Models\Permission;
use App\Models\Shift;
use App\Models\Site;
use App\Models\User;
use App\Services\EnhancedMarService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    Cache::flush();
    Carbon::setTestNow(Carbon::parse('2026-05-21 10:00:00', 'Pacific/Auckland'));
});

afterEach(function () {
    Cache::flush();
    Carbon::setTestNow();
});

it('lets a medication-record-only worker administer via /my-day/medications/{med}/administer', function () {
    [$worker, $medication] = makeWorkerAndMedication();

    $response = $this->actingAs($worker)
        ->post("/my-day/medications/{$medication->id}/administer", [
            'scheduled_for' => Carbon::parse('2026-05-21 10:00:00', 'Pacific/Auckland')->toIso8601String(),
        ]);

    $response->assertRedirect();
    expect(ClientMedicationAdministration::query()
        ->where('client_medication_id', $medication->id)
        ->where('status', 'given')
        ->where('administered_by', $worker->id)
        ->exists())->toBeTrue();
});

it('records My Day medication actions from a non-second-aligned authorization instant', function (
    string $action,
    array $actionPayload,
    string $expectedStatus,
) {
    $actionAt = Carbon::parse('2026-05-21 10:00:00', 'Pacific/Auckland')->setMicrosecond(123456);
    Carbon::setTestNow($actionAt);
    [$worker, $medication] = makeWorkerAndMedication();

    $this->actingAs($worker)
        ->post("/my-day/medications/{$medication->id}/{$action}", [
            'scheduled_for' => Carbon::parse('2026-05-21 10:00:00', 'Pacific/Auckland')->toIso8601String(),
            ...$actionPayload,
        ])
        ->assertRedirect()
        ->assertSessionHas('success');

    $administration = ClientMedicationAdministration::query()
        ->where('client_medication_id', $medication->id)
        ->sole();
    expect($administration->status)->toBe($expectedStatus)
        ->and(Carbon::parse(
            (string) $administration->getRawOriginal('administered_at'),
            'UTC',
        )->format('Y-m-d H:i:s'))
        ->toBe($actionAt->copy()->utc()->format('Y-m-d H:i:s'));
})->with([
    'given' => ['administer', [], 'given'],
    'refused' => ['refuse', [
        'reason_code' => 'refused',
        'reason' => 'Resident declined at the scheduled time.',
    ], 'refused'],
]);

it('does not create duplicate administrations when the same My Day dose is submitted twice', function () {
    [$worker, $medication] = makeWorkerAndMedication();
    $scheduledFor = Carbon::parse('2026-05-21 10:00:00', 'Pacific/Auckland')->toIso8601String();

    $payload = ['scheduled_for' => $scheduledFor];

    $this->actingAs($worker)
        ->post("/my-day/medications/{$medication->id}/administer", $payload)
        ->assertRedirect();

    $this->actingAs($worker)
        ->post("/my-day/medications/{$medication->id}/administer", $payload)
        ->assertRedirect();

    expect(ClientMedicationAdministration::query()
        ->where('client_medication_id', $medication->id)
        ->whereBetween('scheduled_for', [
            Carbon::parse($scheduledFor)->utc()->subMinute(),
            Carbon::parse($scheduledFor)->utc()->addMinute(),
        ])
        ->count())->toBe(1);
});

it('stores scheduled doses in UTC while resolving the local slot on My Day and MAR', function () {
    [$worker, $medication] = makeWorkerAndMedication([
        'frequency' => 'Once daily',
        'dose_times' => ['09:00'],
    ]);

    $scheduledLocal = Carbon::parse('2026-05-21 09:00:00', 'Pacific/Auckland');

    $this->actingAs($worker)
        ->post("/my-day/medications/{$medication->id}/administer", [
            'scheduled_for' => $scheduledLocal->toIso8601String(),
        ])
        ->assertRedirect();

    $administration = ClientMedicationAdministration::query()
        ->where('client_medication_id', $medication->id)
        ->firstOrFail();

    expect(DB::table('client_medication_administrations')->where('id', $administration->id)->value('scheduled_for'))
        ->toBe($scheduledLocal->copy()->utc()->format('Y-m-d H:i:s'));

    $this->actingAs($worker)
        ->get('/my-day')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('my-day/index')
            ->has('medications_due', 1)
            ->where('medications_due.0.medication_id', $medication->id)
            ->where('medications_due.0.scheduled_for', $scheduledLocal->toIso8601String())
            ->where('medications_due.0.status', 'given')
        );

    $mar = app(EnhancedMarService::class)->build(
        $medication->client()->firstOrFail(),
        Carbon::parse('2026-05-21', 'Pacific/Auckland'),
        Carbon::parse('2026-05-21 10:00:00', 'Pacific/Auckland'),
    );

    $row = collect($mar['scheduled'])->firstWhere('client_medication_id', $medication->id);
    expect($row)->not->toBeNull();
    expect($row['scheduled_time'])->toBe('09:00');
    expect($row['schedule_state'])->toBe('completed');
});

it('rejects a controlled drug My Day give without witness details', function () {
    [$worker, $medication] = makeWorkerAndMedication([
        'controlled_drug' => true,
        'witness_required' => true,
    ]);

    $this->actingAs($worker)
        ->post("/my-day/medications/{$medication->id}/administer", [
            'scheduled_for' => Carbon::parse('2026-05-21 10:00:00', 'Pacific/Auckland')->toIso8601String(),
        ])
        ->assertRedirect()
        ->assertSessionHasErrors('witnessed_by');

    expect(ClientMedicationAdministration::query()
        ->where('client_medication_id', $medication->id)
        ->exists())->toBeFalse();
});

it('records a witnessed controlled drug My Day give through the MAR service', function () {
    [$worker, $medication] = makeWorkerAndMedication([
        'controlled_drug' => true,
        'witness_required' => true,
    ]);
    $witness = makeMedicationWitness($medication->client);
    ClientMedicationStock::query()->create([
        'client_medication_id' => $medication->id,
        'on_hand' => 10,
        'unit' => 'tablets',
    ]);

    $this->actingAs($worker)
        ->post("/my-day/medications/{$medication->id}/administer", [
            'scheduled_for' => Carbon::parse('2026-05-21 10:00:00', 'Pacific/Auckland')->toIso8601String(),
            'witnessed_by' => $witness->id,
            'witness_credential' => 'password',
        ])
        ->assertRedirect()
        ->assertSessionHas('success');

    expect((float) $medication->stock()->value('on_hand'))->toBe(9.0);
    expect(ClientMedicationAdministration::query()
        ->where('client_medication_id', $medication->id)
        ->where('status', 'given')
        ->where('witnessed_by', $witness->id)
        ->exists())->toBeTrue();
    $this->assertDatabaseHas('client_controlled_drug_entries', [
        'client_id' => $medication->client_id,
        'client_medication_id' => $medication->id,
        'entry_type' => 'administered',
        'recorded_by' => $worker->id,
        'witnessed_by' => $witness->id,
        'on_hand_before' => 10,
        'on_hand_after' => 9,
    ]);
});

it('conceals missing and foreign My Day witnesses before checking eligible credentials', function () {
    [$worker, $medication] = makeWorkerAndMedication([
        'controlled_drug' => true,
        'witness_required' => true,
    ]);
    ClientMedicationStock::query()->create([
        'client_medication_id' => $medication->id,
        'on_hand' => 10,
        'unit' => 'tablets',
    ]);
    $foreignSite = Site::factory()->create();
    $foreignClient = Client::factory()->create([
        'site_id' => $foreignSite->id,
        'service_context_id' => $medication->client->service_context_id,
    ]);
    $foreignWitness = makeMedicationWitness($foreignClient);
    $missingWitnessId = (int) User::query()->max('id') + 1000;
    $payload = [
        'scheduled_for' => Carbon::parse('2026-05-21 10:00:00', 'Pacific/Auckland')->toIso8601String(),
        'witness_credential' => 'wrong-password',
    ];

    foreach ([$missingWitnessId, $foreignWitness->id] as $concealedWitnessId) {
        $this->actingAs($worker)
            ->post("/my-day/medications/{$medication->id}/administer", [
                ...$payload,
                'witnessed_by' => $concealedWitnessId,
            ])
            ->assertNotFound();
    }

    $eligibleWitness = makeMedicationWitness($medication->client);
    $this->actingAs($worker)
        ->from('/my-day')
        ->post("/my-day/medications/{$medication->id}/administer", [
            ...$payload,
            'witnessed_by' => $eligibleWitness->id,
        ])
        ->assertRedirect('/my-day')
        ->assertSessionHasErrors('witness_credential');

    expect(ClientMedicationAdministration::query()
        ->where('client_medication_id', $medication->id)
        ->exists())->toBeFalse();
});

it('records a refused administration via /my-day/medications/{med}/refuse', function () {
    [$worker, $medication] = makeWorkerAndMedication();

    $this->actingAs($worker)
        ->post("/my-day/medications/{$medication->id}/refuse", [
            'scheduled_for' => Carbon::parse('2026-05-21 10:00:00', 'Pacific/Auckland')->toIso8601String(),
            'reason_code' => 'refused',
            'reason' => 'Resident declined this morning.',
        ])
        ->assertRedirect();

    $admin = ClientMedicationAdministration::query()
        ->where('client_medication_id', $medication->id)
        ->where('status', 'refused')
        ->first();

    expect($admin)->not->toBeNull();
    expect($admin->reason)->toBe('Resident declined this morning.');
    expect($admin->reason_code)->toBe('refused');
});

it('stores a snooze flag in the cache for /my-day/medications/{med}/snooze', function () {
    Cache::flush();
    [$worker, $medication] = makeWorkerAndMedication();
    $scheduled = Carbon::parse('2026-05-21 10:00:00', 'Pacific/Auckland')->toIso8601String();

    $this->actingAs($worker)
        ->post("/my-day/medications/{$medication->id}/snooze", [
            'minutes' => 15,
            'scheduled_for' => $scheduled,
        ])
        ->assertRedirect();

    $key = "my-day.med-snooze.user-{$worker->id}.med-{$medication->id}.{$scheduled}";
    expect(Cache::has($key))->toBeTrue();
});

it('rejects unauthenticated medication administration', function () {
    $site = Site::factory()->create();
    $client = Client::factory()->create(['site_id' => $site->id]);
    $medication = ClientMedication::factory()->create(['client_id' => $client->id]);

    $this->post("/my-day/medications/{$medication->id}/administer")
        ->assertRedirect('/login');

    expect(ClientMedicationAdministration::count())->toBe(0);
});

it('rejects workers without medications.administer.record permission', function () {
    $worker = User::factory()->frontlineWorker()->create();
    $site = Site::factory()->create();
    assignMedicationWorkerToSite($worker, $site);
    $client = Client::factory()->create(['site_id' => $site->id]);
    $client->supportWorkers()->attach($worker->id);
    Shift::factory()->published()->create([
        'client_id' => $client->id,
        'site_id' => $site->id,
        'user_id' => $worker->id,
        'starts_at' => now()->subHour(),
        'ends_at' => now()->addHours(2),
        'actual_starts_at' => now()->subHour(),
        'status' => 'in_progress',
    ]);
    $medication = ClientMedication::factory()->create(['client_id' => $client->id]);

    $this->actingAs($worker)
        ->post("/my-day/medications/{$medication->id}/administer", [
            'scheduled_for' => now()->toIso8601String(),
        ])
        ->assertForbidden();

    $this->actingAs($worker)
        ->post("/my-day/medications/{$medication->id}/refuse", [
            'scheduled_for' => now()->toIso8601String(),
        ])
        ->assertForbidden();

    $this->actingAs($worker)
        ->post("/my-day/medications/{$medication->id}/snooze", [
            'minutes' => 15,
            'scheduled_for' => '2026-05-21T10:00:00+12:00',
        ])
        ->assertForbidden();

    expect(ClientMedicationAdministration::count())->toBe(0);
    expect(Cache::has("my-day.med-snooze.user-{$worker->id}.med-{$medication->id}.2026-05-21T10:00:00+12:00"))->toBeFalse();
});

it('conceals same-Site unassigned, foreign and missing My Day medication actions before request validation', function () {
    [$worker, $ordinaryMedication] = makeWorkerAndMedication();
    $localClient = $ordinaryMedication->client()->firstOrFail();
    $sameSiteClient = Client::factory()->create([
        'site_id' => $localClient->site_id,
        'service_context_id' => $localClient->service_context_id,
    ]);
    $sameSiteUnassignedMedication = ClientMedication::factory()->create([
        'client_id' => $sameSiteClient->id,
        'active' => true,
        'state' => 'active',
        'approval_status' => 'verified',
    ]);
    $foreignSite = Site::factory()->create();
    $foreignClient = Client::factory()->create(['site_id' => $foreignSite->id]);
    $foreignMedication = ClientMedication::factory()->create([
        'client_id' => $foreignClient->id,
        'active' => true,
        'state' => 'active',
        'approval_status' => 'verified',
    ]);

    $invalidPayload = [
        'scheduled_for' => 'not-a-date',
        'witnessed_by' => 'not-a-user-id',
        'minutes' => 'not-a-duration',
    ];
    foreach (['administer', 'refuse', 'snooze'] as $action) {
        $this->actingAs($worker)
            ->post("/my-day/medications/{$foreignMedication->id}/{$action}", $invalidPayload)
            ->assertNotFound();
        $this->actingAs($worker)
            ->post("/my-day/medications/999999999/{$action}", $invalidPayload)
            ->assertNotFound();
    }
    foreach (['administer', 'refuse'] as $action) {
        $this->actingAs($worker)
            ->post("/my-day/medications/{$sameSiteUnassignedMedication->id}/{$action}", $invalidPayload)
            ->assertNotFound();
    }

    expect(ClientMedicationAdministration::query()->exists())->toBeFalse();
    expect($ordinaryMedication->fresh())->not->toBeNull();
});

it('conceals controlled My Day actions without exact controlled record authority', function () {
    [$worker, $ordinaryMedication] = makeWorkerAndMedication();
    $controlledRecordPermission = Permission::query()->firstOrCreate(
        ['key' => 'medications.controlled.record'],
        ['description' => 'medications.controlled.record'],
    );
    $worker->permissionOverrides()->syncWithoutDetaching([
        $controlledRecordPermission->id => ['allowed' => false],
    ]);
    $worker->unsetRelation('permissionOverrides')->unsetRelation('roles');
    expect($worker->canDo('medications.controlled.record'))->toBeFalse();

    $controlledMedication = ClientMedication::factory()->create([
        'client_id' => $ordinaryMedication->client_id,
        'controlled_drug' => true,
        'active' => true,
        'state' => 'active',
        'approval_status' => 'verified',
        'dose_times' => ['10:00'],
        'start_date' => today()->subMonth(),
        'end_date' => null,
    ]);

    foreach (['administer', 'refuse', 'snooze'] as $action) {
        $this->actingAs($worker)
            ->post("/my-day/medications/{$controlledMedication->id}/{$action}", [])
            ->assertNotFound();
    }

    expect(ClientMedicationAdministration::query()
        ->where('client_medication_id', $controlledMedication->id)
        ->exists())->toBeFalse();
});

/**
 * Build a worker assigned to a client + an active medication for that client.
 *
 * Attaches only the exact medication recording authority used by these actions.
 * Client-profile visibility is intentionally not part of the write contract.
 */
function makeWorkerAndMedication(array $medicationOverrides = []): array
{
    $worker = User::factory()->frontlineWorker()->create();
    $site = Site::factory()->create();
    assignMedicationWorkerToSite($worker, $site);

    $overrides = [];
    $permissionKeys = ['medications.administer.record'];
    if ((bool) ($medicationOverrides['controlled_drug'] ?? false)) {
        $permissionKeys[] = 'medications.controlled.record';
    }

    foreach ($permissionKeys as $key) {
        $permission = Permission::query()->firstOrCreate(
            ['key' => $key],
            ['description' => $key],
        );
        $overrides[$permission->id] = ['allowed' => true];
    }
    $worker->permissionOverrides()->syncWithoutDetaching($overrides);
    $assessor = User::factory()->create();
    MedicationCompetencyAssessment::query()->create([
        'user_id' => $worker->id,
        'assessor_id' => $assessor->id,
        'assessment_type' => 'annual',
        'status' => 'passed',
        'assessment_date' => today()->subMonth(),
        'expiry_date' => today()->addYear(),
        'assessor_declared_at' => now()->subMonth(),
        'staff_acknowledged_at' => now()->subMonth()->addMinute(),
    ]);

    $client = Client::factory()->create(['site_id' => $site->id]);
    $client->supportWorkers()->attach($worker->id);

    $medication = ClientMedication::factory()->create(array_merge([
        'client_id' => $client->id,
        'name' => 'Donepezil',
        'dosage' => '5 mg',
        'dose_times' => ['10:00'],
        'active' => true,
        'state' => 'active',
        'approval_status' => 'verified',
        'start_date' => Carbon::parse('2026-05-01', 'Pacific/Auckland')->toDateString(),
        'end_date' => null,
    ], $medicationOverrides));

    Shift::factory()->create([
        'client_id' => $client->id,
        'site_id' => $site->id,
        'user_id' => $worker->id,
        'starts_at' => now()->subHour(),
        'ends_at' => now()->addHours(2),
        'actual_starts_at' => now()->subHour(),
        'actual_ends_at' => null,
        'started_by' => $worker->id,
        'status' => 'in_progress',
    ]);

    return [$worker, $medication];
}

function makeMedicationWitness(Client $client): User
{
    $witness = User::factory()->frontlineWorker()->create();
    assignMedicationWorkerToSite($witness, $client->site()->firstOrFail());
    $client->supportWorkers()->syncWithoutDetaching([$witness->id]);
    $permission = Permission::query()->firstOrCreate(
        ['key' => 'medications.controlled.witness'],
        ['description' => 'medications.controlled.witness'],
    );
    $witness->permissionOverrides()->syncWithoutDetaching([
        $permission->id => ['allowed' => true],
    ]);
    $assessor = User::query()->staff()->whereKeyNot($witness->id)->firstOrFail();
    MedicationCompetencyAssessment::query()->create([
        'user_id' => $witness->id,
        'assessor_id' => $assessor->id,
        'assessment_type' => 'annual',
        'status' => 'passed',
        'assessment_date' => today()->subMonth(),
        'expiry_date' => today()->addYear(),
        'assessor_declared_at' => now()->subMonth(),
        'staff_acknowledged_at' => now()->subMonth()->addMinute(),
        'can_witness_controlled' => true,
    ]);
    Shift::factory()->create([
        'client_id' => $client->id,
        'site_id' => $client->site_id,
        'service_context_id' => $client->service_context_id,
        'user_id' => $witness->id,
        'starts_at' => now()->subHour(),
        'ends_at' => now()->addHours(2),
        'actual_starts_at' => now()->subHour(),
        'actual_ends_at' => null,
        'started_by' => $witness->id,
        'status' => 'in_progress',
    ]);

    return $witness;
}

function assignMedicationWorkerToSite(User $worker, Site $site): void
{
    HrEmployeeProfile::factory()->create([
        'user_id' => $worker->id,
        'primary_site_id' => $site->id,
        'secondary_site_ids' => [],
        'start_date' => today()->subMonth(),
        'end_date' => null,
        'is_active' => true,
    ]);
}
