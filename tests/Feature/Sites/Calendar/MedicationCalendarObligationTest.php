<?php

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Models\Client;
use App\Models\ClientMedication;
use App\Models\ClientMedicationStock;
use App\Models\MedicationReview;
use App\Models\MedicationStockLot;
use App\Models\Permission;
use App\Models\Site;
use App\Models\User;
use App\Services\Sites\Calendar\SiteCalendarAggregator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;

uses(RefreshDatabase::class);

/** Establish the calendar reader's exact capability, approved Site and person assignment. */
function medicationCalendarReader(Site $site, Client $client): User
{
    $reader = User::factory()->frontlineWorker()->create(['approved_at' => now()]);
    HrEmployeeProfile::factory()->create([
        'user_id' => $reader->id,
        'primary_site_id' => $site->id,
        'secondary_site_ids' => [],
        'start_date' => today()->subMonth(),
        'end_date' => null,
        'is_active' => true,
    ]);
    foreach (['medications.view', 'clients.viewAssigned'] as $key) {
        $permission = Permission::query()->firstOrCreate(['key' => $key], ['description' => $key]);
        $reader->permissionOverrides()->syncWithoutDetaching([$permission->id => ['allowed' => true]]);
    }
    $client->supportWorkers()->attach($reader->id);

    return $reader;
}

function medicationCalendarOrder(Client $client): ClientMedication
{
    return ClientMedication::query()->create([
        'client_id' => $client->id,
        'name' => 'Warfarin',
        'dosage' => '3mg',
        'frequency' => 'Once daily',
        'controlled_drug' => false,
        'approval_status' => 'verified',
        'active' => true,
        'state' => 'active',
    ]);
}

function medicationCalendarLot(ClientMedicationStock $stock, User $receiver, string $batch, string $expiry, array $extra = []): MedicationStockLot
{
    return MedicationStockLot::query()->create($extra + [
        'client_medication_stock_id' => $stock->id, 'batch_number' => $batch, 'expiry_date' => $expiry,
        'quantity_received' => 10, 'quantity_remaining' => 10, 'state' => 'open',
        'source' => 'pharmacy', 'received_by' => $receiver->id, 'received_at' => now(), 'revision' => 1,
    ]);
}

/** Aggregate the medication source over a wide today-anchored window. */
function aggregateMedication(array $siteIds): Collection
{
    return collect(app(SiteCalendarAggregator::class)->itemsForRange(
        $siteIds,
        now()->subMonth(),
        now()->addMonths(4),
        ['sources' => ['medication']],
    ));
}

test('a scheduled medication review surfaces on the home calendar', function () {
    $site = Site::factory()->create(['type' => 'house']);
    $client = Client::factory()->create(['site_id' => $site->id, 'last_name' => 'Ngata']);
    $this->actingAs(medicationCalendarReader($site, $client));
    medicationCalendarOrder($client);

    $scheduled = MedicationReview::create([
        'client_id' => $client->id,
        'review_type' => 'annual',
        'status' => 'scheduled',
        'scheduled_date' => now()->addDays(10)->toDateString(),
    ]);

    // A completed review must not appear.
    MedicationReview::create([
        'client_id' => $client->id,
        'review_type' => 'annual',
        'status' => 'completed',
        'scheduled_date' => now()->addDays(12)->toDateString(),
        'completed_date' => now()->toDateString(),
    ]);

    $items = aggregateMedication([$site->id]);

    expect($items)->toHaveCount(1);
    $review = $items->first();
    expect($review->source)->toBe('medication');
    expect($review->group)->toBe('auto');
    expect($review->allDay)->toBeTrue();
    expect($review->title)->toContain('Ngata');
    expect($review->title)->toContain('Medication review due');
    expect(parse_url($review->link, PHP_URL_PATH))->toBe('/emar/reviews');
    parse_str(parse_url($review->link, PHP_URL_QUERY), $query);
    expect($query)->toBe(['review' => (string) $scheduled->id, 'client_id' => (string) $client->id, 'site_id' => (string) $site->id]);
    $this->get($review->link)->assertOk();
    expect($review->site['id'])->toBe($site->id);
});

