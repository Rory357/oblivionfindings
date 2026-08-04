<?php

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Http\Middleware\HandleInertiaRequests;
use App\Models\Client;
use App\Models\ClientExcursionRequest;
use App\Models\ClientLeaveRequest;
use App\Models\ClientMealLog;
use App\Models\ClientOnboardingWorkflow;
use App\Models\ClientPersonalAsset;
use App\Models\ClientPhoto;
use App\Models\ClientRisk;
use App\Models\ClientRoutine;
use App\Models\FamilyNote;
use App\Models\HsEvent;
use App\Models\Permission;
use App\Models\Role;
use App\Models\ServiceAgreement;
use App\Models\Site;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

function grantSensitiveProfilePermissions(User $user, array $permissions): void
{
    $role = Role::query()->firstOrCreate(
        ['name' => 'sensitive_profile_'.$user->id],
        ['label' => 'Sensitive profile', 'level' => 20, 'type' => 'custom'],
    );

    foreach ($permissions as $permission) {
        Permission::query()->firstOrCreate(
            ['key' => $permission],
            ['description' => $permission, 'group' => 'test', 'module' => 'Test'],
        );
    }

    $role->permissions()->sync(
        Permission::query()->whereIn('key', $permissions)->pluck('id')->all(),
    );
    $user->roles()->syncWithoutDetaching([$role->id]);
}

function scopeSensitiveProfileUserToSite(User $user, Site $site, string $positionRole): void
{
    HrEmployeeProfile::factory()->create([
        'user_id' => $user->id,
        'position_role' => $positionRole,
        'primary_site_id' => $site->id,
        'secondary_site_ids' => [],
        'start_date' => now()->subMonth()->toDateString(),
        'end_date' => null,
        'is_active' => true,
    ]);
}

function seedSensitiveProfileSentinels(Client $client, User $author): void
{
    ClientOnboardingWorkflow::query()->create([
        'client_id' => $client->id,
        'status' => 'in_progress',
        'started_at' => now(),
        'assigned_to' => $author->id,
        'notes' => 'Restricted onboarding note',
        'created_by' => $author->id,
    ]);
    ClientLeaveRequest::withoutEvents(fn () => ClientLeaveRequest::query()->create([
        'client_id' => $client->id,
        'starts_on' => now()->addDay(),
        'ends_on' => now()->addDays(2),
        'destination' => 'Restricted destination',
        'risks_and_mitigations' => 'Restricted leave risk',
        'emergency_contact' => 'Restricted emergency contact',
        'status' => 'draft',
        'requested_by' => $author->id,
    ]));
    ClientExcursionRequest::withoutEvents(fn () => ClientExcursionRequest::query()->create([
        'client_id' => $client->id,
        'starts_at' => now()->addDay(),
        'destination' => 'Restricted excursion',
        'risk_assessment' => 'Restricted excursion risk',
        'status' => 'draft',
        'requested_by' => $author->id,
    ]));
    ClientRoutine::withoutEvents(fn () => ClientRoutine::query()->create([
        'client_id' => $client->id,
        'time_block' => 'morning',
        'body' => 'Restricted morning routine',
        'display_order' => 10,
        'updated_by' => $author->id,
    ]));
    ClientMealLog::withoutEvents(fn () => ClientMealLog::query()->create([
        'client_id' => $client->id,
        'meal_type' => 'breakfast',
        'status' => 'eaten',
        'occurred_at' => now(),
        'notes' => 'Restricted meal note',
        'recorded_by' => $author->id,
    ]));
    FamilyNote::query()->create([
        'client_id' => $client->id,
        'created_by' => $author->id,
        'title' => 'Restricted family note',
        'description' => 'Restricted family-authored body',
        'note_type' => 'note',
        'priority' => 'high',
        'status' => 'open',
        'visibility' => 'staff',
    ]);
    ClientPhoto::query()->create([
        'client_id' => $client->id,
        'uploaded_by_user_id' => $author->id,
        'storage_path' => "client-photos/{$client->id}/restricted.jpg",
        'original_name' => 'restricted.jpg',
        'mime_type' => 'image/jpeg',
        'size_bytes' => 100,
        'caption' => 'Restricted staff-only photo',
        'visibility' => 'staff_only',
        'status' => 'approved',
    ]);
    ClientPersonalAsset::query()->create([
        'client_id' => $client->id,
        'name' => 'Restricted personal asset',
        'category' => 'electronics',
        'serial_number' => 'SECRET-SERIAL',
        'estimated_value' => 999.99,
        'condition' => 'good',
        'status' => 'active',
        'insurance_reference' => 'SECRET-INSURANCE',
        'recorded_by_user_id' => $author->id,
    ]);
    ServiceAgreement::factory()->create([
        'client_id' => $client->id,
        'title' => 'Restricted service agreement',
        'status' => 'active',
    ]);
}

