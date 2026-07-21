<?php

use App\Models\AssetGeofence;
use App\Models\Client;
use App\Models\ClientAppointment;
use App\Models\ClientConsent;
use App\Models\ClientMedication;
use App\Models\ClientNote;
use App\Models\ClientRisk;
use App\Models\ConsentType;
use App\Models\Permission;
use App\Models\Role;
use App\Models\ServiceContext;
use App\Models\Site;
use App\Models\SiteHouseRoom;
use App\Models\TimelineEvent;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

function grantClientProfileFoundationPermissions(User $user, array $permissionKeys): void
{
    $role = Role::query()->firstOrCreate(
        ['name' => 'client_profile_foundation_'.$user->id],
        ['label' => 'Client Profile Foundation', 'level' => 50, 'type' => 'custom'],
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
    $user->roles()->syncWithoutDetaching([$role->id]);
}

function grantClientProfileFoundationRole(
    User $user,
    string $roleName,
    array $permissionKeys,
): void {
    $role = Role::query()->firstOrCreate(
        ['name' => $roleName],
        ['label' => str($roleName)->headline(), 'level' => 50, 'type' => 'custom'],
    );

    foreach ($permissionKeys as $key) {
        Permission::query()->firstOrCreate(
            ['key' => $key],
            ['description' => $key, 'group' => 'test', 'module' => 'Test'],
        );
    }

    $role->permissions()->syncWithoutDetaching(
        Permission::query()->whereIn('key', $permissionKeys)->pluck('id')->all(),
    );
    $user->roles()->syncWithoutDetaching([$role->id]);
}

it('hydrates every Add Client step when completing an existing profile', function () {
    $user = User::factory()->create(['organization_id' => 1]);
    grantClientProfileFoundationPermissions($user, ['clients.update']);
    $client = Client::factory()->create([
        'organization_id' => 1,
        'ethnicity' => 'Māori',
        'languages' => ['English', 'Te Reo Māori'],
        'mobility_needs' => 'Walking frame',
        'interests_hobbies' => 'Kapa haka',
        'service_start_date' => '2024-02-01',
        'risk_level' => 'high',
        'safeguarding_flag' => true,
    ]);
    $client->medicalProfile()->create([
        'gp_name' => 'Dr Aroha',
        'allergies' => ['penicillin'],
        'organ_donor' => false,
    ]);
    $client->conditions()->create([
        'label' => 'Epilepsy',
        'severity' => 'Severe',
        'notes' => 'Follow seizure plan.',
    ]);
    $client->emergencyContacts()->create([
        'name' => 'Hana Wineera',
        'relationship' => 'Sister',
        'phone' => '021 555 0101',
        'contact_order' => 1,
        'is_primary_contact' => true,
        'preferred_method' => 'phone',
        'can_view_medical' => true,
        'can_receive_updates' => true,
    ]);

    $this->actingAs($user)
        ->getJson("/operations/clients/{$client->id}/edit?modal=1")
        ->assertOk()
        ->assertJsonPath('initialValues.ethnicity', 'Māori')
        ->assertJsonPath('initialValues.languages.1', 'Te Reo Māori')
        ->assertJsonPath('initialValues.mobility_needs', 'Walking frame')
        ->assertJsonPath('initialValues.interests_hobbies', 'Kapa haka')
        ->assertJsonPath('initialValues.service_start_date', '2024-02-01')
        ->assertJsonPath('initialValues.risk_level', 'high')
        ->assertJsonPath('initialValues.safeguarding_flag', true)
        ->assertJsonPath('initialValues.medical.gp_name', 'Dr Aroha')
        ->assertJsonPath('initialValues.medical.allergies.0', 'penicillin')
        ->assertJsonPath('initialValues.conditions.0.label', 'Epilepsy')
        ->assertJsonPath('initialValues.emergency_contacts.0.name', 'Hana Wineera')
        ->assertJsonStructure([
            'sites',
            'serviceContexts',
            'keyWorkers',
            'geofences',
            'defaultServiceContextId',
        ]);
});

it('round trips the complete profile wizard payload through update and edit hydration', function () {
    $manager = User::factory()->create(['organization_id' => 1]);
    grantClientProfileFoundationPermissions($manager, ['clients.update']);
    $worker = User::factory()->create([
        'organization_id' => 1,
        'role' => 'support_worker',
    ]);
    $site = Site::factory()->create(['tenant_id' => 1]);
    $room = SiteHouseRoom::query()->create([
        'site_id' => $site->id,
        'tenant_id' => 1,
        'name' => 'Kōwhai Room',
        'is_active' => true,
        'is_assignable' => true,
    ]);
    $serviceContext = ServiceContext::factory()->create([
        'site_id' => $site->id,
        'name' => 'Supported living',
    ]);
    $geofence = AssetGeofence::query()->create([
        'site_id' => $site->id,
        'asset_id' => null,
        'name' => 'Kōwhai House',
        'type' => 'circle',
        'scope' => 'house',
        'shape' => ['lat' => -36.8485, 'lng' => 174.7633, 'radius_m' => 120],
        'breach_type' => 'both',
        'is_active' => true,
    ]);
    $client = Client::factory()->create(['organization_id' => 1]);

    $payload = [
        '_modal' => true,
        'site_id' => $site->id,
        'room_id' => $room->id,
        'service_context_id' => $serviceContext->id,
        'status' => 'active',
        'first_name' => 'Ariana',
        'last_name' => 'Raukawa',
        'preferred_name' => 'Ari',
        'date_of_birth' => '1991-07-18',
        'gender' => 'woman',
        'preferred_pronouns' => 'she/her',
        'nhi_number' => $client->nhi_number,
        'phone' => '021 555 0182',
        'email' => 'ari@example.test',
        'address_line_1' => '14 Kauri Lane',
        'address_line_2' => 'Unit 2',
        'suburb' => 'Ōtara',
        'city' => 'Tāmaki Makaurau',
        'postcode' => '2023',
        'ethnicity' => 'Māori',
        'languages' => ['English', 'Te Reo Māori'],
        'religion' => 'Rātana',
        'mobility_needs' => 'Uses a walking frame outdoors.',
        'sensory_needs' => 'Low-light environment preferred.',
        'cognitive_needs' => 'One instruction at a time.',
        'dietary_requirements' => 'Gluten free.',
        'sleep_preferences' => 'Quiet after 9pm.',
        'transport_needs' => ['wheelchair_vehicle', 'support_person'],
        'transport_notes' => 'Allow extra boarding time.',
        'fluid_intake_min_ml' => 1400,
        'fluid_intake_max_ml' => 2200,
        'seizure_duration_escalation_seconds' => 240,
        'interests_hobbies' => 'Kapa haka and gardening.',
        'strengths_abilities' => 'Strong visual memory.',
        'life_story' => 'Grew up near the Waikato River.',
        'education_level' => 'Certificate',
        'employment_status' => 'Part-time',
        'service_start_date' => '2025-03-01',
        'key_worker_id' => $worker->id,
        'risk_level' => 'high',
        'safeguarding_flag' => true,
        'house_geofence_id' => $geofence->id,
        'funding_type' => 'EGL',
        'funding_notes' => 'Annual allocation reviewed each March.',
        'medical' => [
            'gp_name' => 'Dr Aroha Ngata',
            'gp_practice' => 'Kōwhai Medical',
            'gp_phone' => '09 555 0100',
            'hospital_preference' => 'Middlemore Hospital',
            'blood_type' => 'O+',
            'organ_donor' => true,
            'allergies' => ['penicillin', 'peanuts'],
            'disabilities' => ['limited_mobility', 'epilepsy'],
            'medical_history' => 'Childhood asthma.',
            'mental_health_history' => 'No current concerns.',
            'surgical_history' => 'Appendectomy in 2014.',
            'immunisation_notes' => 'Influenza vaccine current.',
            'notes' => 'Prefers clinicians to explain procedures first.',
        ],
        'conditions' => [
            ['label' => 'Epilepsy', 'severity' => 'Severe', 'notes' => 'Follow seizure plan.'],
            ['label' => 'Asthma', 'severity' => 'Mild', 'notes' => 'Seasonal.'],
        ],
        'emergency_contacts' => [
            [
                'name' => 'Mere Raukawa',
                'relationship' => 'Mother',
                'phone' => '021 555 0190',
                'alternate_phone' => '09 555 0190',
                'email' => 'mere@example.test',
                'address' => '8 Rimu Road',
                'preferred_method' => 'text',
                'availability' => 'Any time',
                'notes' => 'Primary decision support person.',
                'can_view_medical' => true,
                'can_view_medications' => true,
                'can_view_incidents' => false,
                'can_receive_updates' => true,
            ],
        ],
    ];

    $this->actingAs($manager)
        ->from("/operations/clients/{$client->id}")
        ->put("/operations/clients/{$client->id}", $payload)
        ->assertRedirect("/operations/clients/{$client->id}")
        ->assertSessionHasNoErrors();

    $client->refresh()->load(['medicalProfile', 'conditions', 'emergencyContacts']);
    expect($client->only([
        'site_id',
        'room_id',
        'service_context_id',
        'first_name',
        'last_name',
        'preferred_name',
        'gender',
        'preferred_pronouns',
        'status',
        'phone',
        'email',
        'address_line_1',
        'address_line_2',
        'suburb',
        'city',
        'postcode',
        'ethnicity',
        'religion',
        'mobility_needs',
        'sensory_needs',
        'cognitive_needs',
        'dietary_requirements',
        'sleep_preferences',
        'transport_notes',
        'interests_hobbies',
        'strengths_abilities',
        'life_story',
        'education_level',
        'employment_status',
        'key_worker_id',
        'risk_level',
        'safeguarding_flag',
        'house_geofence_id',
        'funding_type',
        'funding_notes',
    ]))->toMatchArray([
        'site_id' => $site->id,
        'room_id' => $room->id,
        'service_context_id' => $serviceContext->id,
        'first_name' => 'Ariana',
        'last_name' => 'Raukawa',
        'preferred_name' => 'Ari',
        'gender' => 'woman',
        'preferred_pronouns' => 'she/her',
        'status' => 'active',
        'phone' => '021 555 0182',
        'email' => 'ari@example.test',
        'address_line_1' => '14 Kauri Lane',
        'address_line_2' => 'Unit 2',
        'suburb' => 'Ōtara',
        'city' => 'Tāmaki Makaurau',
        'postcode' => '2023',
        'ethnicity' => 'Māori',
        'religion' => 'Rātana',
        'mobility_needs' => 'Uses a walking frame outdoors.',
        'sensory_needs' => 'Low-light environment preferred.',
        'cognitive_needs' => 'One instruction at a time.',
        'dietary_requirements' => 'Gluten free.',
        'sleep_preferences' => 'Quiet after 9pm.',
        'transport_notes' => 'Allow extra boarding time.',
        'interests_hobbies' => 'Kapa haka and gardening.',
        'strengths_abilities' => 'Strong visual memory.',
        'life_story' => 'Grew up near the Waikato River.',
        'education_level' => 'Certificate',
        'employment_status' => 'Part-time',
        'key_worker_id' => $worker->id,
        'risk_level' => 'high',
        'safeguarding_flag' => true,
        'house_geofence_id' => $geofence->id,
        'funding_type' => 'EGL',
        'funding_notes' => 'Annual allocation reviewed each March.',
    ]);
    expect($client->languages)->toBe(['English', 'Te Reo Māori'])
        ->and($client->transport_needs)->toBe(['wheelchair_vehicle', 'support_person'])
        ->and($client->date_of_birth?->toDateString())->toBe('1991-07-18')
        ->and($client->service_start_date?->toDateString())->toBe('2025-03-01')
        ->and($client->fluid_intake_min_ml)->toBe(1400)
        ->and($client->fluid_intake_max_ml)->toBe(2200)
        ->and($client->seizure_duration_escalation_seconds)->toBe(240)
        ->and($client->medicalProfile?->allergies)->toBe(['penicillin', 'peanuts'])
        ->and($client->medicalProfile?->disabilities)->toBe(['limited_mobility', 'epilepsy'])
        ->and($client->conditions->pluck('label')->all())->toBe(['Epilepsy', 'Asthma'])
        ->and($client->emergencyContacts)->toHaveCount(1)
        ->and($client->emergencyContacts->first()?->can_view_medications)->toBeTrue();

    $this->actingAs($manager)
        ->getJson("/operations/clients/{$client->id}/edit?modal=1")
        ->assertOk()
        ->assertJsonPath('initialValues.first_name', 'Ariana')
        ->assertJsonPath('initialValues.languages.1', 'Te Reo Māori')
        ->assertJsonPath('initialValues.transport_needs.1', 'support_person')
        ->assertJsonPath('initialValues.medical.gp_name', 'Dr Aroha Ngata')
        ->assertJsonPath('initialValues.medical.allergies.1', 'peanuts')
        ->assertJsonPath('initialValues.conditions.1.label', 'Asthma')
        ->assertJsonPath('initialValues.emergency_contacts.0.alternate_phone', '09 555 0190')
        ->assertJsonPath('initialValues.emergency_contacts.0.can_view_medications', true)
        ->assertJsonPath('initialValues.house_geofence_id', (string) $geofence->id)
        ->assertJsonPath('initialValues.funding_type', 'EGL');
});

it('does not serialize Add Client geofences outside the available Site options', function () {
    $manager = User::factory()->create(['organization_id' => 1]);
    grantClientProfileFoundationPermissions($manager, ['clients.create']);
    $availableSite = Site::factory()->create(['tenant_id' => 1, 'name' => 'Available home']);
    $hiddenSite = Site::factory()->create(['tenant_id' => 2, 'name' => 'Hidden home']);
    $availableFence = AssetGeofence::query()->create([
        'site_id' => $availableSite->id,
        'name' => 'AVAILABLE-GEOFENCE-SENTINEL',
        'type' => 'circle',
        'scope' => 'house',
        'shape' => ['lat' => 1, 'lng' => 1, 'radius_m' => 50],
        'breach_type' => 'both',
        'is_active' => true,
    ]);
    $hiddenFence = AssetGeofence::query()->create([
        'site_id' => $hiddenSite->id,
        'name' => 'HIDDEN-GEOFENCE-SENTINEL',
        'type' => 'circle',
        'scope' => 'house',
        'shape' => ['lat' => 2, 'lng' => 2, 'radius_m' => 50],
        'breach_type' => 'both',
        'is_active' => true,
    ]);

    $this->actingAs($manager)
        ->get('/operations/clients/create')
        ->assertOk()
        ->assertInertia(function (Assert $page) use ($availableFence, $availableSite, $hiddenFence, $hiddenSite): void {
            $props = $page->toArray()['props'];

            expect(collect($props['sites'])->pluck('id')->all())
                ->toBe([$availableSite->id])
                ->not->toContain($hiddenSite->id)
                ->and(collect($props['geofences'])->pluck('id')->all())
                ->toBe([$availableFence->id])
                ->not->toContain($hiddenFence->id)
                ->and(json_encode($props['geofences'], JSON_THROW_ON_ERROR))
                ->not->toContain('HIDDEN-GEOFENCE-SENTINEL');
        });
});

it('scopes edit wizard geofences to the canonical client site and clears ineligible history', function () {
    $manager = User::factory()->create(['organization_id' => 1]);
    grantClientProfileFoundationPermissions($manager, ['clients.update']);
    $ownSite = Site::factory()->create(['tenant_id' => 1, 'name' => 'Own house']);
    $samePartitionSite = Site::factory()->create(['tenant_id' => 1, 'name' => 'Other same-partition house']);
    $foreignSite = Site::factory()->create(['tenant_id' => 2, 'name' => 'Foreign house']);
    $ownContext = ServiceContext::factory()->create([
        'site_id' => $ownSite->id,
        'name' => 'Own context',
    ]);
    $foreignContext = ServiceContext::factory()->create([
        'site_id' => $foreignSite->id,
        'name' => 'Foreign context',
    ]);
    $globalContext = ServiceContext::factory()->create([
        'site_id' => null,
        'name' => 'Shared context',
    ]);
    $ownGeofence = AssetGeofence::query()->create([
        'site_id' => $ownSite->id,
        'name' => 'Own active fence',
        'type' => 'circle',
        'scope' => 'house',
        'shape' => ['lat' => 1, 'lng' => 1, 'radius_m' => 50],
        'breach_type' => 'both',
        'is_active' => true,
    ]);
    $currentInactiveGeofence = AssetGeofence::query()->create([
        'site_id' => $ownSite->id,
        'name' => 'Own inactive current fence',
        'type' => 'circle',
        'scope' => 'house',
        'shape' => ['lat' => 2, 'lng' => 2, 'radius_m' => 50],
        'breach_type' => 'both',
        'is_active' => false,
    ]);
    $foreignGeofence = AssetGeofence::query()->create([
        'site_id' => $foreignSite->id,
        'name' => 'Foreign active fence',
        'type' => 'circle',
        'scope' => 'house',
        'shape' => ['lat' => 3, 'lng' => 3, 'radius_m' => 50],
        'breach_type' => 'both',
        'is_active' => true,
    ]);
    $samePartitionGeofence = AssetGeofence::query()->create([
        'site_id' => $samePartitionSite->id,
        'name' => 'Other same-partition active fence',
        'type' => 'circle',
        'scope' => 'house',
        'shape' => ['lat' => 4, 'lng' => 4, 'radius_m' => 50],
        'breach_type' => 'both',
        'is_active' => true,
    ]);
    $client = Client::factory()->create([
        'organization_id' => 1,
        'site_id' => $ownSite->id,
        'service_context_id' => $ownContext->id,
        'house_geofence_id' => $currentInactiveGeofence->id,
    ]);

    $response = $this->actingAs($manager)
        ->getJson("/operations/clients/{$client->id}/edit?modal=1")
        ->assertOk()
        ->assertJsonPath('initialValues.house_geofence_id', '');

    expect(collect($response->json('sites'))->pluck('id')->all())
        ->toContain($ownSite->id)
        ->not->toContain($foreignSite->id);
    expect(collect($response->json('serviceContexts'))->pluck('id')->all())
        ->toContain($ownContext->id, $globalContext->id)
        ->not->toContain($foreignContext->id);
    expect(collect($response->json('geofences'))->pluck('id')->all())
        ->toBe([$ownGeofence->id])
        ->not->toContain($currentInactiveGeofence->id, $samePartitionGeofence->id, $foreignGeofence->id);

    $this->from("/operations/clients/{$client->id}")
        ->put("/operations/clients/{$client->id}", [
            'first_name' => $client->first_name,
            'last_name' => $client->last_name,
            'status' => $client->status,
            'site_id' => $ownSite->id,
            'phone' => '021 555 0199',
            'house_geofence_id' => '',
        ])
        ->assertSessionHasNoErrors();

    expect($client->fresh()->phone)->toBe('021 555 0199')
        ->and($client->fresh()->house_geofence_id)->toBeNull();
});

it('rejects same-partition cross-site geofences on client create and update', function () {
    $manager = User::factory()->create(['organization_id' => 1]);
    grantClientProfileFoundationPermissions($manager, ['clients.create', 'clients.update']);
    $clientSite = Site::factory()->create(['tenant_id' => 1, 'name' => 'Client home']);
    $otherSite = Site::factory()->create(['tenant_id' => 1, 'name' => 'Other home']);
    $otherFence = AssetGeofence::query()->create([
        'site_id' => $otherSite->id,
        'name' => 'Other home fence',
        'type' => 'circle',
        'scope' => 'house',
        'shape' => ['lat' => 5, 'lng' => 5, 'radius_m' => 50],
        'breach_type' => 'both',
        'is_active' => true,
    ]);

    $this->actingAs($manager)
        ->from('/operations/clients')
        ->post('/operations/clients', [
            'first_name' => 'Create',
            'last_name' => 'Boundary',
            'status' => 'active',
            'site_id' => $clientSite->id,
            'house_geofence_id' => $otherFence->id,
        ])
        ->assertSessionHasErrors('house_geofence_id');

    expect(Client::query()->count())->toBe(0);

    $client = Client::factory()->create([
        'organization_id' => 1,
        'site_id' => $clientSite->id,
        'house_geofence_id' => null,
    ]);

    $this->from("/operations/clients/{$client->id}")
        ->put("/operations/clients/{$client->id}", [
            'first_name' => $client->first_name,
            'last_name' => $client->last_name,
            'status' => $client->status,
            'site_id' => $clientSite->id,
            'house_geofence_id' => $otherFence->id,
        ])
        ->assertSessionHasErrors('house_geofence_id');

    expect($client->fresh()->house_geofence_id)->toBeNull();
});

it('rejects foreign organization location options on profile update', function () {
    $manager = User::factory()->create(['organization_id' => 1]);
    grantClientProfileFoundationPermissions($manager, ['clients.update']);
    $foreignSite = Site::factory()->create(['tenant_id' => 2]);
    $foreignContext = ServiceContext::factory()->create(['site_id' => $foreignSite->id]);
    $foreignGeofence = AssetGeofence::query()->create([
        'site_id' => $foreignSite->id,
        'name' => 'Foreign fence',
        'type' => 'circle',
        'scope' => 'house',
        'shape' => ['lat' => 4, 'lng' => 4, 'radius_m' => 50],
        'breach_type' => 'both',
        'is_active' => true,
    ]);
    $client = Client::factory()->create(['organization_id' => 1]);

    $this->actingAs($manager)
        ->from("/operations/clients/{$client->id}")
        ->put("/operations/clients/{$client->id}", [
            'first_name' => $client->first_name,
            'last_name' => $client->last_name,
            'status' => $client->status,
            'site_id' => $foreignSite->id,
            'service_context_id' => $foreignContext->id,
            'house_geofence_id' => $foreignGeofence->id,
        ])
        ->assertRedirect("/operations/clients/{$client->id}")
        ->assertSessionHasErrors([
            'site_id',
            'service_context_id',
            'house_geofence_id',
        ]);

    expect($client->fresh()->site_id)->toBeNull()
        ->and($client->fresh()->service_context_id)->toBeNull()
        ->and($client->fresh()->house_geofence_id)->toBeNull();
});

it('omits restricted section props when an assigned worker lacks their capabilities', function () {
    $worker = User::factory()->create(['organization_id' => 1]);
    grantClientProfileFoundationRole($worker, 'support_worker', [
        'clients.viewAssigned',
    ]);
    $client = Client::factory()->create(['organization_id' => 1]);
    $client->supportWorkers()->attach($worker->id);
    $client->medicalProfile()->create([
        'allergies' => ['peanut'],
        'disabilities' => ['epilepsy'],
    ]);
    ClientRisk::query()->create([
        'client_id' => $client->id,
        'label' => 'Confidential high risk',
        'severity' => 'high',
        'active' => true,
    ]);
    ClientMedication::factory()->create([
        'client_id' => $client->id,
        'name' => 'Confidential medicine',
        'dosage' => '20mg',
        'frequency' => '08:00',
        'is_prn' => false,
        'active' => true,
        'state' => 'active',
        'ceased_at' => null,
    ]);
    ClientNote::query()->create([
        'organization_id' => 1,
        'client_id' => $client->id,
        'user_id' => $worker->id,
        'type' => 'daily_note',
        'body' => 'Confidential daily note',
        'occurred_at' => now(),
        'visibility' => 'internal',
    ]);

    $this->actingAs($worker)
        ->get("/operations/clients/{$client->id}")
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('operations/clients/show')
            ->missing('medical')
            ->missing('health_monitoring')
            ->missing('emar_summary')
            ->missing('client_finance')
            ->missing('consents')
            ->missing('consent_type_options')
            ->missing('consent_request_list')
            ->missing('client_risks')
            ->missing('hs_risk_assessments')
            ->missing('client_incidents')
            ->missing('first_aid_records')
            ->missing('documents')
            ->missing('portal_users')
            ->missing('audit_history')
            ->missing('data_subject_requests')
            ->missing('respite')
            ->missing('events')
            ->missing('handover')
            ->missing('client_daily_notes')
            ->missing('communication_notes')
            ->missing('daily_notes_summary')
            ->missing('care_plans_summary')
            ->missing('assessments')
            ->missing('behaviour_patterns')
            ->missing('path_plan')
            ->missing('calendar_events')
            ->has('safety.allergies', 0)
            ->has('safety.critical_risks', 0)
            ->where(
                'safety.care_flags',
                fn ($flags) => collect($flags)->doesntContain(
                    fn ($flag) => str_starts_with((string) ($flag['key'] ?? ''), 'disability_'),
                ),
            )
            ->where('profile_section_access.notes', false)
            ->where('profile_section_access.timeline', false)
            ->where('profile_section_access.care_plans', false)
            ->where('profile_section_access.assessments', false)
            ->where('profile_section_access.behaviour', false)
            ->where('profile_section_access.calendar', false));
});

