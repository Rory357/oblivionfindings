<?php

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Models\Client;
use App\Models\ClientAppointment;
use App\Models\ClientMedication;
use App\Models\ClientMedicationAdministration;
use App\Models\FamilyNote;
use App\Models\FamilyVisitRequest;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Shift;
use App\Models\Site;
use App\Models\User;
use Illuminate\Testing\TestResponse;

function grantClientCalendarDisclosurePermissions(
    User $user,
    array $permissionKeys,
    string $roleName = 'calendar_disclosure_staff',
): void {
    $role = Role::query()->create([
        'name' => $roleName.'_'.$user->id,
        'label' => 'Calendar disclosure test',
        'level' => 50,
        'type' => 'custom',
    ]);

    foreach ($permissionKeys as $key) {
        Permission::query()->firstOrCreate(
            ['key' => $key],
            ['description' => $key, 'group' => 'test', 'module' => 'Test'],
        );
    }

    $role->permissions()->sync(
        Permission::query()->whereIn('key', $permissionKeys)->pluck('id')->all(),
    );
    $user->roles()->attach($role->id);
}

function scopeClientCalendarDisclosureStaffToSite(User $user, Site $site): void
{
    HrEmployeeProfile::factory()->create([
        'user_id' => $user->id,
        'position_role' => 'support_worker',
        'primary_site_id' => $site->id,
        'secondary_site_ids' => [],
        'start_date' => now()->subMonth()->toDateString(),
        'end_date' => null,
        'is_active' => true,
    ]);
}

/** @return array{client: Client, site: Site, viewer: User} */
function clientCalendarDisclosureFixture(array $permissionKeys): array
{
    $site = Site::factory()->create();
    $client = Client::factory()->create(['site_id' => $site->id]);
    $viewer = User::factory()->create([
        'role' => 'support_worker',
        'approved_at' => now(),
    ]);
    grantClientCalendarDisclosurePermissions($viewer, $permissionKeys);
    scopeClientCalendarDisclosureStaffToSite($viewer, $site);
    $client->supportWorkers()->attach($viewer->id);

    return compact('client', 'site', 'viewer');
}

/** @return array<string, int> */
function seedClientCalendarDisclosureEvents(Client $client, User $actor): array
{
    $appointment = ClientAppointment::query()->create([
        'client_id' => $client->id,
        'title' => 'Visible appointment',
        'appointment_type' => 'gp_visit',
        'starts_at' => now()->addDay(),
        'status' => 'scheduled',
        'share_with_family' => true,
        'created_by' => $actor->id,
    ]);

    $shift = Shift::factory()->create([
        'client_id' => $client->id,
        'user_id' => $actor->id,
        'starts_at' => now()->addDay()->startOfHour(),
        'ends_at' => now()->addDay()->startOfHour()->addHours(4),
        'status' => 'scheduled',
    ]);

    $visit = FamilyVisitRequest::query()->create([
        'user_id' => $actor->id,
        'client_id' => $client->id,
        'requested_date' => now()->addDay()->toDateString(),
        'preferred_time_start' => '10:00',
        'preferred_time_end' => '11:00',
        'visit_type' => 'in_person',
        'status' => 'approved',
    ]);

    $familyNote = FamilyNote::query()->create([
        'client_id' => $client->id,
        'created_by' => $actor->id,
        'title' => 'Restricted family note',
        'status' => 'open',
        'due_date' => now()->addDay()->toDateString(),
    ]);

    $medication = ClientMedication::factory()->create([
        'client_id' => $client->id,
        'name' => 'Restricted medication',
        'frequency' => 'As required',
        'active' => true,
        'is_prn' => true,
        'state' => 'active',
    ]);
    $administration = ClientMedicationAdministration::query()->create([
        'client_id' => $client->id,
        'client_medication_id' => $medication->id,
        'administered_by' => $actor->id,
        'scheduled_for' => now()->addDay()->startOfHour(),
        'administered_at' => now()->addDay()->startOfHour(),
        'status' => 'given',
    ]);

    return [
        'appointment' => $appointment->id,
        'shift' => $shift->id,
        'visit' => $visit->id,
        'family_note' => $familyNote->id,
        'medication_administration' => $administration->id,
    ];
}

