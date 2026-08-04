<?php

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Models\Client;
use App\Models\ClientBreakGlassAccess;
use App\Models\ClientDocument;
use App\Models\ClientMedication;
use App\Models\ClientNote;
use App\Models\ClientRoutine;
use App\Models\Permission;
use App\Models\Role;
use App\Models\ServiceContext;
use App\Models\Shift;
use App\Models\Site;
use App\Models\User;
use App\Services\NotificationService;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;

function grantClinicalDirectRoutePermissions(User $user, array $permissionKeys): void
{
    $role = Role::query()->create([
        'name' => 'clinical_direct_route_'.$user->id,
        'label' => 'Clinical direct-route test',
        'level' => 50,
        'type' => 'custom',
    ]);

    foreach ($permissionKeys as $permissionKey) {
        Permission::query()->firstOrCreate(
            ['key' => $permissionKey],
            [
                'description' => $permissionKey,
                'group' => 'test',
                'module' => 'Test',
            ],
        );
    }

    $role->permissions()->sync(
        Permission::query()->whereIn('key', $permissionKeys)->pluck('id')->all(),
    );
    $user->roles()->sync([$role->id]);
}

/**
 * @param  array<int, Site>  $secondarySites
 */
function makeClinicalDirectRouteActor(Site $primarySite, array $permissionKeys, array $secondarySites = []): User
{
    $user = User::factory()->create([
        'role' => 'staff',
        'approved_at' => now(),
    ]);
    grantClinicalDirectRoutePermissions($user, $permissionKeys);
    HrEmployeeProfile::factory()->create([
        'user_id' => $user->id,
        'primary_site_id' => $primarySite->id,
        'secondary_site_ids' => array_map(
            static fn (Site $site): int => (int) $site->id,
            $secondarySites,
        ),
        'is_active' => true,
        'start_date' => now()->subMonth()->toDateString(),
        'end_date' => null,
    ]);

    return $user;
}

it('cannot bootstrap medication access for a Client outside the actors Site access', function () {
    $actorSite = Site::factory()->create();
    $outsideSite = Site::factory()->create();
    $actor = makeClinicalDirectRouteActor($actorSite, [
        'medications.breakglass',
        'medications.view',
    ]);
    $outsideSiteClient = Client::factory()->create(['site_id' => $outsideSite->id]);

    $this->mock(NotificationService::class)
        ->shouldReceive('notifyCrud')
        ->zeroOrMoreTimes();

    $this->actingAs($actor)
        ->post(route('operations.clients.break_glass.store', $outsideSiteClient, false), [
            'reason' => 'Emergency cover',
            'acknowledged_min_necessary' => true,
        ])
        ->assertForbidden();

    expect(ClientBreakGlassAccess::query()
        ->where('client_id', $outsideSiteClient->id)
        ->where('user_id', $actor->id)
        ->exists())->toBeFalse()
        ->and(Gate::forUser($actor)->allows('viewMedications', $outsideSiteClient))->toBeFalse();
});

it('keeps direct document and routine reads aligned with profile section access', function () {
    Storage::fake('local');

    $site = Site::factory()->create();
    $client = Client::factory()->create(['site_id' => $site->id]);
    $restrictedViewer = makeClinicalDirectRouteActor($site, ['clients.viewAny']);
    $editor = makeClinicalDirectRouteActor($site, ['clients.viewAny', 'clients.update']);

    Storage::disk('local')->put('client-documents/private.txt', 'private care document');
    $document = ClientDocument::query()->create([
        'client_id' => $client->id,
        'uploaded_by_user_id' => $editor->id,
        'title' => 'Private care document',
        'storage_disk' => 'local',
        'storage_path' => 'client-documents/private.txt',
        'original_name' => 'private.txt',
        'mime_type' => 'text/plain',
        'size_bytes' => 21,
        'portal_visible' => false,
    ]);
    ClientRoutine::query()->create([
        'client_id' => $client->id,
        'time_block' => 'morning',
        'body' => 'Sensitive morning support details',
        'display_order' => 10,
        'updated_by' => $editor->id,
    ]);

    $this->actingAs($restrictedViewer)
        ->get(route('operations.clients.documents.index', $client, false))
        ->assertForbidden();
    $this->get(route('clients.documents.index', $client, false))->assertForbidden();
    $this->get(route('operations.clients.documents.download', [
        'client' => $client,
        'document' => $document,
    ], false))->assertForbidden();
    $this->get(route('clients.documents.download', [
        'client' => $client,
        'document' => $document,
    ], false))->assertForbidden();
    $this->get(route('operations.clients.routines.index', $client, false))->assertForbidden();

    $this->actingAs($editor)
        ->get(route('operations.clients.documents.index', $client, false))
        ->assertOk();
    $this->get(route('operations.clients.documents.download', [
        'client' => $client,
        'document' => $document,
    ], false))->assertOk();
    $this->get(route('operations.clients.routines.index', $client, false))
        ->assertOk()
        ->assertJsonPath('0.body', 'Sensitive morning support details');
});

