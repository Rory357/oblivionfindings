<?php

use App\Models\Client;
use App\Models\ClientMedication;
use App\Models\MedicationDashboardAlert;
use App\Models\Site;
use App\Services\MedicationAlertService;
use Illuminate\Support\Carbon;

/*
 * Carbon 3's diffInDays() is signed and fractional, so measuring from the end
 * date back to now showed "Expires in -2.9166666666667 days". Days remaining
 * are whole calendar days from today until the order's end date.
 */

beforeEach(function () {
    // 2 pm NZ on 8 June (02:00 UTC), so the NZ and UTC dates agree.
    Carbon::setTestNow(Carbon::parse('2026-06-08 14:00:00', 'Pacific/Auckland')->utc());
});

afterEach(function () {
    Carbon::setTestNow();
});

function medicationOrderEndingOn(Site $site, string $endDate): ClientMedication
{
    $client = Client::factory()->create(['site_id' => $site->id, 'status' => 'active']);

    return ClientMedication::factory()->create([
        'client_id' => $client->id,
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
    ]);
}

it('tells staff how many whole days are left before an order expires', function (string $endDate, string $message) {
    $site = Site::factory()->create(['type' => 'house', 'is_active' => true]);
    $order = medicationOrderEndingOn($site, $endDate);

    app(MedicationAlertService::class)->generateClientAlerts($order->client);

    $alert = MedicationDashboardAlert::query()
        ->where('client_medication_id', $order->id)
        ->where('alert_type', 'expiring_soon')
        ->firstOrFail();

    expect($alert->message)->toBe($message);
})->with([
    'three days' => ['2026-06-11', 'Risperidone: Expires in 3 days (11/06/2026)'],
    'one day' => ['2026-06-09', 'Risperidone: Expires in 1 day (09/06/2026)'],
]);

it('gives the expiring-medications widget whole days remaining', function () {
    $site = Site::factory()->create(['type' => 'house', 'is_active' => true]);
    $order = medicationOrderEndingOn($site, '2026-06-11');

    $widgets = app(MedicationAlertService::class)->getGlobalDashboardWidgets(siteIds: [$site->id]);
    $item = collect($widgets['expiring_medications']['items'])->firstWhere('id', $order->id);

    expect($item)->not->toBeNull()
        ->and($item['expiry_date'])->toBe('2026-06-11')
        ->and($item['days_remaining'])->toBe(3);
});
