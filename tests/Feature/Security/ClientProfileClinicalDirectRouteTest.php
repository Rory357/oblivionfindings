<?php

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

function makeClinicalDirectRouteActor(int $organizationId, array $permissionKeys): User
{
    $user = User::factory()->create([
        'organization_id' => $organizationId,
        'role' => 'staff',
        'approved_at' => now(),
    ]);
    grantClinicalDirectRoutePermissions($user, $permissionKeys);

    return $user;
}

it('cannot bootstrap medication access by opening break glass for another organization', function () {
    $actor = makeClinicalDirectRouteActor(1, [
        'medications.breakglass',
        'medications.view',
    ]);
    $foreignClient = Client::factory()->create(['organization_id' => 2]);

    $this->mock(NotificationService::class)
        ->shouldReceive('notifyCrud')
        ->zeroOrMoreTimes();

    $this->actingAs($actor)
        ->post(route('operations.clients.break_glass.store', $foreignClient, false), [
            'reason' => 'Emergency cover',
            'acknowledged_min_necessary' => true,
        ])
        ->assertForbidden();

    expect(ClientBreakGlassAccess::query()
        ->where('client_id', $foreignClient->id)
        ->where('user_id', $actor->id)
        ->exists())->toBeFalse()
        ->and(Gate::forUser($actor)->allows('viewMedications', $foreignClient))->toBeFalse();
});

it('keeps direct document and routine reads aligned with profile section access', function () {
    Storage::fake('local');

    $client = Client::factory()->create(['organization_id' => 1]);
    $restrictedViewer = makeClinicalDirectRouteActor(1, ['clients.viewAny']);
    $editor = makeClinicalDirectRouteActor(1, ['clients.viewAny', 'clients.update']);

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
        'organization_id' => 1,
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

it('rejects client notes linked to another clients shift', function (int $shiftOrganizationId) {
    $actor = makeClinicalDirectRouteActor(1, [
        'clients.viewAny',
        'timeline.create',
    ]);
    $client = Client::factory()->create(['organization_id' => 1]);
    $otherClient = Client::factory()->create(['organization_id' => $shiftOrganizationId]);
    $shift = Shift::factory()->create([
        'organization_id' => $shiftOrganizationId,
        'client_id' => $otherClient->id,
        'user_id' => $actor->id,
        'created_by' => $actor->id,
    ]);

    $this->actingAs($actor)
        ->postJson(route('operations.clients.notes.store', $client, false), [
            'type' => 'shift_note',
            'body' => 'Must not cross the client boundary',
            'shift_id' => $shift->id,
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('shift_id');

    expect(ClientNote::query()
        ->where('client_id', $client->id)
        ->where('body', 'Must not cross the client boundary')
        ->exists())->toBeFalse();
})->with([
    'same organization, different client' => 1,
    'different organization and client' => 2,
]);

it('rejects medication administrations linked to another clients shift before side effects', function (int $shiftOrganizationId) {
    $actor = makeClinicalDirectRouteActor(1, [
        'clients.viewAny',
        'medications.view',
        'medications.administer.record',
    ]);
    $serviceContext = ServiceContext::factory()->create();
    $client = Client::factory()->create([
        'organization_id' => 1,
        'service_context_id' => $serviceContext->id,
    ]);
    $otherClient = Client::factory()->create(['organization_id' => $shiftOrganizationId]);
    $shift = Shift::factory()->create([
        'organization_id' => $shiftOrganizationId,
        'client_id' => $otherClient->id,
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
    'same organization, different client' => 1,
    'different organization and client' => 2,
]);