it('redirects linked portal users away from the legacy staff profile route', function () {
    $portalUser = User::factory()->create(['organization_id' => 1]);
    grantClientProfileFoundationRole($portalUser, 'next_of_kin', [
        'clients.viewPortal',
    ]);
    $client = Client::factory()->create(['organization_id' => 1]);
    $client->portalUsers()->attach($portalUser->id, [
        'relation' => 'next_of_kin',
    ]);

    $this->actingAs($portalUser)
        ->get("/clients/{$client->id}?tab=documents")
        ->assertRedirect("/portal/clients/{$client->id}?tab=documents");
});

it('requires granular consent authority and parent client access', function () {
    $sameOrgUpdater = User::factory()->create(['organization_id' => 1]);
    grantClientProfileFoundationRole($sameOrgUpdater, 'manager', [
        'clients.update',
    ]);
    $sameOrgClient = Client::factory()->create(['organization_id' => 1]);
    $consentType = ConsentType::factory()->create(['active' => true]);

    $this->actingAs($sameOrgUpdater)
        ->post("/operations/clients/{$sameOrgClient->id}/consents", [
            'consent_type_id' => $consentType->id,
            'status' => 'given',
            'given_method' => 'written',
            'given_at' => now()->toDateString(),
        ])
        ->assertForbidden();

    $crossOrgViewer = User::factory()->create(['organization_id' => 1]);
    grantClientProfileFoundationRole($crossOrgViewer, 'coordinator', [
        'clients.viewAny',
        'consents.viewAny',
        'consents.request',
    ]);
    $otherOrgClient = Client::factory()->create(['organization_id' => 2]);

    $this->actingAs($crossOrgViewer)
        ->get("/operations/clients/{$otherOrgClient->id}/consents")
        ->assertForbidden();
    $this->actingAs($crossOrgViewer)
        ->get("/operations/clients/{$otherOrgClient->id}/consent-requests")
        ->assertForbidden();
});