test('an active medication stock expiry surfaces on the home calendar', function () {
    $site = Site::factory()->create(['type' => 'house']);
    $client = Client::factory()->create(['site_id' => $site->id]);
    $this->actingAs(medicationCalendarReader($site, $client));

    $medication = medicationCalendarOrder($client);

    ClientMedicationStock::create([
        'client_medication_id' => $medication->id,
        'on_hand' => 30,
        'unit' => 'tablets',
        'expiry_date' => now()->addDays(20)->toDateString(),
    ]);

    $items = aggregateMedication([$site->id]);

    expect($items)->toHaveCount(1);
    $stock = $items->first();
    expect($stock->source)->toBe('medication');
    expect($stock->title)->toContain('Warfarin');
    expect($stock->title)->toContain('Stock expires');
    expect(parse_url($stock->link, PHP_URL_PATH))->toBe('/emar/mar');
    parse_str(parse_url($stock->link, PHP_URL_QUERY), $query);
    expect($query)->toBe([
        'site_id' => (string) $site->id, 'client_id' => (string) $client->id,
        'medication_id' => (string) $medication->id, 'tab' => 'medicines',
    ]);
    // The expiry can be weeks away; it is not a future administration day.
    expect($query)->not->toHaveKey('date');
    $this->get($stock->link)->assertOk();
});

test('a stock updater opens the exact authorized medicine pack from its calendar expiry', function () {
    $site = Site::factory()->create(['type' => 'house']);
    $client = Client::factory()->create(['site_id' => $site->id]);
    $reader = medicationCalendarReader($site, $client);
    $permission = Permission::query()->firstOrCreate(['key' => 'medications.stock.update'], ['description' => 'Update medication stock']);
    $reader->permissionOverrides()->syncWithoutDetaching([$permission->id => ['allowed' => true]]);
    $this->actingAs($reader->fresh());
    $medication = medicationCalendarOrder($client);
    ClientMedicationStock::create([
        'client_medication_id' => $medication->id, 'on_hand' => 30, 'unit' => 'tablets',
        'expiry_date' => now()->addDays(20)->toDateString(),
    ]);

    $stock = aggregateMedication([$site->id])->first();
    expect(parse_url($stock->link, PHP_URL_PATH))->toBe('/emar/stock/packs');
    parse_str(parse_url($stock->link, PHP_URL_QUERY), $query);
    expect($query)->toBe([
        'site_id' => (string) $site->id, 'client_id' => (string) $client->id,
        'medication_id' => (string) $medication->id,
    ]);
    $this->get($stock->link)->assertOk();
});

test('pack calendars use each retained batch expiry and omit stale scalar depleted and quarantined evidence', function () {
    $site = Site::factory()->create(['type' => 'house']);
    $client = Client::factory()->create(['site_id' => $site->id]);
    $reader = medicationCalendarReader($site, $client);
    $this->actingAs($reader);
    $medicine = medicationCalendarOrder($client);
    $stock = ClientMedicationStock::create([
        'client_medication_id' => $medicine->id, 'on_hand' => 30, 'unit' => 'tablets',
        'expiry_date' => today()->addDays(5), 'batch_number' => 'STALE-SCALAR',
    ]);
    $stock->forceFill(['lots_started_at' => now()])->saveOrFail();
    $first = medicationCalendarLot($stock, $reader, 'ACTUAL-A', today()->addDays(10)->toDateString());
    $second = medicationCalendarLot($stock, $reader, 'ACTUAL-B', today()->addDays(15)->toDateString());
    medicationCalendarLot($stock, $reader, 'DEPLETED', today()->addDays(8)->toDateString(), ['quantity_remaining' => 0]);
    medicationCalendarLot($stock, $reader, 'QUARANTINED', today()->addDays(8)->toDateString(), ['state' => 'quarantined']);

    $items = aggregateMedication([$site->id]);
    expect($items)->toHaveCount(2)->and($items->pluck('ref')->sort()->values()->all())->toBe(['ACTUAL-A', 'ACTUAL-B']);
    foreach ([$first, $second] as $lot) {
        $item = $items->firstWhere('id', 'medication-stock-lot-'.$lot->id);
        expect($item->title)->toContain('Batch '.$lot->batch_number)->not->toContain('STALE-SCALAR');
        expect(substr($item->start, 0, 10))->toBe($lot->expiry_date->toDateString());
        expect($item->desc)->toContain('10.00 tablets');
        expect(parse_url($item->link, PHP_URL_PATH))->toBe('/emar/mar');
    }
    $first->update(['quantity_remaining' => 0]);
    $second->update(['state' => 'quarantined']);
    expect(aggregateMedication([$site->id]))->toBeEmpty();
});