/** @return array<int, string> */
function clientCalendarDisclosureTypes(TestResponse $response): array
{
    return collect($response->json())
        ->pluck('extendedProps.type')
        ->filter()
        ->unique()
        ->sort()
        ->values()
        ->all();
}

it('requires the staff calendar section on the direct client calendar JSON route', function () {
    ['client' => $client, 'viewer' => $viewer] = clientCalendarDisclosureFixture([
        'clients.viewAssigned',
    ]);

    $this->actingAs($viewer)
        ->getJson(route('client.calendar.events', $client, false))
        ->assertForbidden();
});

it('returns appointments but omits shift family and medication contributors without their section access', function () {
    ['client' => $client, 'viewer' => $viewer] = clientCalendarDisclosureFixture([
        'clients.viewAssigned',
        'calendar.view',
    ]);
    seedClientCalendarDisclosureEvents($client, $viewer);

    $response = $this->actingAs($viewer)
        ->getJson(route('client.calendar.events', [
            'client' => $client,
            'start' => now()->startOfMonth()->toIso8601String(),
            'end' => now()->endOfMonth()->toIso8601String(),
        ], false))
        ->assertOk();

    expect(clientCalendarDisclosureTypes($response))->toBe(['appointment']);
});

it('preserves every calendar contributor for a staff user with each canonical section capability', function () {
    ['client' => $client, 'viewer' => $viewer] = clientCalendarDisclosureFixture([
        'clients.viewAssigned',
        'calendar.view',
        'shifts.viewAny',
        'family_portal.viewAny',
        'medications.view',
        'medications.audit.view',
    ]);
    seedClientCalendarDisclosureEvents($client, $viewer);

    $response = $this->actingAs($viewer)
        ->getJson(route('client.calendar.events', [
            'client' => $client,
            'start' => now()->startOfMonth()->toIso8601String(),
            'end' => now()->endOfMonth()->toIso8601String(),
        ], false))
        ->assertOk();

    expect(clientCalendarDisclosureTypes($response))->toBe([
        'appointment',
        'family_note',
        'family_visit',
        'medication',
        'shift',
    ]);
});