it('enforces consent nesting and only withdraws a currently given consent once', function () {
    $manager = User::factory()->create(['organization_id' => 1]);
    grantClientProfileFoundationRole($manager, 'manager', [
        'clients.viewAny',
        'consents.record',
        'consents.withdraw',
    ]);
    $client = Client::factory()->create(['organization_id' => 1]);
    $otherClient = Client::factory()->create(['organization_id' => 1]);
    $consentType = ConsentType::factory()->create(['active' => true]);
    $given = ClientConsent::query()->create([
        'client_id' => $client->id,
        'consent_type_id' => $consentType->id,
        'status' => 'given',
        'given_at' => now(),
        'given_method' => 'written',
        'given_by_user_id' => $manager->id,
        'created_by' => $manager->id,
    ]);
    $refused = ClientConsent::query()->create([
        'client_id' => $client->id,
        'consent_type_id' => $consentType->id,
        'status' => 'refused',
        'given_at' => now(),
        'given_method' => 'written',
        'given_by_user_id' => $manager->id,
        'created_by' => $manager->id,
    ]);

    $this->actingAs($manager)
        ->post("/operations/clients/{$otherClient->id}/consents/{$given->id}/withdraw", [
            'withdrawal_reason' => 'Wrong nested client',
        ])
        ->assertNotFound();

    $this->actingAs($manager)
        ->post("/operations/clients/{$client->id}/consents/{$given->id}/withdraw", [
            'withdrawal_reason' => 'Client changed their decision',
        ])
        ->assertRedirect();
    expect($given->fresh()->status)->toBe('withdrawn');

    $this->actingAs($manager)
        ->from("/operations/clients/{$client->id}?tab=consents")
        ->post("/operations/clients/{$client->id}/consents/{$given->id}/withdraw", [
            'withdrawal_reason' => 'Repeated transition',
        ])
        ->assertSessionHasErrors('status');
    $this->actingAs($manager)
        ->from("/operations/clients/{$client->id}?tab=consents")
        ->post("/operations/clients/{$client->id}/consents/{$refused->id}/withdraw", [
            'withdrawal_reason' => 'Invalid refused transition',
        ])
        ->assertSessionHasErrors('status');
});

