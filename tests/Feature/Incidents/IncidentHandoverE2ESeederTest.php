<?php

use App\Models\Client;
use App\Models\ControlRoom\Shift;
use App\Models\ControlRoomAlert;
use App\Models\Site;
use App\Models\User;
use Database\Seeders\IncidentHandoverE2ESeeder;
use Database\Seeders\RbacSeeder;

it('seeds one bounded seven-persona incident handover fixture without active duplicates', function () {
    $this->seed(RbacSeeder::class);
    $this->seed(IncidentHandoverE2ESeeder::class);

    $emails = [
        'incident-e2e-operator@demo.test',
        'incident-e2e-reviewer@demo.test',
        'incident-e2e-owner@demo.test',
        'incident-e2e-action-owner@demo.test',
        'incident-e2e-verifier@demo.test',
        'incident-e2e-incoming@demo.test',
        'incident-e2e-worker@demo.test',
    ];
    $firstUserIds = User::query()
        ->whereIn('email', $emails)
        ->pluck('id', 'email');

    expect($firstUserIds)->toHaveCount(7)
        ->and($firstUserIds->unique())->toHaveCount(7);
    $worker = User::query()->findOrFail(
        $firstUserIds['incident-e2e-worker@demo.test'],
    );
    expect($worker->canDo('hazards.view'))->toBeTrue()
        ->and($worker->canDo('hazards.manage'))->toBeFalse();

    $site = Site::query()->findOrFail(IncidentHandoverE2ESeeder::SITE_ID);
    $client = Client::query()->findOrFail(IncidentHandoverE2ESeeder::CLIENT_ID);
    expect($site->name)->toBe('Playwright Incident Handover House')
        ->and($client->site_id)->toBe($site->id);

    $activeShift = Shift::query()
        ->where('name', IncidentHandoverE2ESeeder::SHIFT_NAME)
        ->active()
        ->sole();
    expect($activeShift->shift_lead_user_id)
        ->toBe($firstUserIds['incident-e2e-operator@demo.test'])
        ->and($activeShift->memberUserIds())
        ->toContain(
            $firstUserIds['incident-e2e-operator@demo.test'],
            $firstUserIds['incident-e2e-incoming@demo.test'],
        );

    $assertBoundedAlerts = function () use ($site, $client): void {
        $alerts = ControlRoomAlert::query()
            ->where('context->fixture_marker', IncidentHandoverE2ESeeder::MARKER)
            ->orderBy('reference_number')
            ->get();

        expect($alerts)->toHaveCount(2)
            ->and($alerts->pluck('reference_number')->all())
            ->toBe(IncidentHandoverE2ESeeder::REQUIRED_ALERT_REFERENCES)
            ->and($alerts->pluck('site_id')->unique()->all())
            ->toBe([$site->id])
            ->and($alerts->pluck('client_id')->unique()->all())
            ->toBe([$client->id]);
    };
    $assertBoundedAlerts();

    $this->seed(IncidentHandoverE2ESeeder::class);

    expect(User::query()->whereIn('email', $emails)->pluck('id', 'email')->all())
        ->toBe($firstUserIds->all())
        ->and(Shift::query()
            ->where('name', IncidentHandoverE2ESeeder::SHIFT_NAME)
            ->active()
            ->count())
        ->toBe(1)
        ->and(Shift::query()
            ->where('name', IncidentHandoverE2ESeeder::SHIFT_NAME)
            ->where('status', '!=', 'active')
            ->count())
        ->toBe(1);
    $assertBoundedAlerts();
});