it('omits every unrelated care and governance prop from a finance-only profile', function () {
    $site = Site::factory()->create(['is_active' => true]);
    $author = User::factory()->create(['role' => 'support_worker', 'approved_at' => now()]);
    $finance = User::factory()->create(['role' => 'finance', 'approved_at' => now()]);
    grantSensitiveProfilePermissions($finance, [
        'clients.viewAny',
        'client_funds.manage',
    ]);
    $worker = User::factory()->create([
        'role' => 'support_worker',
        'approved_at' => now(),
        'email' => 'restricted-worker@example.test',
    ]);
    $client = Client::factory()->create([
        'site_id' => $site->id,
        'risk_level' => 'critical',
        'safeguarding_flag' => true,
        'mobility_needs' => 'Restricted mobility need',
        'cognitive_needs' => 'Restricted cognitive need',
        'dietary_requirements' => 'Restricted diet',
        'sleep_target_hours' => 9,
        'fluid_intake_min_ml' => 1200,
        'seizure_duration_escalation_seconds' => 180,
    ]);
    scopeSensitiveProfileUserToSite($author, $site, 'support_worker');
    scopeSensitiveProfileUserToSite($finance, $site, 'finance');
    scopeSensitiveProfileUserToSite($worker, $site, 'support_worker');
    $client->supportWorkers()->attach($worker->id);
    seedSensitiveProfileSentinels($client, $author);

    $this->actingAs($finance)
        ->get("/operations/clients/{$client->id}")
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('client_finance')
            ->missing('client.risk_level')
            ->missing('client.safeguarding_flag')
            ->missing('client.mobility_needs')
            ->missing('client.cognitive_needs')
            ->missing('client.dietary_requirements')
            ->missing('client.sleep_target_hours')
            ->missing('client.fluid_intake_min_ml')
            ->missing('client.seizure_duration_escalation_seconds')
            ->missing('client.support_workers.0.email')
            ->missing('onboarding')
            ->missing('leave_excursions')
            ->missing('meal_logs')
            ->missing('client_routines')
            ->missing('actions_reviews')
            ->missing('actions_reviews_summary')
            ->missing('client_agreements')
            ->missing('pending_visit_count')
            ->missing('family_notes_open_count')
            ->missing('family_notes')
            ->missing('photos')
            ->missing('personal_assets')
            ->missing('asset_locations')
            ->missing('available_trackers')
            ->missing('location')
            ->missing('transport'));
});

it('does not let an Inertia partial request bypass the transport section gate', function () {
    $site = Site::factory()->create(['is_active' => true]);
    $finance = User::factory()->create(['role' => 'finance', 'approved_at' => now()]);
    grantSensitiveProfilePermissions($finance, [
        'clients.viewAny',
        'client_funds.manage',
    ]);
    scopeSensitiveProfileUserToSite($finance, $site, 'finance');
    $client = Client::factory()->create(['site_id' => $site->id]);
    $inertiaVersion = app(HandleInertiaRequests::class)->version(request());

    $this->actingAs($finance)
        ->get("/operations/clients/{$client->id}", [
            'X-Inertia' => 'true',
            'X-Inertia-Version' => $inertiaVersion,
            'X-Inertia-Partial-Component' => 'operations/clients/show',
            'X-Inertia-Partial-Data' => 'transport',
        ])
        ->assertOk()
        ->assertHeader('X-Inertia', 'true')
        ->assertJsonPath('component', 'operations/clients/show')
        // Inertia's partial resolver deliberately materialises a requested,
        // absent key as null. The restricted callback is never evaluated and
        // no transport record is serialized.
        ->assertJsonPath('props.transport', null);
});

