<?php

use App\Models\Client;
use App\Models\ClientControlledDrugDiscrepancy;
use App\Models\ClientControlledDrugEntry;
use App\Models\ClientInrRecord;
use App\Models\ClientMedication;
use App\Models\ClientMedicationAdministration;
use App\Models\MedicationReview;
use App\Models\Site;
use App\Models\User;
use App\Services\MedicationOverviewService;
use Illuminate\Support\Carbon;

beforeEach(function () {
    Carbon::setTestNow(Carbon::parse('2026-06-14 11:15:00', 'Pacific/Auckland'));
});

afterEach(function () {
    Carbon::setTestNow();
});

function makeOverviewClient(): Client
{
    $site = Site::factory()->create(['name' => 'Kōwhai House']);

    return Client::factory()->create([
        'site_id' => $site->id,
        'first_name' => 'Margaret',
        'last_name' => 'Sole',
    ]);
}

it('surfaces an INR-out-of-range item in the action centre', function () {
    $user = User::factory()->create();
    $client = makeOverviewClient();

    ClientInrRecord::create([
        'client_id' => $client->id,
        'inr_value' => 4.8,
        'target_range_low' => 2.0,
        'target_range_high' => 3.0,
        'tested_on' => today()->toDateString(),
        'recorded_by' => $user->id,
    ]);

    $feed = app(MedicationOverviewService::class)->actionCentre(today());

    $inr = collect($feed)->firstWhere('type', 'inr');

    expect($inr)->not->toBeNull()
        ->and($inr['severity'])->toBe('critical')
        ->and($inr['status'])->toBe('Above range')
        ->and($inr['code'])->toBe('INR')
        ->and($inr['client'])->toBe('Margaret Sole');
});

it('surfaces an open CD discrepancy in the action centre', function () {
    $user = User::factory()->create();
    $client = makeOverviewClient();
    $medication = ClientMedication::factory()->create([
        'client_id' => $client->id,
        'name' => 'Morphine sulfate',
        'controlled_drug' => true,
    ]);

    ClientControlledDrugDiscrepancy::create([
        'client_id' => $client->id,
        'client_medication_id' => $medication->id,
        'on_hand_before' => 20,
        'on_hand_after' => 18,
        'difference' => -2,
        'reason' => 'Count mismatch at handover',
        'reported_at' => now(),
        'reported_by' => $user->id,
        'status' => 'open',
    ]);

    $feed = app(MedicationOverviewService::class)->actionCentre(today());

    $cd = collect($feed)->firstWhere('type', 'cd_discrepancy');

    expect($cd)->not->toBeNull()
        ->and($cd['severity'])->toBe('critical')
        ->and($cd['category'])->toBe('controlled')
        ->and($cd['code'])->toBe('CD');
});

it('keeps med cd scope balance checks due when today entry has noncanonical ownership', function () {
    $user = User::factory()->create();
    $owner = makeOverviewClient();
    $otherClient = Client::factory()->create([
        'site_id' => $owner->site_id,
        'first_name' => 'Other',
        'last_name' => 'Resident',
    ]);
    $medication = ClientMedication::factory()->create([
        'client_id' => $owner->id,
        'name' => 'Morphine sulfate',
        'controlled_drug' => true,
        'active' => true,
        'state' => 'active',
    ]);
    ClientControlledDrugEntry::query()->create([
        'client_id' => $otherClient->id,
        'client_medication_id' => $medication->id,
        'entry_type' => 'balance_check',
        'on_hand_before' => '10.00',
        'on_hand_after' => '10.00',
        'recorded_at' => now(),
        'recorded_by' => $user->id,
    ]);

    $feed = app(MedicationOverviewService::class)->actionCentre(today());
    $due = collect($feed)->firstWhere('id', 'cdbal-'.$medication->id);

    expect($due)->not->toBeNull()
        ->and($due['type'])->toBe('cd_balance')
        ->and($due['client_id'])->toBe($owner->id)
        ->and($due['summary'])->toContain('no balance count recorded today');
});

