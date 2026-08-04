<?php

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Models\Client;
use App\Models\ClientNote;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use App\Services\Client\ActionsAggregator;
use Inertia\Testing\AssertableInertia as Assert;

function grantClientProfileBatchOnePermissions(User $user, array $permissionKeys): void
{
    $role = Role::query()->firstOrCreate(
        ['name' => 'client_profile_batch_one_'.$user->id],
        ['label' => 'Client Profile Batch One', 'level' => 50, 'type' => 'custom'],
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

function makeBatchOneNote(Client $client, User $author, array $attributes = []): ClientNote
{
    return ClientNote::query()->create([
        'client_id' => $client->id,
        'user_id' => $author->id,
        'type' => 'daily_note',
        'category' => 'other',
        'subject' => 'Daily record',
        'body' => 'A complete daily record.',
        'occurred_at' => now(),
        'visibility' => 'internal',
        'appears_on_timeline' => true,
        'is_draft' => false,
        ...$attributes,
    ]);
}

function makeBatchOneClient(): Client
{
    return Client::factory()->create([
        'site_id' => Site::factory()->create()->id,
    ]);
}

function assignBatchOneWorkerToSite(User $worker, Site $site): void
{
    HrEmployeeProfile::factory()->create([
        'user_id' => $worker->id,
        'primary_site_id' => $site->id,
        'secondary_site_ids' => [],
        'is_active' => true,
        'start_date' => now()->subMonth()->toDateString(),
        'end_date' => null,
    ]);
}

it('shows only the current authors drafts in the profile and daily note endpoint', function () {
    $viewer = User::factory()->create();
    $colleague = User::factory()->create();
    $client = makeBatchOneClient();
    grantClientProfileBatchOnePermissions($viewer, [
        'clients.viewAny',
        'progress_notes.viewAny',
        'progress_notes.create',
        'progress_notes.update',
        'progress_notes.review',
    ]);

    $ownDraft = makeBatchOneNote($client, $viewer, [
        'subject' => 'Own private draft',
        'body' => 'Still being written by this viewer.',
        'is_draft' => true,
    ]);
    $colleagueDraft = makeBatchOneNote($client, $colleague, [
        'subject' => 'Colleague private draft',
        'body' => 'Must remain private to the colleague.',
        'is_draft' => true,
        'is_flagged' => true,
        'follow_up_action' => 'Draft-only follow-up',
    ]);
    $submitted = makeBatchOneNote($client, $colleague, [
        'subject' => 'Submitted colleague note',
        'body' => 'Visible after submission.',
    ]);

    $this->actingAs($viewer)
        ->get("/operations/clients/{$client->id}")
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('client_daily_notes', fn ($notes) => collect($notes)->pluck('id')->sort()->values()->all() === collect([$ownDraft->id, $submitted->id])->sort()->values()->all())
            ->where('daily_notes_summary.drafts', 1)
            ->where('daily_notes_summary.flagged_open', 0));

    $response = $this->actingAs($viewer)
        ->getJson("/operations/clients/{$client->id}/daily-notes")
        ->assertOk()
        ->assertJsonCount(2, 'data');

    expect(collect($response->json('data'))->pluck('id'))
        ->toContain($ownDraft->id, $submitted->id)
        ->not->toContain($colleagueDraft->id);
});

it('keeps submitted private notes with their author and senior reviewers', function () {
    $site = Site::factory()->create();
    $client = Client::factory()->create(['site_id' => $site->id]);
    $author = User::factory()->create(['role' => 'support_worker']);
    $colleague = User::factory()->create(['role' => 'support_worker']);
    $reviewer = User::factory()->create(['role' => 'provider_manager']);

    grantClientProfileBatchOnePermissions($author, [
        'clients.viewAssigned',
        'progress_notes.viewAny',
        'progress_notes.create',
    ]);
    grantClientProfileBatchOnePermissions($colleague, [
        'clients.viewAssigned',
        'progress_notes.viewAny',
    ]);
    grantClientProfileBatchOnePermissions($reviewer, [
        'clients.viewAny',
        'progress_notes.viewAny',
        'progress_notes.review',
    ]);
    assignBatchOneWorkerToSite($author, $site);
    assignBatchOneWorkerToSite($colleague, $site);
    $client->supportWorkers()->attach([$author->id, $colleague->id]);

    $private = makeBatchOneNote($client, $author, [
        'subject' => 'Private submitted note',
        'is_private' => true,
    ]);
    $shared = makeBatchOneNote($client, $author, [
        'subject' => 'Shared submitted note',
        'is_private' => false,
    ]);

    foreach ([[$author, [$private->id, $shared->id]], [$reviewer, [$private->id, $shared->id]]] as [$viewer, $expectedIds]) {
        $response = $this->actingAs($viewer)
            ->getJson("/operations/clients/{$client->id}/daily-notes")
            ->assertOk();

        expect(collect($response->json('data'))->pluck('id')->sort()->values()->all())
            ->toBe(collect($expectedIds)->sort()->values()->all());
    }

    $response = $this->actingAs($colleague)
        ->getJson("/operations/clients/{$client->id}/daily-notes")
        ->assertOk();

    expect(collect($response->json('data'))->pluck('id')->all())
        ->toBe([$shared->id])
        ->and(ClientNote::query()->forUser(null)->count())->toBe(0)
        ->and($private->getRawOriginal('organization_id'))->toBe(1)
        ->and($private->toArray())->not->toHaveKey('organization_id');
});

it('lets an author resume and submit their own draft without granting submitted-note update authority', function () {
    $author = User::factory()->create();
    $client = makeBatchOneClient();
    grantClientProfileBatchOnePermissions($author, [
        'clients.viewAny',
        'progress_notes.viewAny',
        'progress_notes.create',
    ]);
    $draft = makeBatchOneNote($client, $author, [
        'subject' => 'Working draft',
        'body' => 'First draft text.',
        'is_draft' => true,
    ]);

    $this->actingAs($author)
        ->getJson("/operations/clients/{$client->id}/daily-notes")
        ->assertOk()
        ->assertJsonPath('data.0.id', $draft->id)
        ->assertJsonPath('data.0.can.update', true)
        ->assertJsonPath('data.0.can.delete', true)
        ->assertJsonPath('data.0.can.flag', false)
        ->assertJsonPath('data.0.can.review', false);

    $this->actingAs($author)
        ->put("/operations/clients/{$client->id}/daily-notes/{$draft->id}", [
            'body' => 'Completed and ready for the record.',
            'visibility' => 'portal',
            'is_draft' => false,
        ])
        ->assertRedirect();

    expect($draft->fresh())
        ->is_draft->toBeFalse()
        ->body->toBe('Completed and ready for the record.')
        ->visibility->toBe('portal');

    $this->actingAs($author)
        ->put("/operations/clients/{$client->id}/daily-notes/{$draft->id}", [
            'body' => 'A later correction without update authority.',
        ])
        ->assertForbidden();

    expect($draft->fresh()->body)->toBe('Completed and ready for the record.');
});

it('keeps a colleagues draft private from update delete flag and review operations', function () {
    $manager = User::factory()->create();
    $author = User::factory()->create();
    $client = makeBatchOneClient();
    grantClientProfileBatchOnePermissions($manager, [
        'clients.viewAny',
        'progress_notes.viewAny',
        'progress_notes.update',
        'progress_notes.delete',
        'progress_notes.review',
    ]);
    $draft = makeBatchOneNote($client, $author, [
        'body' => 'Private author workspace.',
        'is_draft' => true,
    ]);

    $this->actingAs($manager)
        ->put("/operations/clients/{$client->id}/daily-notes/{$draft->id}", [
            'body' => 'Manager overwrite.',
        ])
        ->assertForbidden();
    $this->actingAs($manager)
        ->delete("/operations/clients/{$client->id}/daily-notes/{$draft->id}")
        ->assertForbidden();
    $this->actingAs($manager)
        ->post("/operations/clients/{$client->id}/daily-notes/{$draft->id}/flag", [
            'is_flagged' => true,
        ])
        ->assertForbidden();
    $this->actingAs($manager)
        ->post("/operations/clients/{$client->id}/daily-notes/{$draft->id}/review")
        ->assertForbidden();

    expect($draft->fresh())
        ->body->toBe('Private author workspace.')
        ->is_flagged->toBeFalse()
        ->reviewed_at->toBeNull();
});

it('does not allow submitted notes to move backwards into draft state', function () {
    $manager = User::factory()->create();
    $client = makeBatchOneClient();
    grantClientProfileBatchOnePermissions($manager, [
        'clients.viewAny',
        'progress_notes.viewAny',
        'progress_notes.update',
    ]);
    $submitted = makeBatchOneNote($client, $manager);

    $this->actingAs($manager)
        ->from("/operations/clients/{$client->id}?tab=progress_notes")
        ->put("/operations/clients/{$client->id}/daily-notes/{$submitted->id}", [
            'body' => 'Submitted records cannot become drafts.',
            'is_draft' => true,
        ])
        ->assertSessionHasErrors('is_draft');

    expect($submitted->fresh()->is_draft)->toBeFalse();
});

it('excludes drafts from review and follow-up projections', function () {
    $reviewer = User::factory()->create();
    $client = makeBatchOneClient();
    grantClientProfileBatchOnePermissions($reviewer, [
        'clients.viewAny',
        'progress_notes.viewAny',
        'progress_notes.review',
    ]);
    $draft = makeBatchOneNote($client, $reviewer, [
        'subject' => 'Draft action source',
        'body' => 'Not part of the formal record yet.',
        'is_draft' => true,
        'is_flagged' => true,
        'follow_up_action' => 'Do not surface this draft action',
    ]);
    $submitted = makeBatchOneNote($client, $reviewer, [
        'subject' => 'Submitted action source',
        'body' => 'This is part of the formal record.',
        'is_flagged' => true,
        'follow_up_action' => 'Surface this submitted action',
    ]);

    $items = app(ActionsAggregator::class)->forClient($client, $reviewer);

    expect(collect($items)->pluck('source_id'))
        ->toContain($submitted->id)
        ->not->toContain($draft->id);

    $this->actingAs($reviewer)
        ->getJson("/operations/clients/{$client->id}/daily-notes/review-queue")
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $submitted->id)
        ->assertJsonPath('data.0.can.flag', true)
        ->assertJsonPath('data.0.can.review', true);
});

it('does not mutate unrelated client-note types through daily-note routes', function () {
    $manager = User::factory()->create();
    $client = makeBatchOneClient();
    grantClientProfileBatchOnePermissions($manager, [
        'clients.viewAny',
        'progress_notes.viewAny',
        'progress_notes.update',
        'progress_notes.delete',
        'progress_notes.review',
    ]);
    $unrelated = makeBatchOneNote($client, $manager, [
        'type' => 'clinical_summary',
        'body' => 'Owned by another client-note workflow.',
    ]);

    $this->actingAs($manager)
        ->put("/operations/clients/{$client->id}/daily-notes/{$unrelated->id}", [
            'body' => 'Cross-workflow mutation.',
        ])
        ->assertNotFound();
    $this->actingAs($manager)
        ->delete("/operations/clients/{$client->id}/daily-notes/{$unrelated->id}")
        ->assertNotFound();
    $this->actingAs($manager)
        ->post("/operations/clients/{$client->id}/daily-notes/{$unrelated->id}/review")
        ->assertNotFound();

    expect($unrelated->fresh()->body)->toBe('Owned by another client-note workflow.');
});
