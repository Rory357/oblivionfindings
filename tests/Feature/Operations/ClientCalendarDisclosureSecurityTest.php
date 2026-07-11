<?php

use App\Models\Client;
use App\Models\ClientAppointment;
use App\Models\ClientMedication;
use App\Models\ClientMedicationAdministration;
use App\Models\FamilyNote;
use App\Models\FamilyVisitRequest;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Shift;
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
    $client = Client::factory()->create(['organization_id' => 1]);
    $viewer = User::factory()->create(['organization_id' => 1]);
    grantClientCalendarDisclosurePermissions($viewer, ['clients.viewAny']);

    $this->actingAs($viewer)
        ->getJson(route('client.calendar.events', $client, false))
        ->assertForbidden();
});

it('returns appointments but omits shift family and medication contributors without their section access', function () {
    $client = Client::factory()->create(['organization_id' => 1]);
    $viewer = User::factory()->create(['organization_id' => 1]);
    grantClientCalendarDisclosurePermissions($viewer, [
        'clients.viewAny',
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
    $client = Client::factory()->create(['organization_id' => 1]);
    $viewer = User::factory()->create(['organization_id' => 1]);
    grantClientCalendarDisclosurePermissions($viewer, [
        'clients.viewAny',
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

it('validates orders and bounds the direct calendar event range to 93 days', function () {
    $client = Client::factory()->create(['organization_id' => 1]);
    $viewer = User::factory()->create(['organization_id' => 1]);
    grantClientCalendarDisclosurePermissions($viewer, [
        'clients.viewAny',
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

it('forbids cross-organization staff and linked portal identities on the staff calendar JSON route', function () {
    $client = Client::factory()->create(['organization_id' => 2]);
    $staff = User::factory()->create(['organization_id' => 1]);
    grantClientCalendarDisclosurePermissions($staff, [
        'clients.viewAny',
        'calendar.view',
        'shifts.viewAny',
        'family_portal.viewAny',
        'medications.view',
        'medications.audit.view',
    ]);

    $this->actingAs($staff)
        ->getJson(route('client.calendar.events', $client, false))
        ->assertForbidden();

    $portalClient = User::factory()->create([
        'organization_id' => 2,
        'role' => 'client',
        'approved_at' => now(),
    ]);
    grantClientCalendarDisclosurePermissions(
        $portalClient,
        ['clients.viewPortal', 'calendar.view'],
        'client',
    );
    $client->portalUsers()->attach($portalClient->id, ['relation' => 'self']);

    $this->actingAs($portalClient)
        ->getJson(route('client.calendar.events', $client, false))
        ->assertForbidden();
});