it('surfaces an overdue medication review in the action centre', function () {
    $client = makeOverviewClient();

    MedicationReview::create([
        'client_id' => $client->id,
        'review_type' => 'Routine 6-monthly',
        'status' => 'scheduled',
        'scheduled_date' => today()->subDays(3)->toDateString(),
    ]);

    $feed = app(MedicationOverviewService::class)->actionCentre(today());

    $review = collect($feed)->firstWhere('type', 'review');

    expect($review)->not->toBeNull()
        ->and($review['code'])->toBe('REV')
        ->and($review['action_type'])->toBe('complete_review')
        ->and($review['status'])->toBe('Review overdue');
});

it('sorts the action-centre feed by severity (critical before warning before info)', function () {
    $user = User::factory()->create();
    $client = makeOverviewClient();

    // critical INR
    ClientInrRecord::create([
        'client_id' => $client->id,
        'inr_value' => 4.8,
        'target_range_low' => 2.0,
        'target_range_high' => 3.0,
        'tested_on' => today()->toDateString(),
        'recorded_by' => $user->id,
    ]);

    // warning review
    MedicationReview::create([
        'client_id' => $client->id,
        'review_type' => 'Routine',
        'status' => 'scheduled',
        'scheduled_date' => today()->subDays(2)->toDateString(),
    ]);

    $feed = app(MedicationOverviewService::class)->actionCentre(today());
    $ranks = array_map(fn ($i) => $i['severity'], $feed);

    // first item must be critical, and no warning may precede any critical
    $firstCriticalIdx = array_search('critical', $ranks, true);
    $lastCriticalIdx = max(array_keys($ranks, 'critical'));
    $firstWarningIdx = array_search('warning', $ranks, true);

    expect($firstCriticalIdx)->toBe(0);
    if ($firstWarningIdx !== false) {
        expect($lastCriticalIdx)->toBeLessThan($firstWarningIdx);
    }
});

it('builds a complete dashboard payload with all merged keys', function () {
    makeOverviewClient();

    $payload = app(MedicationOverviewService::class)->payload(today());

    expect($payload)->toHaveKeys([
        'date', 'isToday', 'dateTitle', 'nowLabel',
        'stats', 'trend', 'complianceTrend', 'outcomeBreakdown',
        'codedNotGivenReasons', 'actionCentre', 'clientBoard',
        'inrWatch', 'syringeDrivers', 'reviewsDue', 'medicationErrors',
        'overdueMedications', 'recentActivity',
    ]);

    expect($payload['stats'])->toHaveKeys([
        'adminRate', 'dueNow', 'overdue', 'cdDue', 'reviewsDue',
        'competenciesExpiring', 'stockAlerts',
    ]);

    expect($payload['medicationErrors'])->toHaveKeys(['open', 'byType', 'trend']);
    expect($payload['outcomeBreakdown'])->toHaveKeys(['total', 'givenPct', 'segments']);
});

it('computes admin rate from the day\'s administrations', function () {
    $payload = app(MedicationOverviewService::class)->payload(today());

    // No administrations seeded → rate is 0, not a divide-by-zero error.
    expect($payload['stats']['adminRate'])->toBe(0.0);
});

it('attaches a RecordDoseWizard context to overdue dose action items', function () {
    $client = makeOverviewClient();
    $med = ClientMedication::factory()->create([
        'client_id' => $client->id,
        'name' => 'Clozapine',
        'dosage' => '200 mg',
        'route' => 'Oral',
        'controlled_drug' => false,
        'is_prn' => false,
    ]);

    ClientMedicationAdministration::create([
        'client_id' => $client->id,
        'client_medication_id' => $med->id,
        'status' => 'pending',
        'scheduled_for' => today()->setTime(9, 0),
        'administered_by' => User::factory()->create()->id,
    ]);

    $feed = app(MedicationOverviewService::class)->actionCentre(today());
    $dose = collect($feed)->firstWhere('type', 'overdue_dose');

    expect($dose)->not->toBeNull()
        ->and($dose['action_type'])->toBe('record')
        ->and($dose['record']['row']['medication_id'])->toBe($med->id)
        ->and($dose['record']['row']['medication_name'])->toBe('Clozapine')
        ->and($dose['record']['row']['client_name'])->toBe('Margaret Sole')
        ->and($dose['record']['row']['status'])->toBe('overdue')
        ->and($dose['record']['client']['name'])->toBe('Margaret Sole')
        ->and($dose['record']['client']['allergies'])->toBe([]);
});
