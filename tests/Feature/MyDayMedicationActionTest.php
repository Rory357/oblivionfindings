<?php

use App\Models\Client;
use App\Models\ClientMedication;
use App\Models\ClientMedicationAdministration;
use App\Models\Permission;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

beforeEach(function () {
    Carbon::setTestNow(Carbon::parse('2026-05-21 10:00:00', 'Pacific/Auckland'));
});

afterEach(function () {
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

it('records a refused administration via /my-day/medications/{med}/refuse', function () {
    [$worker, $medication] = makeWorkerAndMedication();

    $this->actingAs($worker)
        ->post("/my-day/medications/{$medication->id}/refuse", [
            'reason' => 'Resident declined this morning.',
        ])
        ->assertRedirect();

    $admin = ClientMedicationAdministration::query()
        ->where('client_medication_id', $medication->id)
        ->where('status', 'refused')
        ->first();

    expect($admin)->not->toBeNull();
    expect($admin->reason)->toBe('Resident declined this morning.');
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
function makeWorkerAndMedication(): array
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

    $medication = ClientMedication::factory()->create([
        'client_id' => $client->id,
        'name' => 'Donepezil',
        'dosage' => '5 mg',
    ]);

    return [$worker, $medication];
}
