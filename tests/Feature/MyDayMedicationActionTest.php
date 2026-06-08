<?php

use App\Models\Client;
use App\Models\ClientMedication;
use App\Models\ClientMedicationAdministration;
use App\Models\ClientMedicationStock;
use App\Models\Permission;
use App\Models\Shift;
use App\Models\User;
use App\Services\EnhancedMarService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

beforeEach(function () {
    Cache::flush();
    Carbon::setTestNow(Carbon::parse('2026-05-21 10:00:00', 'Pacific/Auckland'));
});

afterEach(function () {
    Cache::flush();
    Carbon::setTestNow();
});

it('records a given administration via /my-day/medications/{med}/administer', function () {
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

    Shift::factory()->assignedToday($worker)->published()->create([
        'client_id' => $medication->client_id,
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

    expect(\Illuminate\Support\Facades\DB::table('client_medication_administrations')->where('id', $administration->id)->value('scheduled_for'))
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
    $witness = makeMedicationWitness();
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

    expect($medication->stock()->value('on_hand'))->toBe(9);
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
    $client = Client::factory()->create();
    $medication = ClientMedication::factory()->create(['client_id' => $client->id]);

    $this->post("/my-day/medications/{$medication->id}/administer")
        ->assertRedirect('/login');

    expect(ClientMedicationAdministration::count())->toBe(0);
});

it('rejects workers without medications.administer.record permission', function () {
    $worker = User::factory()->frontlineWorker()->create();
    $client = Client::factory()->create();
    $client->supportWorkers()->attach($worker->id);
    $medication = ClientMedication::factory()->create(['client_id' => $client->id]);

    $this->actingAs($worker)
        ->post("/my-day/medications/{$medication->id}/administer")
        ->assertForbidden();
});

/**
 * Build a worker assigned to a client + an active medication for that client.
 *
 * Attaches the `medications.administer.record` permission as an allow override
 * so we don't need to seed the full role hierarchy, AND pivots the client/
 * worker via `support_workers` so the ClientPolicy::view check passes.
 */
function makeWorkerAndMedication(array $medicationOverrides = []): array
{
    $worker = User::factory()->frontlineWorker()->create();

    // Two permissions are needed:
    //   - clients.viewAssigned so ClientPolicy::view passes (worker is assigned
    //     to the client via the support_workers pivot below)
    //   - medications.administer.record so the controller's canDo() check passes
    $overrides = [];
    foreach (['clients.viewAssigned', 'medications.administer.record'] as $key) {
        $permission = Permission::query()->firstOrCreate(
            ['key' => $key],
            ['description' => $key],
        );
        $overrides[$permission->id] = ['allowed' => true];
    }
    $worker->permissionOverrides()->syncWithoutDetaching($overrides);

    $client = Client::factory()->create();
    $client->supportWorkers()->attach($worker->id);

    $medication = ClientMedication::factory()->create(array_merge([
        'client_id' => $client->id,
        'name' => 'Donepezil',
        'dosage' => '5 mg',
        'active' => true,
        'state' => 'active',
        'approval_status' => 'verified',
        'start_date' => Carbon::parse('2026-05-01', 'Pacific/Auckland')->toDateString(),
        'end_date' => null,
    ], $medicationOverrides));

    return [$worker, $medication];
}

function makeMedicationWitness(): User
{
    $witness = User::factory()->frontlineWorker()->create();
    $permission = Permission::query()->firstOrCreate(
        ['key' => 'medications.controlled.witness'],
        ['description' => 'medications.controlled.witness'],
    );
    $witness->permissionOverrides()->syncWithoutDetaching([
        $permission->id => ['allowed' => true],
    ]);

    return $witness;
}
