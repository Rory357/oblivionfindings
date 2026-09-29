<?php

use App\Models\Client;
use App\Models\ClientMedication;
use App\Models\MedicationDashboardAlert;
use App\Models\Site;
use App\Services\MedicationAlertService;
use App\Services\MedicationSafetyService;
use Illuminate\Support\Carbon;

/*
 * A medication order runs to the end of its end date: the MAR and guided
 * rounds still schedule that day's doses. It expires once the New Zealand
 * date is after the end date, not at UTC midnight (noon in New Zealand).
 */

afterEach(function () {
    Carbon::setTestNow();
});

/** An order whose last day is 8 June, checked at a New Zealand wall-clock time. */
function lastDayMedicationOrderAt(string $nzDateTime): ClientMedication
{
    Carbon::setTestNow(Carbon::parse($nzDateTime, 'Pacific/Auckland')->utc());

    $site = Site::factory()->create(['type' => 'house', 'is_active' => true]);
    $client = Client::factory()->create(['site_id' => $site->id, 'status' => 'active']);

    return ClientMedication::factory()->create([
        'client_id' => $client->id,
        'name' => 'Amoxicillin 500mg',
        'controlled_drug' => false,
        'high_risk' => false,
        'active' => true,
        'state' => 'active',
        'approval_status' => 'verified',
        'is_prn' => false,
        'dose_times' => ['09:00'],
        'frequency' => '09:00',
        'start_date' => '2026-06-01',
        'end_date' => '2026-06-08',
    ]);
}

/** @return array{safety: array, alerts: Illuminate\Support\Collection, widget: ?array} */
function lastDayMedicationChecks(ClientMedication $order): array
{
    $safety = app(MedicationSafetyService::class)->performSafetyCheck($order->client, $order);

    $alertService = app(MedicationAlertService::class);
    $alertService->generateClientAlerts($order->client);
    $widgets = $alertService->getGlobalDashboardWidgets(siteIds: [$order->client->site_id]);

    return [
        'safety' => $safety,
        'alerts' => MedicationDashboardAlert::query()
            ->where('client_medication_id', $order->id)
            ->pluck('message', 'alert_type'),
        'widget' => collect($widgets['expiring_medications']['items'])->firstWhere('id', $order->id),
    ];
}

it('lets staff give an order all through its last day', function (string $nzNow) {
    $order = lastDayMedicationOrderAt($nzNow);
    $checks = lastDayMedicationChecks($order);
    $expiring = collect($checks['safety']['warnings'])->firstWhere('type', 'expiring_soon');

    expect($order->isExpired())->toBeFalse()
        ->and($checks['safety']['blocked'])->toBeFalse()
        ->and($expiring['details']['days_remaining'] ?? null)->toBe(0)
        ->and($checks['alerts']->get('expired'))->toBeNull()
        ->and($checks['alerts']->get('expiring_soon'))->toBe('Amoxicillin 500mg: Expires today (08/06/2026)')
        ->and($checks['widget']['days_remaining'] ?? null)->toBe(0);
})->with([
    'morning' => '2026-06-08 08:00:00',
    'afternoon' => '2026-06-08 14:00:00',
    'evening' => '2026-06-08 20:00:00',
    'just before midnight' => '2026-06-08 23:55:00',
]);

it('blocks an order once its last day has passed', function (string $nzNow) {
    $order = lastDayMedicationOrderAt($nzNow);
    $checks = lastDayMedicationChecks($order);

    expect($order->isExpired())->toBeTrue()
        ->and($checks['safety']['blocked'])->toBeTrue()
        ->and($checks['safety']['block_reason'])->toBe('Medication has expired')
        ->and($checks['alerts']->get('expiring_soon'))->toBeNull()
        ->and($checks['alerts']->get('expired'))->toBe('Amoxicillin 500mg: Medication expired on 08/06/2026')
        ->and($checks['widget'])->toBeNull();
})->with([
    'just after midnight' => '2026-06-09 00:05:00',
    'the next afternoon' => '2026-06-09 14:00:00',
]);
