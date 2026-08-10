<?php

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Models\Client;
use App\Models\ClientNote;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;

function grantClientProfileBatchOneSitePermissions(User $user, array $permissionKeys): void
{
    $role = Role::query()->firstOrCreate(
        ['name' => 'client_profile_batch_one_site_'.$user->id],
        ['label' => 'Client profile Batch 1 Site access', 'level' => 60, 'type' => 'custom'],
    );

    foreach ($permissionKeys as $key) {
        $permission = Permission::query()->firstOrCreate(
            ['key' => $key],
            ['description' => $key, 'group' => 'test', 'module' => 'Test'],
        );
        $role->permissions()->syncWithoutDetaching([$permission->id]);
    }

    $user->roles()->syncWithoutDetaching([$role->id]);
}

it('shows application-wide viewers clients at active Sites and excludes Site-less records', function () {
    $manager = User::factory()->create(['role' => 'provider_manager']);
    grantClientProfileBatchOneSitePermissions($manager, ['clients.viewAny']);
    $firstSite = Site::factory()->create(['name' => 'Visible House One']);
    $secondSite = Site::factory()->create(['name' => 'Visible House Two']);
    $firstClient = Client::factory()->create([
        'site_id' => $firstSite->id,
        'first_name' => 'First',
        'last_name' => 'Client',
    ]);
    $secondClient = Client::factory()->create([
        'site_id' => $secondSite->id,
        'first_name' => 'Second',
        'last_name' => 'Client',
    ]);
    Client::factory()->create([
        'site_id' => null,
        'first_name' => 'Unscoped',
        'last_name' => 'Client',
    ]);

    $this->actingAs($manager)
        ->get('/operations/clients')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('operations/clients/index')
            ->has('clients', 2)
            ->where(
                'clients',
                fn ($clients) => collect($clients)->pluck('id')->sort()->values()->all()
                    === collect([$firstClient->id, $secondClient->id])->sort()->values()->all(),
            ));
});

it('limits an assigned review queue to clients at the reviewers current Sites', function () {
    $reviewer = User::factory()->create(['role' => 'support_worker']);
    grantClientProfileBatchOneSitePermissions($reviewer, [
        'clients.viewAssigned',
        'progress_notes.review',
    ]);

    $accessibleSite = Site::factory()->create(['name' => 'Accessible House']);
    $inaccessibleSite = Site::factory()->create(['name' => 'Inaccessible House']);
    HrEmployeeProfile::factory()->create([
        'user_id' => $reviewer->id,
        'primary_site_id' => $accessibleSite->id,
        'secondary_site_ids' => [],
        'is_active' => true,
        'start_date' => now()->subMonth()->toDateString(),
        'end_date' => null,
    ]);
    $accessibleClient = Client::factory()->create(['site_id' => $accessibleSite->id]);
    $inaccessibleClient = Client::factory()->create(['site_id' => $inaccessibleSite->id]);
    $accessibleClient->supportWorkers()->attach($reviewer->id);
    $inaccessibleClient->supportWorkers()->attach($reviewer->id);

    $visibleNote = ClientNote::query()->create([
        'client_id' => $accessibleClient->id,
        'user_id' => $reviewer->id,
        'type' => 'daily_note',
        'subject' => 'Visible finalised daily note',
        'body' => 'This finalised daily note needs review.',
        'is_flagged' => true,
        'is_draft' => false,
        'occurred_at' => now(),
    ]);
    ClientNote::query()->create([
        'client_id' => $inaccessibleClient->id,
        'user_id' => $reviewer->id,
        'type' => 'daily_note',
        'subject' => 'Inaccessible daily note',
        'body' => 'This record is outside the reviewer Site.',
        'is_flagged' => true,
        'is_draft' => false,
        'occurred_at' => now(),
    ]);
    ClientNote::query()->create([
        'client_id' => $accessibleClient->id,
        'user_id' => $reviewer->id,
        'type' => 'daily_note',
        'subject' => 'Draft daily note',
        'body' => 'This draft is not ready for review.',
        'is_flagged' => true,
        'is_draft' => true,
        'occurred_at' => now(),
    ]);
    ClientNote::query()->create([
        'client_id' => $accessibleClient->id,
        'user_id' => $reviewer->id,
        'type' => 'communication',
        'subject' => 'Family communication',
        'body' => 'This is not a daily note.',
        'is_flagged' => true,
        'is_draft' => false,
        'occurred_at' => now(),
    ]);

    $this->actingAs($reviewer)
        ->get('/operations/review-queue')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('operations/review-queue/index')
            ->where('stats.total', 1)
            ->where('stats.clients', 1)
            ->where('stats.sites', 1)
            ->has('items.data', 1)
            ->where('items.data.0.id', $visibleNote->id)
            ->has('sites', 1)
            ->where('sites.0.id', $accessibleSite->id));

    $this->actingAs($reviewer)
        ->get('/operations/review-queue?site='.$inaccessibleSite->id)
        ->assertForbidden();
});
