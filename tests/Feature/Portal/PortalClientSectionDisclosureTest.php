<?php

use App\Models\Client;
use App\Models\ClientConsent;
use App\Models\ClientIncident;
use App\Models\ClientMedication;
use App\Models\ConsentType;
use App\Models\FamilyNote;
use App\Models\FamilyPortalSetting;
use App\Models\NextOfKin;
use App\Models\Permission;
use App\Models\RespiteBooking;
use App\Models\Role;
use App\Models\Shift;
use App\Models\TimelineEvent;
use App\Models\User;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;

function grantPortalSectionDisclosureRole(
    User $user,
    string $roleName,
    array $permissionKeys,
): void {
    $role = Role::query()->firstOrCreate(
        ['name' => $roleName],
        [
            'label' => str($roleName)->headline(),
            'level' => 15,
            'type' => 'system',
        ],
    );

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

function makePortalSectionDisclosureIdentity(
    Client $client,
    string $roleName = 'next_of_kin',
    string $relation = 'next_of_kin',
    array $flags = [],
    array $permissionKeys = ['clients.viewPortal', 'incidents.view.portal'],
    ?int $organizationId = null,
    bool $createNok = true,
): User {
    $user = User::factory()->create([
        'organization_id' => $organizationId ?? $client->organization_id,
        'role' => $roleName,
        'approved_at' => now(),
    ]);
    grantPortalSectionDisclosureRole($user, $roleName, $permissionKeys);
    $client->portalUsers()->attach($user->id, ['relation' => $relation]);

    if ($createNok) {
        NextOfKin::query()->create([
            'user_id' => $user->id,
            'client_id' => $client->id,
            'relationship' => 'guardian',
            'can_view_medical' => (bool) ($flags['can_view_medical'] ?? false),
            'can_view_medications' => (bool) ($flags['can_view_medications'] ?? false),
            'can_view_incidents' => (bool) ($flags['can_view_incidents'] ?? false),
        ]);
    }

    return $user;
}

function enablePortalSectionDisclosureSettings(Client $client, array $overrides = []): FamilyPortalSetting
{
    return FamilyPortalSetting::query()->create([
        'organization_id' => $client->organization_id,
        'client_id' => $client->id,
        'show_shift_schedule' => true,
        'show_respite' => true,
        'show_care_notes' => true,
        'show_care_plans' => true,
        'show_medication_status' => true,
        'show_incidents' => true,
        ...$overrides,
    ]);
}

function recordPortalSectionFamilyConsent(Client $client, User $actor, array $overrides = []): ClientConsent
{
    $type = ConsentType::factory()->create([
        'name' => 'Information Sharing with Whānau / Family',
        'category' => 'communication',
    ]);

    return ClientConsent::query()->create([
        'client_id' => $client->id,
        'consent_type_id' => $type->id,
        'status' => 'given',
        'given_at' => now()->subHour(),
        'expires_at' => now()->addMonth(),
        'given_by_user_id' => $actor->id,
        'given_by_relationship' => 'next_of_kin',
        'given_method' => 'portal',
        'created_by' => $actor->id,
        'updated_by' => $actor->id,
        ...$overrides,
    ]);
}

/** @return array<string, int> */
function seedPortalSectionDisclosureData(Client $client, User $actor): array
{
    $client->forceFill([
        'dietary_requirements' => 'Private dietary detail',
        'mobility_needs' => 'Private mobility detail',
    ])->save();
    $client->medicalProfile()->create([
        'medical_history' => 'Private medical history',
        'allergies' => ['penicillin'],
        'notes' => 'Private medical note',
    ]);
    $medication = ClientMedication::factory()->create([
        'client_id' => $client->id,
        'name' => 'Private portal medication',
        'active' => true,
        'state' => 'active',
    ]);
    $incident = ClientIncident::factory()->reviewed()->highSeverity()->create([
        'client_id' => $client->id,
        'portal_visible' => true,
        'occurred_at' => now()->subHour(),
    ]);
    $careEvent = TimelineEvent::factory()->create([
        'client_id' => $client->id,
        'actor_user_id' => $actor->id,
        'created_by' => $actor->id,
        'visibility' => 'portal',
        'type' => 'progress_note',
        'subject' => 'Private care update',
        'occurred_at' => now()->subMinutes(30),
    ]);
    $shiftEvent = TimelineEvent::factory()->create([
        'client_id' => $client->id,
        'actor_user_id' => $actor->id,
        'created_by' => $actor->id,
        'visibility' => 'portal',
        'type' => 'shift',
        'subject' => 'Private shift update',
        'occurred_at' => now()->subMinutes(20),
    ]);
    $shift = Shift::factory()->create([
        'client_id' => $client->id,
        'user_id' => $actor->id,
        'starts_at' => now()->subHour(),
        'ends_at' => now()->addHour(),
        'status' => 'in_progress',
    ]);
    $respite = RespiteBooking::factory()->create([
        'client_id' => $client->id,
        'start_at' => now()->addDay(),
        'end_at' => now()->addDays(2),
        'status' => 'confirmed',
    ]);
    $familyNote = FamilyNote::query()->create([
        'client_id' => $client->id,
        'created_by' => $actor->id,
        'title' => 'Private family note',
        'status' => 'open',
        'due_date' => now()->addDay(),
    ]);

    return [
        'medication' => $medication->id,
        'incident' => $incident->id,
        'care_event' => $careEvent->id,
        'shift_event' => $shiftEvent->id,
        'shift' => $shift->id,
        'respite' => $respite->id,
        'family_note' => $familyNote->id,
    ];
}

/** @return array<int, string> */
function portalSectionCalendarTypes(TestResponse $response): array
{
    return collect($response->json())
        ->pluck('extendedProps.type')
        ->filter()
        ->unique()
        ->sort()
        ->values()
        ->all();
}

it('fails closed across portal readers for a NOK without active family information consent', function () {
    $client = Client::factory()->create(['organization_id' => 1]);
    $nok = makePortalSectionDisclosureIdentity($client, flags: [
        'can_view_medical' => true,
        'can_view_medications' => true,
        'can_view_incidents' => true,
    ]);
    enablePortalSectionDisclosureSettings($client);
    seedPortalSectionDisclosureData($client, $nok);

    $this->actingAs($nok)
        ->get(route('portal.clients.show', $client, false))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->missing('profile')
            ->missing('medications')
            ->missing('conditions')
            ->missing('emergency_contacts')
            ->has('events', 0)
            ->has('incidents', 0)
            ->where('can.viewIncidents', false));

    $this->get(route('portal.clients.health', $client, false))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('client.dietary_requirements', null)
            ->where('client.mobility_needs', null)
            ->where('medicalProfile', null)
            ->has('medications', 0)
            ->where('permissions.can_view_medical', false)
            ->where('permissions.can_view_medications', false));

    $this->get(route('portal.clients.timeline', $client, false))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('events.data', 0)
            ->where('showShiftSchedule', false));

    $this->get(route('portal.clients.dashboard', $client, false))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('todayShifts', 0)
            ->has('weekShifts', 0)
            ->has('monthShifts', 0)
            ->has('upcomingRespite', 0)
            ->has('recentEvents', 0)
            ->has('recentIncidents', 0)
            ->where('medicalSummary', null)
            ->has('criticalAlerts', 0)
            ->where('stats.incidentsLast30Days', 0)
            ->where('dailySummary.lastEvent', null)
            ->where('familyNotesSummary.open', 0)
            ->has('familyNotesSummary.recent', 0));

    $this->get(route('portal.clients.schedule', $client, false))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('shifts', 0)
            ->has('respiteStays', 0)
            ->where('showShifts', false)
            ->where('showRespite', false));

    $calendar = $this->getJson(route('portal.clients.calendar.events', $client, false))
        ->assertOk();
    expect(portalSectionCalendarTypes($calendar))->toBe([]);

    $this->get(route('portal.clients.family-notes', $client, false))
        ->assertForbidden();
});

