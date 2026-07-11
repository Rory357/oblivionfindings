<?php

use App\Jobs\GenerateSummaryJob;
use App\Models\Client;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Summary;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Bus;

function grantSummaryRagTimelinePermissions(
    User $user,
    array $permissionKeys,
    string $roleName,
): void {
    $role = Role::query()->firstOrCreate(
        ['name' => $roleName],
        [
            'label' => str($roleName)->headline(),
            'level' => 50,
            'type' => in_array($roleName, ['client', 'next_of_kin', 'support_worker'], true)
                ? 'system'
                : 'custom',
        ],
    );

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
    $user->roles()->syncWithoutDetaching([$role->id]);
}

function summaryRagTimelineUser(
    int $organizationId,
    array $permissionKeys,
    string $roleName = 'manager',
): User {
    $user = User::factory()->create([
        'organization_id' => $organizationId,
        'role' => $roleName,
        'approved_at' => now(),
    ]);

    grantSummaryRagTimelinePermissions(
        $user,
        $permissionKeys,
        $roleName.'_'.$user->id,
    );

    return $user;
}

function summaryRagTimelinePortalUser(Client $client): User
{
    $user = User::factory()->create([
        'organization_id' => $client->organization_id,
        'role' => 'next_of_kin',
        'approved_at' => now(),
    ]);

    grantSummaryRagTimelinePermissions($user, ['clients.viewPortal'], 'next_of_kin');
    $client->portalUsers()->attach($user->id, ['relation' => 'next_of_kin']);

    return $user;
}

it('denies linked portal identities from the generic client summary reader', function () {
    $client = Client::factory()->create(['organization_id' => 1]);
    $portalUser = summaryRagTimelinePortalUser($client);

    Summary::query()->create([
        'scope_type' => 'client',
        'scope_id' => $client->id,
        'period_start' => '2026-07-01 00:00:00',
        'period_end' => '2026-07-07 00:00:00',
        'model' => 'local-deterministic',
        'summary_text' => 'Internal staff-only clinical summary.',
    ]);

    $this->actingAs($portalUser)
        ->get(route('summaries.client', [
            'client' => $client,
            'from' => '2026-07-01',
            'to' => '2026-07-07',
        ], false))
        ->assertForbidden();
});

it('denies portal identities from generic staff summary and timeline readers', function () {
    $client = Client::factory()->create(['organization_id' => 1]);
    $portalUser = summaryRagTimelinePortalUser($client);

    Summary::query()->create([
        'scope_type' => 'staff',
        'scope_id' => $portalUser->id,
        'period_start' => now()->startOfDay(),
        'period_end' => now()->addDays(7)->endOfDay(),
        'model' => 'local-deterministic',
        'summary_text' => 'Internal staff history from before a role change.',
    ]);

    $summaryStatus = $this->actingAs($portalUser)
        ->get(route('summaries.me', [], false))
        ->getStatusCode();
    $timelineStatus = $this->get(route('timeline.my', [], false))
        ->getStatusCode();

    expect([$summaryStatus, $timelineStatus])->toBe([403, 403]);
});

it('denies linked portal identities from the generic summary generator', function () {
    Bus::fake([GenerateSummaryJob::class]);

    $client = Client::factory()->create(['organization_id' => 1]);
    $portalUser = summaryRagTimelinePortalUser($client);

    $this->actingAs($portalUser)
        ->post(route('portal.summaries.generate', [], false), [
            'scope_type' => 'client',
            'scope_id' => $client->id,
            'from' => '2026-07-01',
            'to' => '2026-07-07',
        ])
        ->assertForbidden();

    Bus::assertNotDispatched(GenerateSummaryJob::class);
});

it('rejects staff summary generation for a client in another organization before dispatch', function () {
    Bus::fake([GenerateSummaryJob::class]);

    $viewer = summaryRagTimelineUser(1, [
        'clients.viewAny',
        'summaries.generate',
    ]);
    $foreignClient = Client::factory()->create(['organization_id' => 2]);

    $this->actingAs($viewer)
        ->post(route('summaries.generate', [], false), [
            'scope_type' => 'client',
            'scope_id' => $foreignClient->id,
            'from' => '2026-07-01',
            'to' => '2026-07-07',
        ])
        ->assertForbidden();

    Bus::assertNotDispatched(GenerateSummaryJob::class);
});