it('keeps non-medication calendar entries while applying exact controlled and canonical medication scope', function () {
    ['client' => $client, 'site' => $site, 'viewer' => $ordinaryViewer] = clientCalendarDisclosureFixture([
        'clients.viewAssigned',
        'calendar.view',
        'shifts.viewAny',
        'medications.view',
    ]);
    $baseEvents = seedClientCalendarDisclosureEvents($client, $ordinaryViewer);

    $noMedicationViewer = User::factory()->create([
        'role' => 'support_worker',
        'approved_at' => now(),
    ]);
    grantClientCalendarDisclosurePermissions($noMedicationViewer, [
        'clients.viewAssigned',
        'calendar.view',
        'shifts.viewAny',
    ]);
    scopeClientCalendarDisclosureStaffToSite($noMedicationViewer, $site);
    $client->supportWorkers()->attach($noMedicationViewer->id);

    $controlledViewer = User::factory()->create([
        'role' => 'support_worker',
        'approved_at' => now(),
    ]);
    grantClientCalendarDisclosurePermissions($controlledViewer, [
        'clients.viewAssigned',
        'calendar.view',
        'shifts.viewAny',
        'medications.view',
        'medications.controlled.view',
    ]);
    scopeClientCalendarDisclosureStaffToSite($controlledViewer, $site);
    $client->supportWorkers()->attach($controlledViewer->id);

    $ordinaryMedication = ClientMedication::factory()->create([
        'client_id' => $client->id,
        'name' => 'Ordinary scheduled medication',
        'frequency' => '09:00',
        'active' => true,
        'is_prn' => false,
        'state' => 'active',
        'controlled_drug' => false,
    ]);
    $controlledMedication = ClientMedication::factory()->create([
        'client_id' => $client->id,
        'name' => 'Controlled scheduled medication',
        'frequency' => '09:00',
        'active' => true,
        'is_prn' => false,
        'state' => 'active',
        'controlled_drug' => true,
    ]);
    $unverifiedMedication = ClientMedication::factory()->create([
        'client_id' => $client->id,
        'name' => 'Unverified scheduled medication',
        'frequency' => '09:00',
        'active' => true,
        'is_prn' => false,
        'state' => 'active',
        'approval_status' => 'pending_verification',
        'controlled_drug' => false,
    ]);
    $supersededMedication = ClientMedication::factory()->create([
        'client_id' => $client->id,
        'name' => 'Superseded scheduled medication',
        'frequency' => '09:00',
        'active' => true,
        'is_prn' => false,
        'state' => 'active',
        'approval_status' => 'verified',
        'controlled_drug' => false,
    ]);
    $supersededMedication->forceFill(['superseded_by' => $ordinaryMedication->id])->saveQuietly();
    $foreignClient = Client::factory()->create(['site_id' => Site::factory()->create()->id]);
    $foreignMedication = ClientMedication::factory()->create([
        'client_id' => $foreignClient->id,
        'name' => 'Forged foreign medication',
        'frequency' => '09:00',
        'active' => true,
        'is_prn' => false,
        'state' => 'active',
        'controlled_drug' => true,
    ]);

    $ordinaryAdministration = ClientMedicationAdministration::query()->create([
        'client_id' => $client->id,
        'client_medication_id' => $ordinaryMedication->id,
        'administered_by' => $ordinaryViewer->id,
        'scheduled_for' => now()->startOfHour(),
        'administered_at' => now()->startOfHour(),
        'status' => 'given',
    ]);
    $controlledAdministration = ClientMedicationAdministration::query()->create([
        'client_id' => $client->id,
        'client_medication_id' => $controlledMedication->id,
        'administered_by' => $ordinaryViewer->id,
        'scheduled_for' => now()->startOfHour(),
        'administered_at' => now()->startOfHour(),
        'status' => 'given',
    ]);
    $forgedAdministration = ClientMedicationAdministration::query()->create([
        'client_id' => $client->id,
        'client_medication_id' => $foreignMedication->id,
        'administered_by' => $ordinaryViewer->id,
        'scheduled_for' => now()->startOfHour(),
        'administered_at' => now()->startOfHour(),
        'status' => 'given',
    ]);

    $calendarUri = route('client.calendar.events', [
        'client' => $client,
        'start' => now()->startOfMonth()->toIso8601String(),
        'end' => now()->endOfMonth()->toIso8601String(),
    ], false);

    $noMedicationIds = collect($this->actingAs($noMedicationViewer)
        ->getJson($calendarUri)
        ->assertOk()
        ->json())->pluck('id');
    expect($noMedicationIds)
        ->toContain('appt-'.$baseEvents['appointment'], 'shift-'.$baseEvents['shift'])
        ->not->toContain('med-'.$ordinaryAdministration->id, 'med-'.$controlledAdministration->id);
    expect($noMedicationIds->contains(fn (string $id): bool => str_starts_with($id, 'medsched-')))
        ->toBeFalse();

    $ordinaryIds = collect($this->actingAs($ordinaryViewer)
        ->getJson($calendarUri)
        ->assertOk()
        ->json())->pluck('id');
    expect($ordinaryIds)
        ->toContain('med-'.$ordinaryAdministration->id)
        ->not->toContain(
            'med-'.$controlledAdministration->id,
            'med-'.$forgedAdministration->id,
        );
    expect($ordinaryIds->contains(fn (string $id): bool => str_starts_with(
        $id,
        'medsched-'.$ordinaryMedication->id.'-',
    )))->toBeTrue();
    expect($ordinaryIds->contains(fn (string $id): bool => str_starts_with(
        $id,
        'medsched-'.$controlledMedication->id.'-',
    )))->toBeFalse();
    expect($ordinaryIds->contains(fn (string $id): bool => str_starts_with(
        $id,
        'medsched-'.$unverifiedMedication->id.'-',
    )))->toBeFalse();
    expect($ordinaryIds->contains(fn (string $id): bool => str_starts_with(
        $id,
        'medsched-'.$supersededMedication->id.'-',
    )))->toBeFalse();

    $controlledIds = collect($this->actingAs($controlledViewer)
        ->getJson($calendarUri)
        ->assertOk()
        ->json())->pluck('id');
    expect($controlledIds)
        ->toContain('med-'.$ordinaryAdministration->id, 'med-'.$controlledAdministration->id)
        ->not->toContain('med-'.$forgedAdministration->id);
    expect($controlledIds->contains(fn (string $id): bool => str_starts_with(
        $id,
        'medsched-'.$ordinaryMedication->id.'-',
    )))->toBeTrue();
    expect($controlledIds->contains(fn (string $id): bool => str_starts_with(
        $id,
        'medsched-'.$controlledMedication->id.'-',
    )))->toBeTrue();
    expect($controlledIds->contains(fn (string $id): bool => str_starts_with(
        $id,
        'medsched-'.$unverifiedMedication->id.'-',
    )))->toBeFalse();
    expect($controlledIds->contains(fn (string $id): bool => str_starts_with(
        $id,
        'medsched-'.$supersededMedication->id.'-',
    )))->toBeFalse();
});

