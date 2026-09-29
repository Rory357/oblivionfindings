<?php

use App\Models\Client;
use App\Models\ClientInrRecord;
use App\Models\ClientMedication;
use App\Models\MedicationDashboardAlert;
use App\Models\MedicationReview;
use App\Models\Site;
use App\Models\User;
use App\Services\MedicationAlertService;
use App\Services\MedicationSafetyService;
use Illuminate\Support\Carbon;

/*
 * Days remaining on a medication order, and days until an INR test or a
 * review is due, are whole days on the New Zealand calendar. The app clock
 * is UTC, so from midnight to noon in New Zealand the UTC date is still
 * yesterday, and counting from it read one day high every morning.
 */

afterEach(function () {
    Carbon::setTestNow();
});

// 8 June is NZST (UTC+12): at 1 am the UTC date is still 7 June.
dataset('new zealand clock on 8 june', [
    '1 am' => '2026-06-08 01:00:00',
    '2 pm' => '2026-06-08 14:00:00',
    '11:30 pm' => '2026-06-08 23:30:00',
]);

function freezeNzClock(string $nzDateTime): void
{
    Carbon::setTestNow(Carbon::parse($nzDateTime, 'Pacific/Auckland')->utc());
}

function nzCalendarClient(array $attributes = []): Client
{
    $site = Site::factory()->create(['type' => 'house', 'is_active' => true]);

    return Client::factory()->create(array_merge(['site_id' => $site->id, 'status' => 'active'], $attributes));
}

function medicationOrderEndingOn(?string $endDate, array $attributes = []): ClientMedication
{
    return ClientMedication::factory()->create(array_merge([
        'client_id' => nzCalendarClient()->id,
        'name' => 'Risperidone',
        'controlled_drug' => false,
        'high_risk' => false,
        'active' => true,
        'state' => 'active',
        'approval_status' => 'verified',
        'is_prn' => false,
        'dose_times' => ['09:00'],
        'frequency' => '09:00',
        'start_date' => '2026-05-01',
        'end_date' => $endDate,
    ], $attributes));
}

/** @return array{0: string, 1: string}|null the severity and message of the client's alert of this type */
function medicationDashboardAlertFor(Client $client, string $type): ?array
{
    app(MedicationAlertService::class)->generateClientAlerts($client);

    $alert = MedicationDashboardAlert::query()
        ->where('client_id', $client->id)
        ->where('alert_type', $type)
        ->first();

    return $alert ? [$alert->severity, $alert->message] : null;
}

function expiringSoonAlertMessage(ClientMedication $order): ?string
{
    app(MedicationAlertService::class)->generateClientAlerts($order->client);

    return MedicationDashboardAlert::query()
        ->where('client_medication_id', $order->id)
        ->where('alert_type', 'expiring_soon')
        ->value('message');
}

it('tells staff how many days are left on an order, counted on the New Zealand calendar', function (string $nzNow, string $endDate, string $message) {
    freezeNzClock($nzNow);

    expect(expiringSoonAlertMessage(medicationOrderEndingOn($endDate)))->toBe($message);
})->with('new zealand clock on 8 june')->with([
    'three days' => ['2026-06-11', 'Risperidone: Expires in 3 days (11/06/2026)'],
    'one day' => ['2026-06-09', 'Risperidone: Expires in 1 day (09/06/2026)'],
]);

it('says an order expires today on its last day', function () {
    freezeNzClock('2026-06-08 01:00:00');
    $order = medicationOrderEndingOn('2026-06-08');

    expect($order->daysUntilEnd())->toBe(0)
        ->and($order->isExpiringSoon())->toBeTrue()
        ->and(expiringSoonAlertMessage($order))->toBe('Risperidone: Expires today (08/06/2026)');
});

it('gives the expiring-medications widget days remaining on the New Zealand calendar', function (string $nzNow) {
    freezeNzClock($nzNow);
    $order = medicationOrderEndingOn('2026-06-11');

    $widgets = app(MedicationAlertService::class)->getGlobalDashboardWidgets(siteIds: [$order->client->site_id]);
    $item = collect($widgets['expiring_medications']['items'])->firstWhere('id', $order->id);

    expect($item)->not->toBeNull()
        ->and($item['expiry_date'])->toBe('2026-06-11')
        ->and($item['days_remaining'])->toBe(3);
})->with('new zealand clock on 8 june');