it('redacts list safety summaries and create pickers without their capabilities', function () {
    $site = Site::factory()->create(['is_active' => true]);
    $finance = User::factory()->create(['role' => 'finance', 'approved_at' => now()]);
    grantSensitiveProfilePermissions($finance, [
        'clients.viewAny',
        'client_funds.manage',
    ]);
    scopeSensitiveProfileUserToSite($finance, $site, 'finance');
    $client = Client::factory()->create([
        'site_id' => $site->id,
        'risk_level' => 'critical',
        'safeguarding_flag' => true,
    ]);
    $client->medicalProfile()->create([
        'allergies' => ['penicillin'],
        'disabilities' => ['epilepsy'],
    ]);
    ClientRisk::query()->create([
        'client_id' => $client->id,
        'label' => 'Restricted list risk',
        'severity' => 'critical',
        'active' => true,
    ]);

    $this->actingAs($finance)
        ->get('/operations/clients')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('clients.0.id', $client->id)
            ->where('clients.0.safety.has_any', false)
            ->where('clients.0.safety.allergies_count', 0)
            ->where('clients.0.safety.critical_risks_count', 0)
            ->where('clients.0.safety.active_risks_count', 0)
            ->where('clients.0.safety.safeguarding', false)
            ->where('clients.0.safety.risk_level', null)
            ->where('clients.0.safety.top_allergy', null)
            ->where('clients.0.safety.top_risk', null)
            ->missing('sites')
            ->missing('serviceContexts')
            ->missing('keyWorkers')
            ->missing('geofences')
            ->missing('defaultServiceContextId'));
});

it('scopes risk-assessment picker records to the profile Site', function () {
    $accessibleSite = Site::factory()->create(['is_active' => true]);
    $otherSite = Site::factory()->create(['is_active' => true]);
    $manager = User::factory()->create(['role' => 'coordinator', 'approved_at' => now()]);
    grantSensitiveProfilePermissions($manager, [
        'clients.viewAssigned',
        'hazards.view',
        'hazards.manage',
    ]);
    scopeSensitiveProfileUserToSite($manager, $accessibleSite, 'coordinator');
    $client = Client::factory()->create(['site_id' => $accessibleSite->id]);
    $otherClient = Client::factory()->create(['site_id' => $otherSite->id]);
    $client->supportWorkers()->attach($manager->id);
    $event = HsEvent::factory()->create(['site_id' => $accessibleSite->id]);
    $otherEvent = HsEvent::factory()->create(['site_id' => $otherSite->id]);

    $this->actingAs($manager)
        ->get("/operations/clients/{$client->id}")
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('ra_pickers.sites', fn ($sites) => collect($sites)->pluck('id')->contains($accessibleSite->id)
                && ! collect($sites)->pluck('id')->contains($otherSite->id))
            ->where('ra_pickers.clients', fn ($clients) => collect($clients)->pluck('id')->contains($client->id)
                && ! collect($clients)->pluck('id')->contains($otherClient->id))
            ->where('ra_pickers.events', fn ($events) => collect($events)->pluck('id')->contains($event->id)
                && ! collect($events)->pluck('id')->contains($otherEvent->id)));
});

it('retains assigned care context without exposing unrelated governance sections', function () {
    $site = Site::factory()->create(['is_active' => true]);
    $author = User::factory()->create(['role' => 'support_worker', 'approved_at' => now()]);
    $worker = User::factory()->create([
        'role' => 'support_worker',
        'approved_at' => now(),
    ]);
    grantSensitiveProfilePermissions($worker, [
        'clients.viewAssigned',
        'progress_notes.viewAny',
        'timeline.create',
        'fleet.viewAny',
        'assets.viewAssigned',
        'assets.telemetry.view',
    ]);
    scopeSensitiveProfileUserToSite($author, $site, 'support_worker');
    scopeSensitiveProfileUserToSite($worker, $site, 'support_worker');
    $client = Client::factory()->create(['site_id' => $site->id]);
    $client->supportWorkers()->attach($worker->id);
    seedSensitiveProfileSentinels($client, $author);

    $this->actingAs($worker)
        ->get("/operations/clients/{$client->id}")
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('client_routines')
            ->has('meal_logs')
            ->has('family_notes')
            ->has('photos')
            ->has('personal_assets')
            ->has('location')
            ->missing('onboarding')
            ->missing('client_agreements')
            ->missing('client.risk_level'));
});
