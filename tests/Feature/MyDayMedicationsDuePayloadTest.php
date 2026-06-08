<?php

use App\Models\Client;
use App\Models\ClientMedication;
use App\Models\ClientMedicationAdministration;
use App\Models\Shift;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

beforeEach(function () {
    Cache::flush();
    // 10:00 NZ — dose slots at 09:00 and 13:00 both land inside the
    // controller's visibility window (now-2h .. now+4h => 08:00 .. 14:00).
    Carbon::setTestNow(Carbon::parse('2026-05-21 10:00:00', 'Pacific/Auckland'));
});

afterEach(function () {
    Cache::flush();
    Carbon::setTestNow();
});

it('emits a distinct medications_due row per in-window dose slot', function () {
    [$worker, $client] = makeWorkerWithMyDayMedicationClient();

    $med = ClientMedication::factory()->create([
        'client_id' => $client->id,
        'name' => 'Paracetamol',
        'dosage' => '500 mg',
        'is_prn' => false,
        'active' => true,
        'state' => 'active',
        'start_date' => '2026-05-01',
        'end_date' => null,
        'dose_times' => ['09:00', '13:00'],
    ]);

    // Mirror the controller's slot maths exactly so the assertion is immune to
    // NZ DST / offset changes.
    $tz = config('app.worker_timezone', 'Pacific/Auckland');
    $startOfDay = Carbon::now($tz)->startOfDay();
    $iso0900 = $startOfDay->copy()->setTimeFromTimeString('09:00')->toIso8601String();
    $iso1300 = $startOfDay->copy()->setTimeFromTimeString('13:00')->toIso8601String();

    $this->actingAs($worker)
        ->get('/my-day')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('my-day/index')
            // Two in-window slots for one medication => two rows (no collapse).
            ->has('medications_due', 2)
            // Sorted overdue-first: 09:00 (overdue at 10:00) then 13:00 (upcoming).
            ->where('medications_due.0.medication_id', $med->id)
            ->where('medications_due.1.medication_id', $med->id)
            ->where('medications_due.0.scheduled_for', $iso0900)
            ->where('medications_due.1.scheduled_for', $iso1300)
            // The id is the per-occurrence compound, distinct across the slots,
            // so React keys never collide and mutations target one dose.
            ->where('medications_due.0.id', $med->id.':'.$iso0900)
            ->where('medications_due.1.id', $med->id.':'.$iso1300)
            ->where('medications_due.0.status', 'overdue')
            ->where('medications_due.1.status', 'upcoming')
        );
})->group('my-day');

it('marks a My Day medication slot as given when an administration exists for that slot', function () {
    [$worker, $client] = makeWorkerWithMyDayMedicationClient();
    $med = ClientMedication::factory()->create([
        'client_id' => $client->id,
        'name' => 'Paracetamol',
        'dosage' => '500 mg',
        'is_prn' => false,
        'active' => true,
        'state' => 'active',
        'start_date' => '2026-05-01',
        'end_date' => null,
        'dose_times' => ['09:00'],
    ]);

    $scheduledFor = Carbon::now(config('app.worker_timezone', 'Pacific/Auckland'))
        ->startOfDay()
        ->setTimeFromTimeString('09:00');
    ClientMedicationAdministration::query()->create([
        'client_id' => $client->id,
        'client_medication_id' => $med->id,
        'administered_by' => $worker->id,
        'scheduled_for' => $scheduledFor->copy()->utc(),
        'administered_at' => $scheduledFor->copy()->addMinutes(5)->utc(),
        'status' => 'given',
        'dose_given' => '500 mg',
    ]);

    $this->actingAs($worker)
        ->get('/my-day')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('my-day/index')
            ->has('medications_due', 1)
            ->where('medications_due.0.medication_id', $med->id)
            ->where('medications_due.0.scheduled_for', $scheduledFor->toIso8601String())
            ->where('medications_due.0.status', 'given')
        );
})->group('my-day');

it('hides a My Day medication slot while the worker snooze cache key is active', function () {
    Cache::flush();
    [$worker, $client] = makeWorkerWithMyDayMedicationClient();
    $med = ClientMedication::factory()->create([
        'client_id' => $client->id,
        'name' => 'Paracetamol',
        'dosage' => '500 mg',
        'is_prn' => false,
        'active' => true,
        'state' => 'active',
        'start_date' => '2026-05-01',
        'end_date' => null,
        'dose_times' => ['09:00', '13:00'],
    ]);

    $scheduledFor = Carbon::now(config('app.worker_timezone', 'Pacific/Auckland'))
        ->startOfDay()
        ->setTimeFromTimeString('09:00')
        ->toIso8601String();

    Cache::put(
        "my-day.med-snooze.user-{$worker->id}.med-{$med->id}.{$scheduledFor}",
        true,
        now()->addMinutes(15),
    );

    $this->actingAs($worker)
        ->get('/my-day')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('my-day/index')
            ->has('medications_due', 1)
            ->where('medications_due.0.medication_id', $med->id)
            ->where('medications_due.0.status', 'upcoming')
        );
})->group('my-day');

function makeWorkerWithMyDayMedicationClient(): array
{
    $worker = User::factory()->frontlineWorker()->create();
    $client = Client::factory()->create();

    // A visible shift today routes this client into the medications-due
    // builder. No site_id on the shift => the active-site path is skipped and
    // the client comes through the shift list (simpler, deterministic setup).
    Shift::factory()->assignedToday($worker)->published()->create([
        'client_id' => $client->id,
    ]);

    return [$worker, $client];
}