it('rejects a Client note linked to another Clients Shift', function (bool $shiftUsesAnotherAccessibleSite) {
    $clientSite = Site::factory()->create();
    $shiftSite = $shiftUsesAnotherAccessibleSite ? Site::factory()->create() : $clientSite;
    $actor = makeClinicalDirectRouteActor($clientSite, [
        'clients.viewAny',
        'timeline.create',
    ], $shiftUsesAnotherAccessibleSite ? [$shiftSite] : []);
    $client = Client::factory()->create(['site_id' => $clientSite->id]);
    $otherClient = Client::factory()->create(['site_id' => $shiftSite->id]);
    $shift = Shift::factory()->create([
        'client_id' => $otherClient->id,
        'site_id' => $shiftSite->id,
        'service_context_id' => $otherClient->service_context_id,
        'user_id' => $actor->id,
        'created_by' => $actor->id,
    ]);

    $this->actingAs($actor)
        ->postJson(route('operations.clients.notes.store', $client, false), [
            'type' => 'shift_note',
            'body' => 'Must not attach to a different Client Shift',
            'shift_id' => $shift->id,
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('shift_id');

    expect(ClientNote::query()
        ->where('client_id', $client->id)
        ->where('body', 'Must not attach to a different Client Shift')
        ->exists())->toBeFalse();
})->with([
    'same Site, different Client' => false,
    'another accessible Site, different Client' => true,
]);

it('rejects medication administrations linked to another Clients Shift before side effects', function (bool $shiftUsesAnotherAccessibleSite) {
    $clientSite = Site::factory()->create();
    $shiftSite = $shiftUsesAnotherAccessibleSite ? Site::factory()->create() : $clientSite;
    $actor = makeClinicalDirectRouteActor($clientSite, [
        'clients.viewAny',
        'medications.view',
        'medications.administer.record',
    ], $shiftUsesAnotherAccessibleSite ? [$shiftSite] : []);
    $serviceContext = ServiceContext::factory()->create();
    $client = Client::factory()->create([
        'site_id' => $clientSite->id,
        'service_context_id' => $serviceContext->id,
    ]);
    $otherClient = Client::factory()->create(['site_id' => $shiftSite->id]);
    $shift = Shift::factory()->create([
        'client_id' => $otherClient->id,
        'site_id' => $shiftSite->id,
        'service_context_id' => $otherClient->service_context_id,
        'user_id' => $actor->id,
        'created_by' => $actor->id,
    ]);
    $medication = ClientMedication::query()->create([
        'client_id' => $client->id,
        'created_by' => $actor->id,
        'name' => 'Paracetamol',
        'dosage' => '500mg',
        'is_prn' => false,
        'controlled_drug' => false,
        'active' => true,
        'state' => 'active',
        'approval_status' => 'verified',
    ]);

    $this->mock(NotificationService::class)
        ->shouldReceive('notifyCrud')
        ->zeroOrMoreTimes();

    $this->actingAs($actor)
        ->postJson(route('operations.clients.medical.medications.administrations.store', [
            'client' => $client,
            'medication' => $medication,
        ], false), [
            'status' => 'given',
            'dose_given' => '500mg',
            'shift_id' => $shift->id,
            'pulse_bpm' => 72,
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('shift_id');

    $this->assertDatabaseMissing('client_medication_administrations', [
        'client_id' => $client->id,
        'shift_id' => $shift->id,
    ]);
    $this->assertDatabaseMissing('clinical_observations', [
        'client_id' => $client->id,
        'shift_id' => $shift->id,
    ]);
    $this->assertDatabaseMissing('timeline_events', [
        'client_id' => $client->id,
        'shift_id' => $shift->id,
        'type' => 'medication_given',
    ]);
})->with([
    'same Site, different Client' => false,
    'another accessible Site, different Client' => true,
]);