it('requires appointment mutation capabilities and emits one canonical timeline event', function () {
    $client = Client::factory()->create(['organization_id' => 1]);
    $viewer = User::factory()->create(['organization_id' => 1]);
    grantClientProfileFoundationRole($viewer, 'manager', ['clients.viewAny']);
    $payload = [
        'title' => 'GP review',
        'appointment_type' => 'gp_visit',
        'starts_at' => now()->addDay()->toISOString(),
        'share_with_family' => true,
    ];

    $this->actingAs($viewer)
        ->postJson("/clients/{$client->id}/calendar/appointments", $payload)
        ->assertForbidden();
    expect(ClientAppointment::query()->where('client_id', $client->id)->exists())
        ->toBeFalse();

    $creator = User::factory()->create(['organization_id' => 1]);
    grantClientProfileFoundationRole($creator, 'appointment_coordinator', [
        'clients.viewAny',
        'calendar.create',
    ]);

    $response = $this->actingAs($creator)
        ->postJson("/clients/{$client->id}/calendar/appointments", $payload)
        ->assertOk();
    $appointmentId = (int) $response->json('appointment.id');

    expect(TimelineEvent::query()
        ->where('source_type', ClientAppointment::class)
        ->where('source_id', $appointmentId)
        ->count())->toBe(1);
});