it('validates orders and bounds the direct calendar event range to 93 days', function () {
    ['client' => $client, 'viewer' => $viewer] = clientCalendarDisclosureFixture([
        'clients.viewAssigned',
        'calendar.view',
    ]);

    $this->actingAs($viewer)
        ->getJson(route('client.calendar.events', [
            'client' => $client,
            'start' => 'not-a-date',
            'end' => '2026-01-31',
        ], false))
        ->assertUnprocessable()
        ->assertJsonValidationErrors('start');

    $this->getJson(route('client.calendar.events', [
        'client' => $client,
        'start' => '2026-02-01',
        'end' => '2026-01-31',
    ], false))
        ->assertUnprocessable()
        ->assertJsonValidationErrors('end');

    $this->getJson(route('client.calendar.events', [
        'client' => $client,
        'start' => '2026-01-01',
        'end' => '2026-04-05',
    ], false))
        ->assertUnprocessable()
        ->assertJsonValidationErrors('end');

    $this->getJson(route('client.calendar.events', [
        'client' => $client,
        'start' => '2026-01-01',
        'end' => '2026-04-04',
    ], false))
        ->assertOk();
});

it('forbids cross-Site staff and linked portal identities on the staff calendar JSON route', function () {
    $clientSite = Site::factory()->create();
    $staffSite = Site::factory()->create();
    $client = Client::factory()->create(['site_id' => $clientSite->id]);
    $staff = User::factory()->create([
        'role' => 'support_worker',
        'approved_at' => now(),
    ]);
    grantClientCalendarDisclosurePermissions($staff, [
        'clients.viewAssigned',
        'calendar.view',
        'shifts.viewAny',
        'family_portal.viewAny',
        'medications.view',
        'medications.audit.view',
    ]);
    scopeClientCalendarDisclosureStaffToSite($staff, $staffSite);
    $client->supportWorkers()->attach($staff->id);

    $this->actingAs($staff)
        ->getJson(route('client.calendar.events', $client, false))
        ->assertForbidden();

    $portalClient = User::factory()->create([
        'role' => 'client',
        'approved_at' => now(),
    ]);
    $portalRole = Role::query()->firstOrCreate(
        ['name' => 'client'],
        ['label' => 'Client', 'level' => 1, 'type' => 'system'],
    );
    $portalClient->roles()->attach($portalRole->id);
    foreach (['clients.viewPortal', 'calendar.view'] as $permissionKey) {
        $permission = Permission::query()->firstOrCreate(
            ['key' => $permissionKey],
            ['description' => $permissionKey, 'group' => 'test', 'module' => 'Test'],
        );
        $portalClient->permissionOverrides()->attach($permission->id, ['allowed' => true]);
    }
    $client->portalUsers()->attach($portalClient->id, ['relation' => 'self']);

    $this->actingAs($portalClient)
        ->getJson(route('client.calendar.events', $client, false))
        ->assertForbidden();
});
