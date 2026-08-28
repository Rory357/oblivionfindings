<?php

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Http\Middleware\HandleInertiaRequests;
use App\Models\Client;
use App\Models\ClientMedication;
use App\Models\ClientMedicationAdministration;
use App\Models\MedicationRound;
use App\Models\Permission;
use App\Models\Shift;
use App\Models\Site;
use App\Models\User;
use App\Support\EmarUrl;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

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
    $emarUrl = EmarUrl::mar($client->id, $startOfDay->toDateString());

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
            ->where('can_open_emar', true)
            ->where('medications_due.0.emar_url', $emarUrl)
        );
})->group('my-day');

it('keeps record-only workers on Meds Today without exposing a dead admin eMAR link', function () {
    $worker = User::factory()->frontlineWorker()->create();
    $recordPermission = Permission::query()->firstOrCreate(
        ['key' => 'medications.administer.record'],
        ['description' => 'medications.administer.record'],
    );
    $worker->permissionOverrides()->syncWithoutDetaching([
        $recordPermission->id => ['allowed' => true],
    ]);
    $client = Client::factory()->create();
    Shift::factory()->assignedToday($worker)->published()->create([
        'client_id' => $client->id,
    ]);
    ClientMedication::factory()->create([
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
    ClientMedication::factory()->create([
        'client_id' => $client->id,
        'name' => 'PRIVATE CONTROLLED MY DAY MEDICATION',
        'dosage' => '5 mg',
        'is_prn' => false,
        'controlled_drug' => true,
        'active' => true,
        'state' => 'active',
        'start_date' => '2026-05-01',
        'end_date' => null,
        'dose_times' => ['09:00'],
    ]);

    $response = $this->actingAs($worker)
        ->get('/my-day')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('my-day/index')
            ->has('medications_due', 1)
            ->where('can_view_medications', true)
            ->where('can_record_medications', true)
            ->where('can_open_emar', false)
            ->where('medications_due.0.can_record', true)
            ->where('medications_due.0.emar_url', null)
        );
    expect($response->getContent())->not->toContain('PRIVATE CONTROLLED MY DAY MEDICATION');
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

it('matches every dose slot with a single administration query (no N+1)', function () {
    [$worker, $client] = makeWorkerWithMyDayMedicationClient();

    // 3 meds × 2 in-window dose times = 6 slots for one resident. Before F1 the
    // rail issued one ClientMedicationAdministration query per slot, re-run on
    // every 60s live refresh; now it must be a single query for the window.
    foreach (['Paracetamol', 'Metformin', 'Aspirin'] as $name) {
        ClientMedication::factory()->create([
            'client_id' => $client->id,
            'name' => $name,
            'is_prn' => false,
            'active' => true,
            'state' => 'active',
            'start_date' => '2026-05-01',
            'end_date' => null,
            'dose_times' => ['09:00', '13:00'],
        ]);
    }

    // Isolate the rail's batching contract from the independently cached
    // navigation badge. A cold badge cache intentionally performs its own
    // day-wide administration read before sharing the Inertia navigation prop.
    Cache::put(
        HandleInertiaRequests::medsOverdueBadgeCacheKey(
            $worker->id,
            Carbon::now(config('app.worker_timezone', 'Pacific/Auckland'))->toDateString(),
        ),
        0,
        now()->addMinute(),
    );

    DB::flushQueryLog();
    DB::enableQueryLog();

    try {
        $this->actingAs($worker)
            ->get('/my-day')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->has('medications_due', 6));

        $adminQueries = collect(DB::getQueryLog())
            ->filter(fn ($entry) => str_contains($entry['query'], 'client_medication_administrations'))
            ->count();
    } finally {
        DB::disableQueryLog();
        DB::flushQueryLog();
    }

    expect($adminQueries)->toBe(1);
})->group('my-day');

it('does not disclose shift medications without an exact medication capability', function () {
    $worker = User::factory()->frontlineWorker()->create();
    $client = Client::factory()->create();
    Shift::factory()->assignedToday($worker)->published()->create([
        'client_id' => $client->id,
    ]);
    ClientMedication::factory()->create([
        'client_id' => $client->id,
        'name' => 'Private shift medication',
        'is_prn' => false,
        'active' => true,
        'state' => 'active',
        'start_date' => '2026-05-01',
        'end_date' => null,
        'dose_times' => ['09:00'],
    ]);

    $this->actingAs($worker)
        ->get('/my-day')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('my-day/index')
            ->has('medications_due', 0)
            ->where('stats.meds_due', 0)
            ->where('stats.meds_overdue', 0)
            ->where('can_view_medications', false)
            ->where('can_record_medications', false)
            ->where('can_record_controlled_medications', false)
            ->where('can_open_emar', false)
            ->where('active_round', null)
        );
})->group('my-day');

it('only serializes an active medication round with canonical accessible Site provenance', function () {
    $worker = User::factory()->frontlineWorker()->create();
    $recordPermission = Permission::query()->firstOrCreate(
        ['key' => 'medications.administer.record'],
        ['description' => 'medications.administer.record'],
    );
    $worker->permissionOverrides()->syncWithoutDetaching([
        $recordPermission->id => ['allowed' => true],
    ]);

    $localSite = Site::factory()->create();
    $foreignSite = Site::factory()->create();
    HrEmployeeProfile::factory()->create([
        'user_id' => $worker->id,
        'primary_site_id' => $localSite->id,
        'secondary_site_ids' => [],
        'is_active' => true,
        'start_date' => now()->subMonth()->toDateString(),
        'end_date' => null,
    ]);

    foreach ([$localSite, $foreignSite] as $site) {
        $client = Client::factory()->create(['site_id' => $site->id]);
        ClientMedication::factory()->create([
            'client_id' => $client->id,
            'name' => 'Scheduled medicine for '.$site->name,
            'is_prn' => false,
            'active' => true,
            'state' => 'active',
            'approval_status' => 'verified',
            'start_date' => '2026-05-01',
            'end_date' => null,
            'dose_times' => ['09:00'],
        ]);
    }

    $roundDate = Carbon::now(config('app.worker_timezone', 'Pacific/Auckland'))->toDateString();
    $localRound = MedicationRound::query()->create([
        'site_id' => $localSite->id,
        'name' => 'Accessible local round',
        'scheduled_time' => '09:00',
        'window_minutes' => 60,
        'round_date' => $roundDate,
        'status' => 'pending',
        'assigned_to' => $worker->id,
    ]);
    $nullSiteRound = MedicationRound::query()->create([
        'site_id' => null,
        'name' => 'CONCEALED NULL SITE ROUND',
        'scheduled_time' => '08:00',
        'window_minutes' => 60,
        'round_date' => $roundDate,
        'status' => 'in_progress',
        'assigned_to' => $worker->id,
        'started_by' => $worker->id,
        'started_at' => now(),
    ]);
    $foreignRound = MedicationRound::query()->create([
        'site_id' => $foreignSite->id,
        'name' => 'CONCEALED FOREIGN SITE ROUND',
        'scheduled_time' => '10:00',
        'window_minutes' => 60,
        'round_date' => $roundDate,
        'status' => 'pending',
        'assigned_to' => $worker->id,
    ]);

    $nullSiteResponse = $this->actingAs($worker)
        ->get('/my-day')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('active_round.id', fn ($id): bool => (int) $id === $localRound->id)
            ->where('active_round.name', 'Accessible local round')
        );
    expect($nullSiteResponse->getContent())->not->toContain('CONCEALED NULL SITE ROUND');

    $nullSiteRound->update(['status' => 'completed']);
    $foreignRound->update([
        'status' => 'in_progress',
        'started_by' => $worker->id,
        'started_at' => now(),
    ]);

    $foreignSiteResponse = $this->actingAs($worker)
        ->get('/my-day')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('active_round.id', fn ($id): bool => (int) $id === $localRound->id)
            ->where('active_round.name', 'Accessible local round')
        );
    expect($foreignSiteResponse->getContent())->not->toContain('CONCEALED FOREIGN SITE ROUND');
})->group('my-day');

function makeWorkerWithMyDayMedicationClient(): array
{
    $worker = User::factory()->frontlineWorker()->create();
    $permission = Permission::query()->firstOrCreate(
        ['key' => 'medications.view'],
        ['description' => 'medications.view'],
    );
    $worker->permissionOverrides()->syncWithoutDetaching([
        $permission->id => ['allowed' => true],
    ]);
    $client = Client::factory()->create();

    // A visible shift today routes this client into the medications-due
    // builder. No site_id on the shift => the active-site path is skipped and
    // the client comes through the shift list (simpler, deterministic setup).
    Shift::factory()->assignedToday($worker)->published()->create([
        'client_id' => $client->id,
    ]);

    return [$worker, $client];
}