test('pack expiry links focus the exact lot and deny a contradictory person medicine house or lot', function () {
    $site = Site::factory()->create(['type' => 'house']);
    $client = Client::factory()->create(['site_id' => $site->id]);
    $reader = medicationCalendarReader($site, $client);
    $permission = Permission::query()->firstOrCreate(['key' => 'medications.stock.update'], ['description' => 'Update medication stock']);
    $reader->permissionOverrides()->syncWithoutDetaching([$permission->id => ['allowed' => true]]);
    $this->actingAs($reader->fresh());
    $medicine = medicationCalendarOrder($client);
    $stock = ClientMedicationStock::create(['client_medication_id' => $medicine->id, 'on_hand' => 20, 'unit' => 'tablets']);
    $stock->forceFill(['lots_started_at' => now()])->saveOrFail();
    $first = medicationCalendarLot($stock, $reader, 'FIRST-BATCH', today()->addDays(10)->toDateString());
    $second = medicationCalendarLot($stock, $reader, 'SECOND-BATCH', today()->addDays(15)->toDateString());
    $items = aggregateMedication([$site->id]);
    expect($items)->toHaveCount(2);
    foreach ([$first, $second] as $lot) {
        $item = $items->firstWhere('id', 'medication-stock-lot-'.$lot->id);
        parse_str(parse_url($item->link, PHP_URL_QUERY), $query);
        expect($query['lot_id'])->toBe((string) $lot->id);
        $this->get($item->link)->assertOk()->assertInertia(fn ($page) => $page
            ->where('focused_lot_id', $lot->id)->where('filters.lot_id', $lot->id)
            ->where('filters.medication_id', $medicine->id)->where('items.total', 1));
    }
    $this->get('/emar/stock/packs?lot_id='.$second->id)->assertOk()->assertInertia(fn ($page) => $page
        ->where('filters.client_id', $client->id)->where('filters.medication_id', $medicine->id)
        ->where('focused_lot_id', $second->id)->where('items.total', 1));
    $other = Client::factory()->create(['site_id' => $site->id]);
    $otherMedicine = medicationCalendarOrder($other);
    foreach ([['client_id' => $other->id], ['medication_id' => $otherMedicine->id],
        ['site_id' => Site::factory()->create(['type' => 'house'])->id], ['lot_id' => 99999999]] as $contradiction) {
        $this->get('/emar/stock/packs?'.http_build_query($contradiction + [
            'client_id' => $client->id, 'site_id' => $site->id, 'medication_id' => $medicine->id, 'lot_id' => $second->id,
        ]))->assertNotFound();
    }
    $this->getJson('/emar/stock/packs?lot_id=bad')->assertUnprocessable()->assertJsonValidationErrors('lot_id');
});

test('medication obligations outside the window are excluded', function () {
    $site = Site::factory()->create(['type' => 'house']);
    $client = Client::factory()->create(['site_id' => $site->id]);
    $this->actingAs(medicationCalendarReader($site, $client));
    medicationCalendarOrder($client);

    MedicationReview::create([
        'client_id' => $client->id,
        'review_type' => 'annual',
        'status' => 'scheduled',
        'scheduled_date' => now()->addMonths(8)->toDateString(),
    ]);

    expect(aggregateMedication([$site->id]))->toBeEmpty();
});

test('calendar medication obligations conceal foreign Sites and same-Site unassigned people', function () {
    $site = Site::factory()->create(['type' => 'house']);
    $foreignSite = Site::factory()->create(['type' => 'house']);
    $assigned = Client::factory()->create(['site_id' => $site->id]);
    $reader = medicationCalendarReader($site, $assigned);
    $this->actingAs($reader);

    foreach ([$assigned, Client::factory()->create(['site_id' => $site->id]), Client::factory()->create(['site_id' => $foreignSite->id])] as $client) {
        $order = medicationCalendarOrder($client);
        MedicationReview::create([
            'client_id' => $client->id,
            'review_type' => 'annual',
            'status' => 'scheduled',
            'scheduled_date' => today()->addDays(10),
        ]);
        ClientMedicationStock::create([
            'client_medication_id' => $order->id,
            'on_hand' => 30,
            'unit' => 'tablets',
            'expiry_date' => today()->addDays(20),
        ]);
    }

    $items = aggregateMedication([$site->id, $foreignSite->id]);
    expect($items)->toHaveCount(2)
        ->and($items->pluck('site.id')->unique()->all())->toBe([$site->id]);
    foreach ($items as $item) {
        expect($item->title)->toContain($assigned->last_name);
    }

    $view = Permission::query()->where('key', 'medications.view')->firstOrFail();
    $reader->permissionOverrides()->syncWithoutDetaching([$view->id => ['allowed' => false]]);
    $this->actingAs($reader->fresh());
    expect(aggregateMedication([$site->id, $foreignSite->id]))->toBeEmpty();
});
