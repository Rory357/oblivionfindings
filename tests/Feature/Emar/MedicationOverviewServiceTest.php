<?php

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Models\Client;
use App\Models\ClientControlledDrugDiscrepancy;
use App\Models\ClientControlledDrugEntry;
use App\Models\ClientInrRecord;
use App\Models\ClientMedication;
use App\Models\MedicationReview;
use App\Models\Permission;
use App\Models\Site;
use App\Models\User;
use App\Services\MedicationOverviewService;
use Database\Seeders\RbacSeeder;
use Illuminate\Support\Carbon;

beforeEach(function () {
    Carbon::setTestNow(Carbon::parse('2026-06-14 11:15:00', 'Pacific/Auckland')->utc());
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

it('labels an INR reading with no medicine linked instead of hiding it', function () {
    $user = User::factory()->create();
    $client = makeOverviewClient();
    $linkedClient = Client::factory()->create([
        'site_id' => $client->site_id,
        'first_name' => 'Linked',
        'last_name' => 'Reading',
    ]);
    $warfarin = ClientMedication::factory()->create([
        'client_id' => $linkedClient->id,
        'name' => 'Warfarin',
        'controlled_drug' => false,
    ]);
    foreach ([[$client, null], [$linkedClient, $warfarin->id]] as [$owner, $medicationId]) {
        ClientInrRecord::create([
            'client_id' => $owner->id,
            'client_medication_id' => $medicationId,
            'inr_value' => 4.8,
            'target_range_low' => 2.0,
            'target_range_high' => 3.0,
            'tested_on' => today()->toDateString(),
            'recorded_by' => $user->id,
        ]);
    }

    $service = app(MedicationOverviewService::class);
    $inr = collect($service->actionCentre(today()))
        ->where('type', 'inr')
        ->keyBy('client_id');
    // The /emar watch card labels the reading from its missing medicine link.
    $watch = collect($service->inrWatch())->keyBy('client_id');

    expect($inr->keys()->sort()->values()->all())->toBe([$client->id, $linkedClient->id])
        ->and($inr[$client->id]['summary'])->toStartWith('No medicine linked · Target 2–3')
        ->and($inr[$linkedClient->id]['summary'])->toStartWith('Target 2–3')
        ->and($inr[$linkedClient->id]['summary'])->not->toContain('No medicine linked')
        ->and($watch->keys()->sort()->values()->all())->toBe([$client->id, $linkedClient->id])
        ->and($watch[$client->id]['client_medication_id'])->toBeNull()
        ->and($watch[$linkedClient->id]['client_medication_id'])->toBe($warfarin->id);
});

it('keeps controlled, cross-client and other-Site INR readings concealed from a scoped reader', function () {
    $this->seed(RbacSeeder::class);
    $recorder = User::factory()->create();
    $visible = makeOverviewClient();
    $controlledOwner = Client::factory()->create(['site_id' => $visible->site_id]);
    $crossLinked = Client::factory()->create(['site_id' => $visible->site_id]);
    $otherSite = Client::factory()->create(['site_id' => Site::factory()->create()->id]);
    $morphine = ClientMedication::factory()->create([
        'client_id' => $controlledOwner->id,
        'name' => 'Morphine sulfate',
        'controlled_drug' => true,
    ]);
    $visibleWarfarin = ClientMedication::factory()->create([
        'client_id' => $visible->id,
        'name' => 'Warfarin',
        'controlled_drug' => false,
    ]);
    foreach ([
        [$visible, null],
        [$controlledOwner, $morphine->id],
        // Linked to another resident's order — not canonical, stays hidden.
        [$crossLinked, $visibleWarfarin->id],
        [$otherSite, null],
    ] as [$owner, $medicationId]) {
        ClientInrRecord::create([
            'client_id' => $owner->id,
            'client_medication_id' => $medicationId,
            'inr_value' => 4.8,
            'target_range_low' => 2.0,
            'target_range_high' => 3.0,
            'tested_on' => today()->toDateString(),
            'recorded_by' => $recorder->id,
        ]);
    }

    $reader = User::factory()->create(['role' => 'support_worker', 'approved_at' => now()]);
    $reader->permissionOverrides()->sync([
        Permission::query()->where('key', 'medications.view')->value('id') => ['allowed' => true],
        Permission::query()->where('key', 'medications.controlled.view')->value('id') => ['allowed' => false],
    ]);
    HrEmployeeProfile::factory()->create([
        'user_id' => $reader->id,
        'primary_site_id' => $visible->site_id,
        'secondary_site_ids' => [],
        'start_date' => today()->subYear(),
        'end_date' => null,
        'is_active' => true,
    ]);

    $payload = app(MedicationOverviewService::class)->payload(today(), $reader);

    expect(collect($payload['actionCentre'])->where('type', 'inr')->pluck('client_id')->all())
        ->toBe([$visible->id])
        ->and(collect($payload['inrWatch'])->pluck('client_id')->all())
        ->toBe([$visible->id]);
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

it('reports the admin rate as not applicable when no scheduled dose is eligible yet', function () {
    $payload = app(MedicationOverviewService::class)->payload();

    // No scheduled doses → "n/a" (null), never a reassuring 0 %.
    expect($payload['stats']['adminRate'])->toBeNull()
        ->and($payload['stats']['eligibleToday'])->toBe(0)
        ->and($payload['date'])->toBe('2026-06-14')
        ->and($payload['isToday'])->toBeTrue()
        ->and($payload['nowLabel'])->toBe('11:15 AM');
});

it('attaches a RecordDoseWizard context to an unrecorded past scheduled dose', function () {
    // EM-01: an overdue dose is an unrecorded scheduled slot whose time has
    // passed — no `pending` administration row exists in production.
    $client = makeOverviewClient();
    // Entered this morning, before its 09:00 dose (a dose due before an
    // order's entry is not owed).
    $now = Carbon::getTestNow();
    Carbon::setTestNow(Carbon::now('Pacific/Auckland')->startOfDay()->utc());
    $med = ClientMedication::factory()->create([
        'client_id' => $client->id,
        'name' => 'Clozapine',
        'dosage' => '200 mg',
        'route' => 'Oral',
        'frequency' => 'Daily',
        'dose_times' => ['09:00'],
        // The factory's random start/end dates can fall outside today.
        'start_date' => null,
        'end_date' => null,
        'controlled_drug' => false,
        'is_prn' => false,
        'active' => true,
        'state' => 'active',
    ]);
    Carbon::setTestNow($now);

    $service = app(MedicationOverviewService::class);
    $workerToday = Carbon::now('Pacific/Auckland')->startOfDay();
    $feed = $service->actionCentre($workerToday);
    $dose = collect($feed)->firstWhere('type', 'overdue_dose');

    expect($dose)->not->toBeNull()
        ->and($dose['action_type'])->toBe('record')
        ->and($dose['record']['row']['medication_id'])->toBe($med->id)
        ->and($dose['record']['row']['medication_name'])->toBe('Clozapine')
        ->and($dose['record']['row']['client_name'])->toBe('Margaret Sole')
        ->and($dose['record']['row']['status'])->toBe('overdue')
        ->and($dose['record']['row']['time'])->toBe('09:00')
        ->and($dose['record']['client']['name'])->toBe('Margaret Sole')
        ->and($dose['record']['client']['allergies'])->toBe([])
        ->and($dose['record']['client']['allergy_status'])->toBe('none_recorded');

    $stats = $service->stats($workerToday);
    expect($stats['overdue'])->toBe(1)
        ->and($stats['dueNow'])->toBe(1)
        ->and($stats['adminRate'])->toBe(0.0);
});