it('rejects a queued generic summary job attributed to a portal identity', function () {
    $client = Client::factory()->create(['organization_id' => 1]);
    $portalUser = summaryRagTimelinePortalUser($client);

    $job = new GenerateSummaryJob(
        'client',
        $client->id,
        '2026-07-01T00:00:00+12:00',
        '2026-07-07T23:59:59+12:00',
        $portalUser->id,
    );

    expect(fn () => $job->handle())
        ->toThrow(AuthorizationException::class);
    expect(Summary::query()->count())->toBe(0);
});

it('denies a staff summary reader targeting a user in another organization', function () {
    $viewer = summaryRagTimelineUser(1, ['summaries.viewAny']);
    $foreignStaff = User::factory()->create(['organization_id' => 2]);

    $this->actingAs($viewer)
        ->get(route('summaries.staff', $foreignStaff, false))
        ->assertForbidden();
});

it('denies a staff timeline reader targeting a user in another organization', function () {
    $viewer = summaryRagTimelineUser(1, ['timeline.viewAny']);
    $foreignStaff = User::factory()->create(['organization_id' => 2]);

    $this->actingAs($viewer)
        ->get(route('timeline.staff', $foreignStaff, false))
        ->assertForbidden();
});

it('preserves staff summary and timeline reads for a target in the same organization', function () {
    $viewer = summaryRagTimelineUser(1, [
        'summaries.viewAny',
        'timeline.viewAny',
    ]);
    $localStaff = User::factory()->create(['organization_id' => 1]);

    $this->actingAs($viewer)
        ->get(route('summaries.staff', $localStaff, false))
        ->assertOk();
    $this->get(route('timeline.staff', $localStaff, false))->assertOk();
});

it('requires an exact RAG capability before listing queryable clients', function () {
    $viewer = summaryRagTimelineUser(1, ['clients.viewAny']);
    Client::factory()->create(['organization_id' => 1]);

    $this->actingAs($viewer)
        ->getJson(route('rag.clients', [], false))
        ->assertForbidden();
});

it('scopes the any-client RAG picker to the callers organization', function () {
    $viewer = summaryRagTimelineUser(1, ['clients.viewAny', 'rag.ask.any']);
    $localClient = Client::factory()->create([
        'organization_id' => 1,
        'first_name' => 'Local',
        'last_name' => 'Client',
    ]);
    $foreignClient = Client::factory()->create([
        'organization_id' => 2,
        'first_name' => 'Foreign',
        'last_name' => 'Client',
    ]);

    $this->actingAs($viewer)
        ->getJson(route('rag.clients', [], false))
        ->assertOk()
        ->assertJsonCount(1, 'clients')
        ->assertJsonPath('clients.0.id', $localClient->id)
        ->assertJsonMissing(['id' => $foreignClient->id]);
});

it('limits the assigned-client RAG picker to assigned clients', function () {
    $viewer = summaryRagTimelineUser(1, ['clients.viewAssigned', 'rag.ask.assigned'], 'relief_worker');
    $assignedClient = Client::factory()->create([
        'organization_id' => 1,
        'first_name' => 'Assigned',
        'last_name' => 'Client',
    ]);
    $unassignedClient = Client::factory()->create([
        'organization_id' => 1,
        'first_name' => 'Unassigned',
        'last_name' => 'Client',
    ]);
    $foreignClient = Client::factory()->create([
        'organization_id' => 2,
        'first_name' => 'Foreign',
        'last_name' => 'Client',
    ]);
    $assignedClient->supportWorkers()->attach($viewer->id);

    $this->actingAs($viewer)
        ->getJson(route('rag.clients', [], false))
        ->assertOk()
        ->assertJsonCount(1, 'clients')
        ->assertJsonPath('clients.0.id', $assignedClient->id)
        ->assertJsonMissing(['id' => $unassignedClient->id])
        ->assertJsonMissing(['id' => $foreignClient->id]);
});
