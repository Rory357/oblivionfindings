<?php

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Models\Client;
use App\Models\ClientAssessment;
use App\Models\ClientDocument;
use App\Models\ClientPathPlan;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use App\Services\Client\ActionsAggregator;

function grantClientProfileAggregatePermissions(User $user, array $permissions): void
{
    $role = Role::query()->firstOrCreate(
        ['name' => 'client_profile_aggregate_'.$user->id],
        ['label' => 'Client profile aggregate', 'level' => 20, 'type' => 'custom'],
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

function clientProfileAggregateUserAtSite(Site $site, array $permissions = []): User
{
    $user = User::factory()->create(['approved_at' => now()]);
    grantClientProfileAggregatePermissions($user, $permissions);
    HrEmployeeProfile::factory()->create([
        'user_id' => $user->id,
        'primary_site_id' => $site->id,
        'secondary_site_ids' => [],
        'start_date' => today()->subYear(),
        'end_date' => null,
        'is_active' => true,
    ]);

    return $user;
}

function seedRestrictedClientProfileAggregateRecords(Client $client, User $author): void
{
    ClientDocument::withoutEvents(fn () => ClientDocument::query()->create([
        'client_id' => $client->id,
        'uploaded_by_user_id' => $author->id,
        'title' => 'Restricted document title',
        'category' => 'clinical',
        'version' => 1,
        'expiry_date' => now()->addDay(),
        'storage_disk' => 'local',
        'storage_path' => 'restricted/document.pdf',
        'original_name' => 'document.pdf',
        'mime_type' => 'application/pdf',
        'size_bytes' => 100,
    ]));
    ClientAssessment::withoutEvents(fn () => ClientAssessment::query()->create([
        'client_id' => $client->id,
        'created_by_user_id' => $author->id,
        'type' => 'restricted_assessment',
        'score' => 7,
        'notes' => 'Restricted assessment note',
        'assessed_at' => now(),
        'next_review_at' => now()->addDay(),
    ]));
    ClientPathPlan::withoutEvents(fn () => ClientPathPlan::query()->create([
        'client_id' => $client->id,
        'dream' => 'Restricted PATH goal',
        'next_review_at' => now()->addDay(),
        'updated_by' => $author->id,
    ]));
}

it('does not leak restricted document assessment or PATH items to a finance-only viewer', function () {
    $site = Site::factory()->create();
    $author = clientProfileAggregateUserAtSite($site);
    $viewer = clientProfileAggregateUserAtSite($site, [
        'clients.viewAny',
        'client_funds.manage',
    ]);
    $client = Client::factory()->create(['site_id' => $site->id]);
    seedRestrictedClientProfileAggregateRecords($client, $author);

    $items = app(ActionsAggregator::class)->forClient($client, $viewer);

    expect($items)->toBe([]);
});

it('exposes aggregate contributors only with their owning section capability', function () {
    $site = Site::factory()->create();
    $author = clientProfileAggregateUserAtSite($site);
    $client = Client::factory()->create(['site_id' => $site->id]);
    seedRestrictedClientProfileAggregateRecords($client, $author);

    $documentViewer = clientProfileAggregateUserAtSite($site, [
        'clients.viewAny',
        'clients.update',
    ]);
    $assessmentViewer = clientProfileAggregateUserAtSite($site, [
        'clients.viewAny',
        'clinical.assessments.viewAny',
    ]);
    $pathViewer = clientProfileAggregateUserAtSite($site, [
        'clients.viewAny',
        'care_plans.viewAny',
    ]);

    $documentItems = app(ActionsAggregator::class)->forClient($client, $documentViewer);
    $assessmentItems = app(ActionsAggregator::class)->forClient($client, $assessmentViewer);
    $pathItems = app(ActionsAggregator::class)->forClient($client, $pathViewer);

    expect(collect($documentItems)->pluck('type')->all())->toBe(['document_expiring'])
        ->and(collect($assessmentItems)->pluck('type')->all())->toBe(['assessment_due'])
        ->and(collect($pathItems)->pluck('type')->all())->toBe(['path_plan_review_due']);
});

it('reports overflow metadata from the bounded client actions endpoint', function () {
    $site = Site::factory()->create();
    $viewer = clientProfileAggregateUserAtSite($site, [
        'clients.viewAny',
        'clients.update',
    ]);
    $client = Client::factory()->create(['site_id' => $site->id]);

    ClientDocument::withoutEvents(function () use ($client, $viewer): void {
        foreach (range(1, 21) as $index) {
            ClientDocument::query()->create([
                'client_id' => $client->id,
                'uploaded_by_user_id' => $viewer->id,
                'title' => "Expiring document {$index}",
                'category' => 'clinical',
                'version' => 1,
                'expiry_date' => now()->addDays($index),
                'storage_disk' => 'local',
                'storage_path' => "restricted/document-{$index}.pdf",
                'original_name' => "document-{$index}.pdf",
                'mime_type' => 'application/pdf',
                'size_bytes' => 100,
            ]);
        }
    });

    $this->actingAs($viewer)
        ->getJson("/operations/clients/{$client->id}/actions")
        ->assertOk()
        ->assertJsonCount(20, 'data')
        ->assertJsonPath('meta.loaded', 20)
        ->assertJsonPath('meta.has_more', true);
});
