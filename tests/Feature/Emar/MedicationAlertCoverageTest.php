<?php

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Models\MedicationAlert;
use App\Models\MedicationAlertEvent;
use App\Models\Permission;
use App\Models\Site;
use App\Models\User;
use App\Services\Medication\Alerts\MedicationAlertCatalogue;
use App\Services\Medication\Alerts\MedicationAlertRecipients;
use App\Services\Medication\Alerts\MedicationAlerts;
use App\Services\Medication\Alerts\MedicationAlertSubject;
use Database\Seeders\RbacSeeder;
use Database\Seeders\SystemCatalogSeeder;
use Database\Seeders\SystemClientsSeeder;
use Database\Seeders\SystemMedicationsSeeder;
use Database\Seeders\SystemUsersSeeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;

/*
 * P11 B2 Q1: on the seeded demo data, every alert type that something raises
 * reaches at least one person at each house with active orders — through its
 * groups (the demo house leads and clinical lead, B2 (b)), or else through
 * the safety net (medication settings managers, B2 (a)). No type silently
 * reaches nobody: when even the safety net is empty, the alert log says
 * "Nobody could be told" and Settings counts it.
 */

beforeEach(function () {
    Carbon::setTestNow(Carbon::parse('2026-10-02 11:15:00', 'Pacific/Auckland')->utc());
    Notification::fake();
    $this->seed([
        RbacSeeder::class,
        SystemCatalogSeeder::class,
        SystemUsersSeeder::class,
        SystemClientsSeeder::class,
        SystemMedicationsSeeder::class,
    ]);
});

afterEach(function () {
    Carbon::setTestNow();
});

/** @return list<Site> */
function coverageHouses(): array
{
    return Site::query()
        ->whereHas('clients.medications', fn ($orders) => $orders->active())
        ->orderBy('id')
        ->get()
        ->all();
}

/** @return array{told: list<array{user: User, reason: string}>, fallback: bool} */
function coverageResolve(string $alert, Site $house): array
{
    $staffMember = HrEmployeeProfile::query()->where('primary_site_id', $house->id)->value('user_id');

    return app(MedicationAlertRecipients::class)->resolve($alert, new MedicationAlertSubject(
        key: 'coverage',
        siteId: (int) $house->id,
        title: 'Coverage',
        message: 'Coverage',
        shortMessage: 'Coverage',
        controlled: MedicationAlertCatalogue::ALERTS[$alert]['controlled'],
        staffUserId: $staffMember !== null ? (int) $staffMember : null,
    ), now());
}

it('reaches someone in each alert’s own groups at every house with active orders', function () {
    $houses = coverageHouses();
    expect($houses)->not->toBeEmpty();

    foreach ($houses as $house) {
        foreach (MedicationAlertCatalogue::built() as $alert) {
            $resolved = coverageResolve($alert, $house);
            expect($resolved['told'])->not->toBeEmpty("{$alert} reached nobody at {$house->name}")
                ->and($resolved['fallback'])->toBeFalse("{$alert} needed the safety net at {$house->name}");
        }
    }
});

it('falls through to medication settings managers when a house has no lead', function () {
    User::query()->whereIn('role', ['team_lead', 'clinical_lead'])->get()->each(fn (User $u) => $u->roles()->detach());

    foreach (coverageHouses() as $house) {
        foreach (MedicationAlertCatalogue::built() as $alert) {
            $resolved = coverageResolve($alert, $house);
            expect($resolved['told'])->not->toBeEmpty("{$alert} reached nobody at {$house->name}");
            if ($resolved['fallback']) {
                expect(collect($resolved['told'])->every(fn (array $t) => $t['user']->canDo('medications.settings.manage')))->toBeTrue();
            }
        }
        // "Medication review due" goes to clinical leads only: now the safety net.
        expect(coverageResolve('reviewDue', $house)['fallback'])->toBeTrue();
    }
});

it('says “Nobody could be told” and Settings counts it when even the safety net is empty', function () {
    $house = coverageHouses()[0];
    User::query()->where('role', 'team_lead')->get()->each(fn (User $u) => $u->roles()->detach());
    // No medication settings manager can see controlled medicines.
    $deny = Permission::query()->where('key', 'medications.controlled.view')->value('id');
    User::query()->get()
        ->filter(fn (User $u) => $u->canDo('medications.settings.manage'))
        ->each(fn (User $u) => $u->permissionOverrides()->syncWithoutDetaching([$deny => ['allowed' => false]]));

    $alert = app(MedicationAlerts::class)->raise('cdCheck', new MedicationAlertSubject(
        key: 'cd-check:'.$house->id,
        siteId: (int) $house->id,
        title: 'Controlled-drug balance check overdue',
        message: 'No controlled-drug balance check at '.$house->name.' for 7 days (1 medicine).',
        shortMessage: 'A controlled-drug check at '.$house->name.' is overdue.',
        controlled: true,
    ));

    expect($alert->reached_nobody)->toBeTrue()
        ->and($alert->events()->where('event', MedicationAlertEvent::NOBODY_TOLD)->value('detail'))
        ->toMatchArray(['reason' => 'Nobody in its groups, and no medication settings manager with access to this house and controlled-medicine access.']);

    $manager = User::query()->where('email', 'manager@demo.test')->firstOrFail();
    $this->actingAs($manager)->get(route('emar.settings'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('alertNobodyOpen', 1));
    expect(MedicationAlert::query()->whereNotNull('open_key')->where('reached_nobody', true)->count())->toBe(1);
});