it('lists orders ending within 14 days on the New Zealand calendar in the widget', function (string $nzNow) {
    freezeNzClock($nzNow);
    $lastListed = medicationOrderEndingOn('2026-06-22');
    $notListed = medicationOrderEndingOn('2026-06-23');

    $widgets = app(MedicationAlertService::class)->getGlobalDashboardWidgets(
        siteIds: [$lastListed->client->site_id, $notListed->client->site_id],
    );
    $items = collect($widgets['expiring_medications']['items']);

    expect($items->pluck('id')->all())->toBe([$lastListed->id])
        ->and($items->first()['days_remaining'])->toBe(14);
})->with('new zealand clock on 8 june');

it('reports days remaining in the administration safety check on the New Zealand calendar', function (string $nzNow) {
    freezeNzClock($nzNow);
    $order = medicationOrderEndingOn('2026-06-11');

    $result = app(MedicationSafetyService::class)->performSafetyCheck($order->client, $order);
    $expiring = collect($result['warnings'])->firstWhere('type', 'expiring_soon');

    expect($expiring)->not->toBeNull()
        ->and($expiring['details'])->toBe(['expiry_date' => '2026-06-11', 'days_remaining' => 3]);
})->with('new zealand clock on 8 june');

it('keeps the expiring-soon window and the day count on the same calendar', function (string $nzNow) {
    freezeNzClock($nzNow);
    $endingIn = fn (int $days) => (new ClientMedication)->forceFill([
        'end_date' => now(config('app.worker_timezone'))->addDays($days)->toDateString(),
    ]);

    expect($endingIn(1)->daysUntilEnd())->toBe(1)
        ->and($endingIn(1)->isExpiringSoon())->toBeTrue()
        ->and($endingIn(7)->daysUntilEnd())->toBe(7)
        ->and($endingIn(7)->isExpiringSoon())->toBeTrue()
        ->and($endingIn(8)->daysUntilEnd())->toBe(8)
        ->and($endingIn(8)->isExpiringSoon())->toBeFalse()
        ->and((new ClientMedication)->daysUntilEnd())->toBeNull();
})->with('new zealand clock on 8 june');

it('dates INR tests on the New Zealand calendar', function (string $nzNow, string $nextTest, ?array $alert) {
    freezeNzClock($nzNow);
    $order = medicationOrderEndingOn(null, ['name' => 'Warfarin 1mg']);
    ClientInrRecord::query()->create([
        'client_id' => $order->client_id,
        'inr_value' => 2.5,
        'target_range_low' => 2.0,
        'target_range_high' => 3.0,
        'tested_on' => '2026-05-25',
        'next_test_date' => $nextTest,
        'recorded_by' => User::factory()->create()->id,
    ]);

    expect(medicationDashboardAlertFor($order->client, 'inr_due'))->toBe($alert);
})->with('new zealand clock on 8 june')->with([
    'due yesterday' => ['2026-06-07', ['critical', 'Warfarin 1mg: INR overdue since 07/06/2026.']],
    'due in three days' => ['2026-06-11', ['warning', 'Warfarin 1mg: INR due by 11/06/2026.']],
    'due in four days' => ['2026-06-12', null],
]);

it('dates medication chart reviews on the New Zealand calendar', function (string $nzNow, string $reviewDate, ?array $alert) {
    freezeNzClock($nzNow);
    $client = nzCalendarClient(['next_chart_review_date' => $reviewDate]);

    expect(medicationDashboardAlertFor($client, 'chart_review_due'))->toBe($alert);
})->with('new zealand clock on 8 june')->with([
    'due yesterday' => ['2026-06-07', ['critical', 'Medication chart review overdue since 07/06/2026.']],
    'due in seven days' => ['2026-06-15', ['warning', 'Medication chart review due by 15/06/2026.']],
    'due in eight days' => ['2026-06-16', null],
]);

it('dates medication reviews on the New Zealand calendar', function (string $nzNow, string $scheduledDate, ?array $alert) {
    freezeNzClock($nzNow);
    $client = nzCalendarClient();
    MedicationReview::query()->create([
        'client_id' => $client->id,
        'review_type' => 'Routine 6-monthly',
        'status' => 'scheduled',
        'scheduled_date' => $scheduledDate,
    ]);

    expect(medicationDashboardAlertFor($client, 'medication_review_due'))->toBe($alert);
})->with('new zealand clock on 8 june')->with([
    'due yesterday' => ['2026-06-07', ['critical', 'Medication review overdue since 07/06/2026.']],
    'due in seven days' => ['2026-06-15', ['warning', 'Medication review due by 15/06/2026.']],
    'due in eight days' => ['2026-06-16', null],
]);