it('combines active consent portal settings and NOK flags for positive family disclosure', function () {
    $client = Client::factory()->create(['organization_id' => 1]);
    $nok = makePortalSectionDisclosureIdentity($client, flags: [
        'can_view_medical' => true,
        'can_view_medications' => true,
        'can_view_incidents' => true,
    ]);
    enablePortalSectionDisclosureSettings($client);
    recordPortalSectionFamilyConsent($client, $nok);
    $records = seedPortalSectionDisclosureData($client, $nok);

    $this->actingAs($nok)
        ->get(route('portal.clients.show', $client, false))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('profile.medical_history', 'Private medical history')
            ->where('medications.0.id', $records['medication'])
            ->where('events', fn ($events) => collect($events)->pluck('id')->contains($records['care_event'])
                && collect($events)->pluck('id')->contains($records['shift_event']))
            ->where('incidents.0.id', $records['incident'])
            ->where('can.viewIncidents', true));

    $this->get(route('portal.clients.health', $client, false))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('client.dietary_requirements', 'Private dietary detail')
            ->where('client.mobility_needs', 'Private mobility detail')
            ->where('medicalProfile.medical_history', 'Private medical history')
            ->where('medications.0.id', $records['medication'])
            ->where('permissions.can_view_medical', true)
            ->where('permissions.can_view_medications', true));

    $this->get(route('portal.clients.timeline', $client, false))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('events.data', fn ($events) => collect($events)->pluck('id')->contains($records['care_event'])
                && collect($events)->pluck('id')->contains($records['shift_event']))
            ->where('showShiftSchedule', true));

    $this->get(route('portal.clients.dashboard', $client, false))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('todayShifts', 1)
            ->has('upcomingRespite', 1)
            ->where('recentEvents', fn ($events) => collect($events)->pluck('id')->contains($records['care_event'])
                && collect($events)->pluck('id')->contains($records['shift_event']))
            ->where('recentIncidents.0.id', $records['incident'])
            ->where('medicalSummary.notes', 'Private medical note')
            ->has('criticalAlerts', 1)
            ->where('stats.incidentsLast30Days', 1)
            ->where('familyNotesSummary.open', 1));

    $this->get(route('portal.clients.schedule', $client, false))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('shifts', 1)
            ->has('respiteStays', 1)
            ->where('showShifts', true)
            ->where('showRespite', true));

    $calendar = $this->getJson(route('portal.clients.calendar.events', $client, false))
        ->assertOk();
    expect(portalSectionCalendarTypes($calendar))->toBe([
        'family_note',
        'respite_stay',
        'shift',
    ]);

    $this->get(route('portal.clients.family-notes', $client, false))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->has('notes', 1));
});

