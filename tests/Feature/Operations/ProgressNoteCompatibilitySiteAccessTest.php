<?php

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Models\Client;
use App\Models\ClientNote;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Shift;
use App\Models\Site;
use App\Models\User;

it('rejects legacy progress-note writes for a Client outside the worker Site assignment', function () {
    $accessibleSite = Site::factory()->create();
    $outsideSite = Site::factory()->create();
    $worker = progressNoteSiteWorker($accessibleSite, ['clients.viewAssigned', 'progress_notes.create']);
    $outsideClient = Client::factory()->create(['site_id' => $outsideSite->id]);
    $outsideClient->supportWorkers()->attach($worker->id);

    $this->actingAs($worker)
        ->post(route('operations.progress_notes.store'), [
            'client_id' => $outsideClient->id,
            'content' => 'Must not be recorded.',
            'note_type' => 'activity',
        ])
        ->assertForbidden();

    expect(ClientNote::query()->where('client_id', $outsideClient->id)->exists())->toBeFalse();
});

it('rejects a Shift from another Client and protects private canonical notes on legacy routes', function () {
    $site = Site::factory()->create();
    $author = progressNoteSiteWorker($site, [
        'clients.viewAssigned',
        'progress_notes.create',
        'progress_notes.update',
    ]);
    $colleague = progressNoteSiteWorker($site, ['clients.viewAssigned', 'progress_notes.update']);
    $client = Client::factory()->create(['site_id' => $site->id]);
    $otherClient = Client::factory()->create(['site_id' => $site->id]);
    $client->supportWorkers()->attach([$author->id, $colleague->id]);
    $otherClient->supportWorkers()->attach($author->id);
    $mismatchedShift = Shift::factory()->create([
        'site_id' => $site->id,
        'client_id' => $otherClient->id,
        'user_id' => $author->id,
    ]);

    $this->actingAs($author)
        ->post(route('operations.progress_notes.store'), [
            'client_id' => $client->id,
            'shift_id' => $mismatchedShift->id,
            'content' => 'Must not attach to another Client Shift.',
            'note_type' => 'activity',
        ])
        ->assertNotFound();

    $privateNote = ClientNote::query()->create([
        'client_id' => $client->id,
        'user_id' => $author->id,
        'type' => 'progress_note',
        'body' => 'Author-only working note.',
        'visibility' => 'internal',
        'is_private' => true,
        'is_draft' => false,
    ]);
    $this->actingAs($colleague)
        ->put(route('operations.progress_notes.update', $privateNote), [
            'content' => 'Must not change.',
        ])
        ->assertNotFound();

    expect($privateNote->fresh()->body)->toBe('Author-only working note.');
});

function progressNoteSiteWorker(Site $site, array $permissionKeys): User
{
    $worker = User::factory()->create([
        'role' => 'support_worker',
        'approved_at' => now(),
    ]);
    $role = Role::firstOrCreate(
        ['name' => 'support_worker'],
        ['label' => 'Support Worker', 'level' => 10, 'type' => 'system'],
    );
    $permissions = collect($permissionKeys)->map(fn (string $key) => Permission::firstOrCreate(
        ['key' => $key],
        ['description' => $key, 'group' => 'Client notes', 'module' => 'operations'],
    ));
    $role->permissions()->syncWithoutDetaching($permissions->pluck('id'));
    $worker->roles()->attach($role);
    HrEmployeeProfile::factory()->create([
        'user_id' => $worker->id,
        'primary_site_id' => $site->id,
        'secondary_site_ids' => [],
        'start_date' => today()->subYear(),
        'end_date' => null,
        'is_active' => true,
    ]);

    return $worker;
}