it('prevents quick updates across organisations and rejects foreign key workers', function () {
    $manager = User::factory()->create(['organization_id' => 1]);
    grantClientProfileFoundationRole($manager, 'manager', ['clients.update']);
    $otherOrgClient = Client::factory()->create(['organization_id' => 2]);

    $this->actingAs($manager)
        ->patch("/operations/clients/{$otherOrgClient->id}/quick-update", [
            'risk_level' => 'critical',
        ])
        ->assertForbidden();

    $sameOrgClient = Client::factory()->create(['organization_id' => 1]);
    $foreignWorker = User::factory()->create([
        'organization_id' => 2,
        'role' => 'support_worker',
    ]);

    $this->actingAs($manager)
        ->from("/operations/clients/{$sameOrgClient->id}")
        ->patch("/operations/clients/{$sameOrgClient->id}/quick-update", [
            'key_worker_id' => $foreignWorker->id,
        ])
        ->assertSessionHasErrors('key_worker_id');
});

it('applies the sensitive section capability matrix without broad profile leakage', function () {
    $client = Client::factory()->create(['organization_id' => 1]);

    $manager = User::factory()->create(['organization_id' => 1]);
    grantClientProfileFoundationRole($manager, 'manager', [
        'clients.viewAny',
        'clients.update',
        'medications.view',
        'clinical.observations.viewAny',
        'client_funds.manage',
        'consents.viewAny',
        'risks.viewAny',
        'incidents.viewAny',
        'hazards.view',
        'family_portal.viewAny',
        'audit.viewAny',
        'privacy.viewRequests',
        'respite.viewAny',
    ]);

    $this->actingAs($manager)
        ->get("/operations/clients/{$client->id}")
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('medical')
            ->has('health_monitoring')
            ->has('emar_summary')
            ->has('client_finance')
            ->has('consents')
            ->has('client_risks')
            ->has('client_incidents')
            ->has('first_aid_records')
            ->has('documents')
            ->has('portal_users')
            ->has('audit_history')
            ->has('data_subject_requests')
            ->has('respite'));

    $financeOnly = User::factory()->create(['organization_id' => 1]);
    grantClientProfileFoundationRole($financeOnly, 'finance', [
        'clients.viewAny',
        'client_funds.manage',
    ]);

    $this->actingAs($financeOnly)
        ->get("/operations/clients/{$client->id}")
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('client_finance')
            ->missing('medical')
            ->missing('consents')
            ->missing('documents')
            ->missing('audit_history')
            ->missing('data_subject_requests'));

    $auditor = User::factory()->create(['organization_id' => 1]);
    grantClientProfileFoundationRole($auditor, 'auditor', [
        'clients.viewAny',
        'audit.viewAny',
    ]);

    $this->actingAs($auditor)
        ->get("/operations/clients/{$client->id}")
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('audit_history')
            ->missing('medical')
            ->missing('client_finance')
            ->missing('documents')
            ->missing('portal_users'));

    $assignedClinicalWorker = User::factory()->create(['organization_id' => 1]);
    grantClientProfileFoundationRole($assignedClinicalWorker, 'support_worker', [
        'clients.viewAssigned',
        'medications.view',
        'clinical.observations.viewAssigned',
        'risks.viewAssigned',
        'incidents.viewAssigned',
        'hazards.view',
    ]);
    $client->supportWorkers()->attach($assignedClinicalWorker->id);

    $this->actingAs($assignedClinicalWorker)
        ->get("/operations/clients/{$client->id}")
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('medical')
            ->has('health_monitoring')
            ->has('client_risks')
            ->has('client_incidents')
            ->has('first_aid_records')
            ->missing('client_finance')
            ->missing('documents')
            ->missing('audit_history'));

    $unassignedWorker = User::factory()->create(['organization_id' => 1]);
    grantClientProfileFoundationPermissions($unassignedWorker, ['clients.viewAssigned']);
    $this->actingAs($unassignedWorker)
        ->get("/operations/clients/{$client->id}")
        ->assertForbidden();

    $crossOrgManager = User::factory()->create(['organization_id' => 2]);
    grantClientProfileFoundationRole($crossOrgManager, 'cross_org_manager', [
        'clients.viewAny',
    ]);
    $this->actingAs($crossOrgManager)
        ->get("/operations/clients/{$client->id}")
        ->assertForbidden();
});

it('redirects linked portal clients away from every legacy staff profile variant', function () {
    $portalClient = User::factory()->create(['organization_id' => 1]);
    grantClientProfileFoundationRole($portalClient, 'client', [
        'clients.viewPortal',
    ]);
    $client = Client::factory()->create(['organization_id' => 1]);
    $client->portalUsers()->attach($portalClient->id, [
        'relation' => 'self',
    ]);

    $response = $this->actingAs($portalClient)
        ->get("/clients/{$client->id}?tab=medical&modal=1", [
            'Accept' => 'application/json',
        ]);

    $response->assertRedirectContains("/portal/clients/{$client->id}");
    parse_str(
        (string) parse_url($response->headers->get('Location'), PHP_URL_QUERY),
        $query,
    );
    expect($query['tab'] ?? null)->toBe('medical')
        ->and($query['modal'] ?? null)->toBe('1');
});