it('keeps each disabled family portal setting effective even with active consent and permissive NOK flags', function () {
    $client = Client::factory()->create(['organization_id' => 1]);
    $nok = makePortalSectionDisclosureIdentity($client, flags: [
        'can_view_medical' => true,
        'can_view_medications' => true,
        'can_view_incidents' => true,
    ]);
    enablePortalSectionDisclosureSettings($client, [
        'show_shift_schedule' => false,
        'show_respite' => false,
        'show_care_notes' => false,
        'show_medication_status' => false,
        'show_incidents' => false,
    ]);
    recordPortalSectionFamilyConsent($client, $nok);
    seedPortalSectionDisclosureData($client, $nok);

    $this->actingAs($nok)
        ->get(route('portal.clients.show', $client, false))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('events', 0)
            ->has('incidents', 0)
            ->where('can.viewIncidents', false)
            ->missing('medications')
            ->where('profile.medical_history', 'Private medical history'));

    $this->get(route('portal.clients.health', $client, false))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('medications', 0)
            ->where('permissions.can_view_medications', false)
            ->where('permissions.show_medication_status', false));

    $this->get(route('portal.clients.timeline', $client, false))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('events.data', 0)
            ->where('showShiftSchedule', false));

    $this->get(route('portal.clients.dashboard', $client, false))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('todayShifts', 0)
            ->has('upcomingRespite', 0)
            ->has('recentEvents', 0)
            ->has('recentIncidents', 0)
            ->has('criticalAlerts', 0)
            ->where('stats.incidentsLast30Days', 0)
            ->where('familyNotesSummary.open', 0));

    $this->get(route('portal.clients.schedule', $client, false))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('shifts', 0)
            ->has('respiteStays', 0));

    $calendar = $this->getJson(route('portal.clients.calendar.events', $client, false))
        ->assertOk();
    expect(portalSectionCalendarTypes($calendar))->toBe([]);

    $this->get(route('portal.clients.family-notes', $client, false))
        ->assertForbidden();
});

