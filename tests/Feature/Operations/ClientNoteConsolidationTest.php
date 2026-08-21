<?php

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Models\CarePlan;
use App\Models\CarePlanGoal;
use App\Models\Client;
use App\Models\ClientNote;
use App\Models\ConsentType;
use App\Models\FamilyPortalSetting;
use App\Models\NextOfKin;
use App\Models\Permission;
use App\Models\ProgressNote;
use App\Models\Role;
use App\Models\Shift;
use App\Models\Site;
use App\Models\TimelineEvent;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\AuthoritativeConsentFixture;

function grantClientNoteConsolidationPermissions(User $user, array $permissionKeys): void
{
    $role = Role::query()->firstOrCreate(
        ['name' => 'client_note_consolidation_'.$user->id],
        ['label' => 'Client Note Consolidation', 'level' => 50, 'type' => 'custom'],
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

function clientNoteConsolidationUserAtSite(
    Site $site,
    array $permissionKeys,
    string $role = 'manager',
): User {
    $user = User::factory()->create([
        'approved_at' => now(),
        'role' => $role,
    ]);
    grantClientNoteConsolidationPermissions($user, $permissionKeys);
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

/**
 * @return array{0: User, 1: Client, 2: CarePlan, 3: CarePlanGoal}
 */
function makeClientNoteConsolidationGoal(): array
{
    $site = Site::factory()->create();
    $user = clientNoteConsolidationUserAtSite($site, ['care_plans.update']);
    $client = Client::factory()->create(['site_id' => $site->id]);
    $plan = CarePlan::query()->create([
        'client_id' => $client->id,
        'title' => 'Active support plan',
        'status' => 'active',
        'plan_type' => 'support',
        'created_by' => $user->id,
    ]);
    $goal = CarePlanGoal::query()->create([
        'care_plan_id' => $plan->id,
        'client_id' => $client->id,
        'title' => 'Build community confidence',
        'category' => 'Community',
        'priority' => 'medium',
        'created_by' => $user->id,
    ]);

    return [$user, $client, $plan, $goal];
}

it('maps every legacy field timestamp and soft delete to the canonical note', function () {
    [$author, $client, , $goal] = makeClientNoteConsolidationGoal();
    $shift = Shift::factory()->create([
        'client_id' => $client->id,
        'site_id' => $client->site_id,
        'user_id' => $author->id,
        'created_by' => $author->id,
    ]);
    $createdAt = CarbonImmutable::parse('2025-02-03 04:05:06', 'UTC');
    $updatedAt = CarbonImmutable::parse('2025-02-04 05:06:07', 'UTC');
    $deletedAt = CarbonImmutable::parse('2025-02-05 06:07:08', 'UTC');

    $legacy = ProgressNote::query()->create([
        'client_id' => $client->id,
        'shift_id' => $shift->id,
        'care_plan_goal_id' => $goal->id,
        'author_id' => $author->id,
        'note_type' => 'goal_hurdle',
        'content' => 'Transport disruption prevented the planned outing.',
        'mood_rating' => 4,
        'emotions' => ['frustrated', 'tired'],
        'is_flagged' => true,
        'flagged_reason' => 'Requires coordinator follow-up',
        'ai_summary' => 'Outing blocked by transport.',
        'visibility' => 'private',
    ]);
    $legacy->delete();
    DB::table('progress_notes')->where('id', $legacy->id)->update([
        'created_at' => $createdAt,
        'updated_at' => $updatedAt,
        'deleted_at' => $deletedAt,
    ]);

    $this->artisan('oblivion:migrate-progress-notes-to-client-notes')
        ->assertSuccessful();

    $canonical = ClientNote::withTrashed()
        ->where('legacy_progress_note_id', $legacy->id)
        ->firstOrFail();

    expect($canonical->client_id)->toBe($client->id)
        ->and($canonical->shift_id)->toBe($shift->id)
        ->and($canonical->care_plan_goal_id)->toBe($goal->id)
        ->and($canonical->user_id)->toBe($author->id)
        ->and($canonical->legacy_progress_note_id)->toBe($legacy->id)
        ->and($canonical->type)->toBe('progress_note')
        ->and($canonical->category)->toBe('goal_hurdle')
        ->and($canonical->body)->toBe('Transport disruption prevented the planned outing.')
        ->and($canonical->mood_rating)->toBe(4)
        ->and($canonical->behaviour_tags)->toBe(['frustrated', 'tired'])
        ->and($canonical->is_flagged)->toBeTrue()
        ->and($canonical->flagged_reason)->toBe('Requires coordinator follow-up')
        ->and($canonical->ai_summary)->toBe('Outing blocked by transport.')
        ->and($canonical->visibility)->toBe('internal')
        ->and($canonical->is_private)->toBeTrue()
        ->and($canonical->occurred_at?->equalTo($createdAt))->toBeTrue()
        ->and($canonical->created_at?->equalTo($createdAt))->toBeTrue()
        ->and($canonical->updated_at?->equalTo($updatedAt))->toBeTrue()
        ->and($canonical->deleted_at?->equalTo($deletedAt))->toBeTrue()
        ->and($canonical->trashed())->toBeTrue();
});

it('reruns the migration idempotently using the explicit legacy source id', function () {
    $site = Site::factory()->create();
    $author = User::factory()->create();
    $client = Client::factory()->create(['site_id' => $site->id]);
    $legacy = ProgressNote::query()->create([
        'client_id' => $client->id,
        'author_id' => $author->id,
        'note_type' => 'activity',
        'content' => 'Joined the cooking group.',
        'visibility' => 'staff_only',
    ]);

    $this->artisan('oblivion:migrate-progress-notes-to-client-notes')->assertSuccessful();
    $this->artisan('oblivion:migrate-progress-notes-to-client-notes')->assertSuccessful();

    expect(ClientNote::query()
        ->where('legacy_progress_note_id', $legacy->id)
        ->count())->toBe(1)
        ->and(ClientNote::query()
            ->where('legacy_progress_note_id', $legacy->id)
            ->value('legacy_progress_note_id'))->toBe($legacy->id);
});

it('adopts an earlier JSON marker migration instead of duplicating history', function () {
    $site = Site::factory()->create();
    $author = User::factory()->create();
    $client = Client::factory()->create(['site_id' => $site->id]);
    $legacy = ProgressNote::query()->create([
        'client_id' => $client->id,
        'author_id' => $author->id,
        'note_type' => 'communication',
        'content' => 'Family was updated after the appointment.',
        'visibility' => 'include_family',
    ]);
    $legacyEvent = TimelineEvent::query()->create([
        'type' => 'progress_note',
        'source_type' => ProgressNote::class,
        'source_id' => $legacy->id,
        'occurred_at' => $legacy->created_at,
        'actor_user_id' => $author->id,
        'client_id' => $client->id,
        'subject' => 'Progress note: Communication',
        'body' => $legacy->content,
        'meta' => ['_projected' => true],
        'visibility' => 'portal',
        'is_pinned' => false,
        'created_by' => $author->id,
    ]);
    $earlierCanonical = ClientNote::query()->create([
        'client_id' => $client->id,
        'user_id' => $author->id,
        'type' => 'daily_note',
        'category' => 'communication',
        'body' => $legacy->content,
        'occurred_at' => $legacy->created_at,
        'visibility' => 'portal',
        'attachments' => [
            'legacy_progress_note_id' => $legacy->id,
            'migration_source' => 'progress_notes',
        ],
    ]);

    $this->artisan('oblivion:migrate-progress-notes-to-client-notes')
        ->assertSuccessful();

    $canonical = ClientNote::withTrashed()
        ->where('legacy_progress_note_id', $legacy->id)
        ->sole();

    expect($canonical->id)->toBe($earlierCanonical->id)
        ->and($canonical->type)->toBe('progress_note')
        ->and($canonical->category)->toBe('communication')
        ->and($canonical->body)->toBe($legacy->content)
        ->and(ClientNote::withTrashed()->where('client_id', $client->id)->count())->toBe(1);

    $canonicalEvents = TimelineEvent::query()
        ->where('source_type', ClientNote::class)
        ->where('source_id', $canonical->id)
        ->get();
    expect($canonicalEvents)->toHaveCount(1)
        ->and($canonicalEvents->sole()->id)->toBe($legacyEvent->id);
});

it('rebinds the one existing timeline event to the canonical ClientNote', function () {
    $site = Site::factory()->create();
    $author = User::factory()->create();
    $client = Client::factory()->create(['site_id' => $site->id]);
    $legacy = ProgressNote::query()->create([
        'client_id' => $client->id,
        'author_id' => $author->id,
        'note_type' => 'general',
        'content' => 'A settled afternoon.',
        'visibility' => 'staff_only',
    ]);
    $legacyEvent = TimelineEvent::query()->updateOrCreate(
        [
            'type' => 'progress_note',
            'source_type' => ProgressNote::class,
            'source_id' => $legacy->id,
        ],
        [
            'occurred_at' => $legacy->created_at,
            'actor_user_id' => $author->id,
            'client_id' => $client->id,
            'subject' => 'Progress note: General',
            'body' => $legacy->content,
            'meta' => ['_projected' => true],
            'visibility' => 'internal',
            'is_pinned' => false,
            'created_by' => $author->id,
        ],
    );

    $this->artisan('oblivion:migrate-progress-notes-to-client-notes')->assertSuccessful();

    $canonical = ClientNote::query()
        ->where('legacy_progress_note_id', $legacy->id)
        ->sole();
    $canonicalEvents = TimelineEvent::query()
        ->where('source_type', ClientNote::class)
        ->where('source_id', $canonical->id)
        ->get();

    expect(TimelineEvent::query()
        ->where('source_type', ProgressNote::class)
        ->where('source_id', $legacy->id)
        ->count())->toBe(0)
        ->and($canonicalEvents)->toHaveCount(1)
        ->and($canonicalEvents->first()->id)->toBe($legacyEvent->id);
});

it('keeps the legacy POST compatible while writing only ClientNote', function () {
    $site = Site::factory()->create();
    $author = clientNoteConsolidationUserAtSite($site, [
        'clients.viewAny',
        'progress_notes.create',
    ]);
    $client = Client::factory()->create(['site_id' => $site->id]);

    $this->actingAs($author)
        ->post('/operations/progress-notes', [
            'client_id' => $client->id,
            'content' => 'Enjoyed music group and chose the closing song.',
            'note_type' => 'activity',
            'mood_rating' => 8,
            'emotions' => ['happy', 'excited'],
            'visibility' => 'include_family',
            'client_request_uuid' => Str::uuid()->toString(),
        ])
        ->assertRedirect();

    $canonical = ClientNote::query()
        ->where('client_id', $client->id)
        ->where('body', 'Enjoyed music group and chose the closing song.')
        ->sole();

    expect($canonical->user_id)->toBe($author->id)
        ->and($canonical->type)->toBe('progress_note')
        ->and($canonical->category)->toBe('activity')
        ->and($canonical->mood_rating)->toBe(8)
        ->and($canonical->behaviour_tags)->toBe(['happy', 'excited'])
        ->and($canonical->visibility)->toBe('portal')
        ->and(ProgressNote::query()->count())->toBe(0);
});

it('forbids an unassigned support worker from mutating another client at the same Site through legacy routes', function () {
    $site = Site::factory()->create();
    $worker = clientNoteConsolidationUserAtSite($site, [
        'clients.viewAssigned',
        'progress_notes.create',
        'progress_notes.update',
        'progress_notes.delete',
    ], 'support_worker');
    $supportWorkerRole = Role::query()->firstOrCreate(
        ['name' => 'support_worker'],
        ['label' => 'Support Worker', 'level' => 40, 'type' => 'system'],
    );
    $worker->roles()->syncWithoutDetaching([$supportWorkerRole->id]);

    $unassignedClient = Client::factory()->create(['site_id' => $site->id]);
    $noteToUpdate = ClientNote::query()->create([
        'client_id' => $unassignedClient->id,
        'user_id' => $worker->id,
        'type' => 'progress_note',
        'body' => 'Must not be changed',
        'visibility' => 'internal',
    ]);
    $noteToDelete = ClientNote::query()->create([
        'client_id' => $unassignedClient->id,
        'user_id' => $worker->id,
        'type' => 'progress_note',
        'body' => 'Must not be deleted',
        'visibility' => 'internal',
    ]);

    $this->actingAs($worker)
        ->post('/operations/progress-notes', [
            'client_id' => $unassignedClient->id,
            'content' => 'Unauthorized unassigned-client note',
            'note_type' => 'general',
        ])
        ->assertForbidden();

    $this->actingAs($worker)
        ->put("/operations/progress-notes/{$noteToUpdate->id}", [
            'content' => 'Unauthorized correction',
        ])
        ->assertForbidden();

    $this->actingAs($worker)
        ->delete("/operations/progress-notes/{$noteToDelete->id}")
        ->assertForbidden();

    expect(ClientNote::query()
        ->where('client_id', $unassignedClient->id)
        ->where('body', 'Unauthorized unassigned-client note')
        ->exists())->toBeFalse()
        ->and($noteToUpdate->fresh()->body)->toBe('Must not be changed')
        ->and($noteToDelete->fresh())->not->toBeNull();
});

it('preserves assigned-worker and Site-authorized manager access through legacy note routes', function () {
    $site = Site::factory()->create();
    $assignedWorker = clientNoteConsolidationUserAtSite($site, [
        'clients.viewAssigned',
        'progress_notes.create',
        'progress_notes.update',
        'progress_notes.delete',
    ], 'support_worker');
    $supportWorkerRole = Role::query()->firstOrCreate(
        ['name' => 'support_worker'],
        ['label' => 'Support Worker', 'level' => 40, 'type' => 'system'],
    );
    $assignedWorker->roles()->syncWithoutDetaching([$supportWorkerRole->id]);
    $assignedClient = Client::factory()->create(['site_id' => $site->id]);
    $assignedClient->supportWorkers()->attach($assignedWorker->id);

    $this->actingAs($assignedWorker)
        ->post('/operations/progress-notes', [
            'client_id' => $assignedClient->id,
            'content' => 'Assigned worker note',
            'note_type' => 'general',
        ])
        ->assertRedirect();
    $assignedNote = ClientNote::query()
        ->where('client_id', $assignedClient->id)
        ->where('body', 'Assigned worker note')
        ->sole();
    $this->actingAs($assignedWorker)
        ->put("/operations/progress-notes/{$assignedNote->id}", [
            'content' => 'Assigned worker correction',
        ])
        ->assertRedirect();

    $manager = clientNoteConsolidationUserAtSite($site, [
        'clients.viewAny',
        'progress_notes.create',
        'progress_notes.update',
        'progress_notes.delete',
    ]);
    $managerClient = Client::factory()->create(['site_id' => $site->id]);
    $managerNote = ClientNote::query()->create([
        'client_id' => $managerClient->id,
        'user_id' => $manager->id,
        'type' => 'progress_note',
        'body' => 'Manager may remove this note',
        'visibility' => 'internal',
    ]);

    $this->actingAs($manager)
        ->delete("/operations/progress-notes/{$managerNote->id}")
        ->assertRedirect();

    expect($assignedNote->fresh()->body)->toBe('Assigned worker correction')
        ->and(ClientNote::query()->find($managerNote->id))->toBeNull()
        ->and(ClientNote::withTrashed()->findOrFail($managerNote->id)->trashed())->toBeTrue();
});

it('resolves a legacy route id before a colliding canonical note id', function () {
    $site = Site::factory()->create();
    $author = clientNoteConsolidationUserAtSite($site, [
        'clients.viewAny',
        'progress_notes.update',
    ]);
    $client = Client::factory()->create(['site_id' => $site->id]);
    $now = now();

    DB::table('client_notes')->insert([
        [
            'id' => 900001,
            'legacy_progress_note_id' => 900002,
            'client_id' => $client->id,
            'user_id' => $author->id,
            'type' => 'progress_note',
            'body' => 'Migrated legacy record',
            'visibility' => 'internal',
            'created_at' => $now,
            'updated_at' => $now,
        ],
        [
            'id' => 900002,
            'legacy_progress_note_id' => null,
            'client_id' => $client->id,
            'user_id' => $author->id,
            'type' => 'daily_note',
            'body' => 'Unrelated canonical record',
            'visibility' => 'internal',
            'created_at' => $now,
            'updated_at' => $now,
        ],
    ]);

    $this->actingAs($author)
        ->put('/operations/progress-notes/900002', [
            'content' => 'Corrected migrated record',
        ])
        ->assertRedirect();

    expect(ClientNote::query()->findOrFail(900001)->body)
        ->toBe('Corrected migrated record')
        ->and(ClientNote::query()->findOrFail(900002)->body)
        ->toBe('Unrelated canonical record');
});

it('does not let legacy progress note routes mutate another canonical note type', function () {
    $site = Site::factory()->create();
    $author = clientNoteConsolidationUserAtSite($site, [
        'clients.viewAny',
        'progress_notes.update',
        'progress_notes.delete',
    ]);
    $client = Client::factory()->create(['site_id' => $site->id]);
    $dailyNote = ClientNote::query()->create([
        'client_id' => $client->id,
        'user_id' => $author->id,
        'type' => 'daily_note',
        'body' => 'Daily note must remain outside the legacy adapter.',
        'visibility' => 'internal',
    ]);

    $this->actingAs($author)
        ->put("/operations/progress-notes/{$dailyNote->id}", [
            'content' => 'Mutated through the wrong compatibility route.',
        ])
        ->assertNotFound();

    $this->actingAs($author)
        ->delete("/operations/progress-notes/{$dailyNote->id}")
        ->assertNotFound();

    expect($dailyNote->fresh()->body)
        ->toBe('Daily note must remain outside the legacy adapter.');
});

it('writes and reads care plan hurdle and progress notes through ClientNote', function () {
    [$user, , $plan, $goal] = makeClientNoteConsolidationGoal();

    $this->actingAs($user)->post(
        "/operations/care-plans/{$plan->id}/goals/{$goal->id}/hurdles",
        [
            'content' => 'Transport keeps falling through',
            'reason' => 'No accessible van',
        ],
    )->assertRedirect();
    $this->actingAs($user)->patch(
        "/operations/care-plans/{$plan->id}/goals/{$goal->id}/progress",
        ['progress_percentage' => 35, 'note' => 'Made steady progress this week'],
    )->assertRedirect();

    $hurdle = ClientNote::query()
        ->where('care_plan_goal_id', $goal->id)
        ->where('category', 'goal_hurdle')
        ->sole();
    $progress = ClientNote::query()
        ->where('care_plan_goal_id', $goal->id)
        ->where('category', 'goal_progress')
        ->sole();

    expect($hurdle->body)->toBe('Transport keeps falling through')
        ->and($hurdle->is_flagged)->toBeTrue()
        ->and($progress->body)->toBe('Made steady progress this week')
        ->and(ProgressNote::query()->count())->toBe(0);

    $this->actingAs($user)
        ->getJson("/operations/care-plans/{$plan->id}/goals/{$goal->id}")
        ->assertOk()
        ->assertJsonPath('hurdles.0.content', 'Transport keeps falling through')
        ->assertJsonPath('progress_log.0.content', 'Made steady progress this week');

    $this->actingAs($user)->patch(
        "/operations/care-plans/{$plan->id}/goals/{$goal->id}/hurdles/{$hurdle->id}/resolve",
    )->assertRedirect();
    expect($hurdle->fresh()->is_flagged)->toBeFalse();
});

it('rejects shift and goal ids belonging to another client', function () {
    $site = Site::factory()->create();
    $author = clientNoteConsolidationUserAtSite($site, [
        'clients.viewAny',
        'progress_notes.create',
    ]);
    $client = Client::factory()->create(['site_id' => $site->id]);
    $otherClient = Client::factory()->create(['site_id' => $site->id]);
    $otherShift = Shift::factory()->create([
        'client_id' => $otherClient->id,
        'site_id' => $site->id,
        'user_id' => $author->id,
        'created_by' => $author->id,
    ]);
    $otherPlan = CarePlan::query()->create([
        'client_id' => $otherClient->id,
        'title' => 'Other client plan',
        'status' => 'active',
        'plan_type' => 'support',
        'created_by' => $author->id,
    ]);
    $otherGoal = CarePlanGoal::query()->create([
        'care_plan_id' => $otherPlan->id,
        'client_id' => $otherClient->id,
        'title' => 'Other client goal',
        'category' => 'Health',
        'priority' => 'medium',
        'created_by' => $author->id,
    ]);
    $base = [
        'client_id' => $client->id,
        'content' => 'Must stay with the requested client.',
        'note_type' => 'general',
    ];

    $this->actingAs($author)
        ->from("/operations/clients/{$client->id}?tab=progress_notes")
        ->post('/operations/progress-notes', [
            ...$base,
            'shift_id' => $otherShift->id,
        ])
        ->assertSessionHasErrors('shift_id');
    $this->actingAs($author)
        ->from("/operations/clients/{$client->id}?tab=progress_notes")
        ->post('/operations/progress-notes', [
            ...$base,
            'care_plan_goal_id' => $otherGoal->id,
        ])
        ->assertSessionHasErrors('care_plan_goal_id');

    expect(ClientNote::query()->where('client_id', $client->id)->count())->toBe(0)
        ->and(ProgressNote::query()->where('client_id', $client->id)->count())->toBe(0);
});

it('builds family emotion summaries only from portal-visible nonprivate nondraft ClientNotes', function () {
    $site = Site::factory()->create();
    $client = Client::factory()->create(['site_id' => $site->id]);
    $otherClient = Client::factory()->create(['site_id' => $site->id]);
    $portalUser = User::factory()->create([
        'role' => 'next_of_kin',
        'approved_at' => now(),
    ]);
    $portalRole = Role::query()->firstOrCreate(
        ['name' => 'next_of_kin'],
        ['label' => 'Next of Kin', 'level' => 10, 'type' => 'system'],
    );
    $portalUser->roles()->attach($portalRole);
    grantClientNoteConsolidationPermissions($portalUser, ['clients.viewPortal']);
    $client->portalUsers()->attach($portalUser->id, ['relation' => 'parent']);
    NextOfKin::query()->create([
        'client_id' => $client->id,
        'user_id' => $portalUser->id,
        'relationship' => 'parent',
    ]);
    $familyConsentType = ConsentType::factory()->create([
        'name' => 'Information Sharing with Whānau / Family',
        'category' => 'communication',
    ]);
    AuthoritativeConsentFixture::manualSelf($client, $familyConsentType, $portalUser, [
        'status' => 'given',
        'given_at' => now(),
        'expires_at' => now()->addMonth(),
    ]);
    FamilyPortalSetting::query()->create([
        'client_id' => $client->id,
        'show_care_notes' => true,
    ]);
    $author = User::factory()->create();
    $base = [
        'client_id' => $client->id,
        'user_id' => $author->id,
        'type' => 'progress_note',
        'body' => 'Mood observation',
        'occurred_at' => now(),
        'appears_on_timeline' => false,
    ];

    ClientNote::query()->create([
        ...$base,
        'visibility' => 'portal',
        'behaviour_tags' => ['happy', 'calm'],
    ]);
    ClientNote::query()->create([
        ...$base,
        'visibility' => 'internal',
        'behaviour_tags' => ['sad'],
    ]);
    ClientNote::query()->create([
        ...$base,
        'visibility' => 'portal',
        'is_private' => true,
        'behaviour_tags' => ['anxious'],
    ]);
    ClientNote::query()->create([
        ...$base,
        'visibility' => 'portal',
        'is_draft' => true,
        'behaviour_tags' => ['tired'],
    ]);
    ProgressNote::query()->create([
        'client_id' => $client->id,
        'author_id' => $author->id,
        'note_type' => 'general',
        'content' => 'Legacy source must not be read.',
        'emotions' => ['legacy'],
        'visibility' => 'include_family',
    ]);

    $this->actingAs($portalUser)
        ->get("/portal/clients/{$client->id}/dashboard")
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('portal/family-dashboard')
            ->where('emotionSummary.today.happy', 1)
            ->where('emotionSummary.today.calm', 1)
            ->missing('emotionSummary.today.sad')
            ->missing('emotionSummary.today.anxious')
            ->missing('emotionSummary.today.tired')
            ->missing('emotionSummary.today.legacy'));

    $this->actingAs($portalUser)
        ->get("/portal/clients/{$otherClient->id}/dashboard")
        ->assertForbidden();
});

it('has no operational ProgressNote model or dual-source profile consumers', function () {
    $roots = [base_path('app'), base_path('resources/js'), base_path('routes')];
    $allowed = [
        'app/Models/ProgressNote.php',
        'app/Console/Commands/MigrateProgressNotesToClientNotes.php',
        'app/Console/Commands/VerifyClientNoteConsolidation.php',
        'app/Console/Commands/AuditClientNoteConsolidation.php',
    ];
    $patterns = [
        '/use\s+App\\\\Models\\\\ProgressNote\s*;/',
        '/(?<![A-Za-z0-9_\\\\])ProgressNote::/',
        '/App\\\\Models\\\\ProgressNote::/',
        '/client_progress_notes/',
        '/legacyProgressNotes/',
    ];
    $violations = [];

    foreach ($roots as $root) {
        $files = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
        );

        foreach ($files as $file) {
            if (! $file->isFile() || ! in_array($file->getExtension(), ['php', 'ts', 'tsx', 'js', 'jsx'], true)) {
                continue;
            }

            $relative = str_replace('\\', '/', substr($file->getPathname(), strlen(base_path()) + 1));
            if (in_array($relative, $allowed, true)) {
                continue;
            }

            $source = file_get_contents($file->getPathname());
            foreach ($patterns as $pattern) {
                if (preg_match($pattern, $source) === 1) {
                    $violations[] = $relative;
                    break;
                }
            }
        }
    }

    sort($violations);

    expect($violations)->toBe([]);
});