it('does not apply family disclosure controls to the person viewing their own portal record', function () {
    $client = Client::factory()->create(['organization_id' => 1]);
    $portalClient = makePortalSectionDisclosureIdentity(
        $client,
        roleName: 'client',
        relation: 'self',
        createNok: false,
    );
    enablePortalSectionDisclosureSettings($client, [
        'show_shift_schedule' => false,
        'show_respite' => false,
        'show_care_notes' => false,
        'show_incidents' => false,
    ]);
    $records = seedPortalSectionDisclosureData($client, $portalClient);

    $this->actingAs($portalClient)
        ->get(route('portal.clients.show', $client, false))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('profile.medical_history', 'Private medical history')
            ->where('medications.0.id', $records['medication'])
            ->where('events', fn ($events) => collect($events)->pluck('id')->contains($records['care_event'])
                && collect($events)->pluck('id')->contains($records['shift_event']))
            ->where('incidents.0.id', $records['incident'])
            ->where('can.viewIncidents', true));

    $this->get(route('portal.clients.timeline', $client, false))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('events.data', fn ($events) => collect($events)->pluck('id')->contains($records['care_event'])
                && collect($events)->pluck('id')->contains($records['shift_event']))
            ->where('showShiftSchedule', true));

    $this->get(route('portal.clients.health', $client, false))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('medicalProfile.medical_history', 'Private medical history')
            ->where('medications.0.id', $records['medication']));

    $this->get(route('portal.clients.schedule', $client, false))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('shifts', 1)
            ->has('respiteStays', 1));

    $calendar = $this->getJson(route('portal.clients.calendar.events', $client, false))
        ->assertOk();
    expect(portalSectionCalendarTypes($calendar))->toBe([
        'family_note',
        'respite_stay',
        'shift',
    ]);

    $this->get(route('portal.clients.family-notes', $client, false))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->has('notes', 1));
});

it('fails closed for ambiguous self links and denies unlinked or cross-organization portal readers', function () {
    $client = Client::factory()->create(['organization_id' => 2]);
    $ambiguous = makePortalSectionDisclosureIdentity(
        $client,
        roleName: 'next_of_kin',
        relation: 'self',
        flags: [
            'can_view_medical' => true,
            'can_view_medications' => true,
            'can_view_incidents' => true,
        ],
    );
    enablePortalSectionDisclosureSettings($client);
    seedPortalSectionDisclosureData($client, $ambiguous);

    $this->actingAs($ambiguous)
        ->get(route('portal.clients.show', $client, false))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->missing('profile')
            ->missing('medications')
            ->has('events', 0)
            ->has('incidents', 0));

    $unlinked = User::factory()->create([
        'organization_id' => 2,
        'role' => 'next_of_kin',
        'approved_at' => now(),
    ]);
    grantPortalSectionDisclosureRole($unlinked, 'next_of_kin', ['clients.viewPortal']);

    foreach (['dashboard', 'timeline', 'health'] as $surface) {
        $this->actingAs($unlinked)
            ->get(route("portal.clients.{$surface}", $client, false))
            ->assertForbidden();
    }

    $crossOrganization = makePortalSectionDisclosureIdentity(
        $client,
        organizationId: 1,
    );
    foreach (['dashboard', 'timeline', 'health'] as $surface) {
        $this->actingAs($crossOrganization)
            ->get(route("portal.clients.{$surface}", $client, false))
            ->assertForbidden();
    }
});
