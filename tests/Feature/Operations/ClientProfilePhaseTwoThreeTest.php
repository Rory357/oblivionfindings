<?php

use App\Enums\NextOfKinRelationship;
use App\Models\AuditLog;
use App\Models\Client;
use App\Models\ClientExcursionRequest;
use App\Models\ClientFinancialDiscrepancy;
use App\Models\ClientLeaveRequest;
use App\Models\ClientNote;
use App\Models\ClientPathPlan;
use App\Models\ClientPurchaseRequest;
use App\Models\ClientSeizureEntry;
use App\Models\DataRetentionPolicy;
use App\Models\NextOfKin;
use App\Models\Permission;
use App\Models\ProgressNote;
use App\Models\Role;
use App\Models\Site;
use App\Models\TimelineEvent;
use App\Models\User;
use App\Services\Client\ActionsAggregator;
use App\Services\Client\BehaviourPatternsService;
use App\Services\Timeline\TimelineEmitter;

function grantPhaseTwoThreePermissions(User $user, array $permissionKeys): void
{
    $role = Role::query()->firstOrCreate(
        ['name' => 'client_profile_phase_two_three_test_'.$user->id],
        ['label' => 'Phase 2/3 Test Role', 'level' => 60, 'type' => 'custom'],
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

it('stores leave requests with the canonical timeline projection', function () {
    $manager = User::factory()->create();
    grantPhaseTwoThreePermissions($manager, [
        'clients.viewAny',
        'clients.update',
    ]);

    $client = Client::factory()->create();

    $this->actingAs($manager)
        ->post("/operations/clients/{$client->id}/leave", [
            'starts_on' => '2026-06-01',
            'ends_on' => '2026-06-07',
            'destination' => 'Family bach',
            'support_required' => 'Carry medication kit + emergency phone',
            'risks_and_mitigations' => 'Travel sickness: bring lozenges',
            'emergency_contact' => 'Aunt Jane 021-123-4567',
        ])
        ->assertRedirect();

    $leave = ClientLeaveRequest::query()
        ->where('client_id', $client->id)
        ->firstOrFail();

    expect($leave->destination)->toBe('Family bach')
        ->and($leave->status)->toBe('requested')
        ->and($leave->requested_by)->toBe($manager->id);

    $event = TimelineEvent::query()
        ->where('source_type', ClientLeaveRequest::class)
        ->where('source_id', $leave->id)
        ->where('type', 'leave_request')
        ->first();

    expect($event)->not->toBeNull()
        ->and($event->subject)->toContain('Family bach')
        ->and(($event->meta ?? [])['_projected'] ?? null)->toBeTrue();
});

it('updates leave status and stamps approver', function () {
    $manager = User::factory()->create();
    grantPhaseTwoThreePermissions($manager, ['clients.viewAny', 'clients.update']);
    $client = Client::factory()->create();
    $leave = ClientLeaveRequest::create([
        'client_id' => $client->id,
        'starts_on' => '2026-07-01',
        'ends_on' => '2026-07-03',
        'destination' => 'Weekend retreat',
        'status' => 'requested',
        'requested_by' => $manager->id,
    ]);

    $this->actingAs($manager)
        ->put("/operations/clients/{$client->id}/leave/{$leave->id}", [
            'status' => 'approved',
            'approval_notes' => 'Confirmed with on-call manager.',
        ])
        ->assertRedirect();

    $leave->refresh();

    expect($leave->status)->toBe('approved')
        ->and($leave->approved_by)->toBe($manager->id)
        ->and($leave->approved_at)->not->toBeNull()
        ->and($leave->approval_notes)->toContain('on-call');
});

it('creates excursions and emits a status_critical-free timeline event by default', function () {
    $manager = User::factory()->create();
    grantPhaseTwoThreePermissions($manager, ['clients.viewAny', 'clients.update']);
    $client = Client::factory()->create();

    $this->actingAs($manager)
        ->post("/operations/clients/{$client->id}/excursions", [
            'starts_at' => '2026-06-15T10:00:00',
            'ends_at' => '2026-06-15T15:00:00',
            'destination' => 'Local museum',
            'activity_description' => 'Cultural visit with one-on-one support',
            'transport_method' => 'Fleet van',
            'risk_assessment' => 'Low risk; bring water + sun hat',
        ])
        ->assertRedirect();

    $excursion = ClientExcursionRequest::query()
        ->where('client_id', $client->id)
        ->firstOrFail();

    expect($excursion->status)->toBe('proposed')
        ->and($excursion->transport_method)->toBe('Fleet van');

    $event = TimelineEvent::query()
        ->where('source_type', ClientExcursionRequest::class)
        ->where('source_id', $excursion->id)
        ->where('type', 'excursion')
        ->first();

    expect($event)->not->toBeNull();
});

it('respects per-client seizure escalation overrides', function () {
    $manager = User::factory()->create();
    grantPhaseTwoThreePermissions($manager, [
        'clients.viewAny',
        'medications.view',
        'medications.administer.record',
    ]);

    $client = Client::factory()->create([
        'seizure_duration_escalation_seconds' => 120, // 2-minute override
    ]);

    $entry = ClientSeizureEntry::create([
        'client_id' => $client->id,
        'occurred_at' => now(),
        'duration_seconds' => 180, // exceeds per-client 120 even though under default 300
        'seizure_type' => 'tonic_clonic',
        'response_taken' => 'Recovery position, called nurse',
        'recorded_by' => $manager->id,
    ]);

    $event = TimelineEvent::query()
        ->where('source_type', ClientSeizureEntry::class)
        ->where('source_id', $entry->id)
        ->first();

    expect($event)->not->toBeNull()
        ->and($event->type)->toBe('status_critical');
});

it('preserves manually recorded timeline events when a model projection retypes', function () {
    // Verifies the meta._projected guard added to TimelineEmitter::project.
    $user = User::factory()->create();
    $client = Client::factory()->create();

    $note = ClientNote::query()->create([
        'client_id' => $client->id,
        'user_id' => $user->id,
        'type' => 'quick',
        'category' => 'other',
        'subject' => 'Initial subject',
        'body' => 'Note body',
        'occurred_at' => now(),
        'appears_on_timeline' => true,
    ]);

    // Controller writes its own event tied to the SAME source (no _projected marker).
    $manualEvent = app(TimelineEmitter::class)->record([
        'type' => 'note_acknowledged',
        'occurred_at' => now(),
        'source_type' => ClientNote::class,
        'source_id' => $note->id,
        'client_id' => $client->id,
        'subject' => 'Manager acknowledged',
        'visibility' => 'internal',
        'is_pinned' => false,
        'actor_user_id' => $user->id,
        'created_by' => $user->id,
    ]);

    // Now switch the note type — the emitter should only delete its OWN projection.
    $note->update(['type' => 'communication', 'contact_person' => 'Dad']);

    $survives = TimelineEvent::query()->whereKey($manualEvent->id)->exists();
    expect($survives)->toBeTrue();
});

it('aggregates behaviour patterns from clinical observations and concern notes', function () {
    $user = User::factory()->create();
    grantPhaseTwoThreePermissions($user, [
        'clients.viewAny',
        'observations.viewAny',
        'progress_notes.viewAny',
    ]);

    $client = Client::factory()->create();

    ClientNote::query()->create([
        'client_id' => $client->id,
        'user_id' => $user->id,
        'type' => 'daily_note',
        'category' => 'concern',
        'subject' => 'Loud environment trigger',
        'body' => 'Became overwhelmed at the supermarket.',
        'behaviour_tags' => ['overwhelmed', 'withdrawn'],
        'is_flagged' => true,
        'flagged_reason' => 'Pattern emerging',
        'occurred_at' => now()->subDay(),
    ]);

    $payload = app(BehaviourPatternsService::class)->forClient($client, $user, 14);

    expect($payload['window_days'])->toBe(14)
        ->and($payload['concern_note_count'])->toBeGreaterThanOrEqual(1)
        ->and($payload['top_behaviour_tags'])
        ->toBeArray()
        ->and(collect($payload['top_behaviour_tags'])->pluck('label')->all())
        ->toContain('overwhelmed');
});

it('returns a manager review queue across multiple clients with site filter', function () {
    $manager = User::factory()->create();
    grantPhaseTwoThreePermissions($manager, [
        'clients.viewAny',
        'progress_notes.review',
        'progress_notes.viewAny',
    ]);

    $siteA = Site::factory()->create(['name' => 'Wairau House']);
    $siteB = Site::factory()->create(['name' => 'Kowhai House']);
    $clientA = Client::factory()->create(['site_id' => $siteA->id]);
    $clientB = Client::factory()->create(['site_id' => $siteB->id]);

    ClientNote::query()->create([
        'client_id' => $clientA->id,
        'user_id' => $manager->id,
        'type' => 'daily_note',
        'category' => 'concern',
        'subject' => 'Needs review A',
        'body' => 'Concern A',
        'is_flagged' => true,
        'occurred_at' => now(),
    ]);

    ClientNote::query()->create([
        'client_id' => $clientB->id,
        'user_id' => $manager->id,
        'type' => 'daily_note',
        'category' => 'concern',
        'subject' => 'Needs review B',
        'body' => 'Concern B',
        'is_flagged' => true,
        'occurred_at' => now(),
    ]);

    $this->actingAs($manager)
        ->get('/operations/review-queue')
        ->assertOk()
        ->assertInertia(
            fn ($page) => $page
                ->component('operations/review-queue/index')
                ->where('stats.total', 2)
                ->where('stats.clients', 2)
                ->where('stats.sites', 2),
        );

    $this->actingAs($manager)
        ->get('/operations/review-queue?site='.$siteA->id)
        ->assertOk()
        ->assertInertia(
            fn ($page) => $page
                ->component('operations/review-queue/index')
                ->where('stats.total', 1)
                ->where('stats.clients', 1),
        );
});

it('upserts a PATH plan and surfaces overdue reviews in the actions aggregator', function () {
    $manager = User::factory()->create();
    grantPhaseTwoThreePermissions($manager, [
        'clients.viewAny',
        'clients.update',
    ]);

    $client = Client::factory()->create();

    $this->actingAs($manager)
        ->post("/operations/clients/{$client->id}/path-plan", [
            'dream' => 'Live independently in own flat by 2028',
            'north_star' => 'Independence with community',
            'strengths' => ['cooking', 'making friends', 'punctuality'],
            'trusted_people' => ['Mum', 'Uncle Tom', 'Support worker Jess'],
            'independence_goals' => ['budget weekly', 'catch the bus'],
            'community' => 'Wednesday art group',
            'meaningful_outcomes' => 'Belonging and choice',
            'plan_date' => '2026-05-01',
            'next_review_at' => now()->subDay()->toDateString(),
        ])
        ->assertRedirect();

    $plan = ClientPathPlan::query()
        ->where('client_id', $client->id)
        ->firstOrFail();

    expect($plan->dream)->toContain('Live independently')
        ->and($plan->strengths)->toContain('cooking')
        ->and($plan->updated_by)->toBe($manager->id);

    // Timeline event emitted (and pinned).
    $event = TimelineEvent::query()
        ->where('source_type', ClientPathPlan::class)
        ->where('source_id', $plan->id)
        ->first();
    expect($event)->not->toBeNull()
        ->and((bool) $event->is_pinned)->toBeTrue();

    // Overdue review shows up in the aggregator.
    $items = app(ActionsAggregator::class)
        ->forClient($client, $manager);
    $types = collect($items)->pluck('type')->all();
    expect($types)->toContain('path_plan_review_due');
});

it('surfaces purchase requests and financial discrepancies on the client profile payload', function () {
    $manager = User::factory()->create();
    grantPhaseTwoThreePermissions($manager, [
        'clients.viewAny',
        'clients.update',
    ]);

    $client = Client::factory()->create();

    ClientPurchaseRequest::query()->create([
        'client_id' => $client->id,
        'description' => 'New winter jacket',
        'amount' => 189.50,
        'status' => 'requested',
        'requested_at' => now()->subDay(),
        'requested_by' => $manager->id,
    ]);

    ClientFinancialDiscrepancy::query()->create([
        'client_id' => $client->id,
        'description' => 'Petty cash receipt missing for community outing',
        'amount' => 42.75,
        'status' => 'open',
        'raised_at' => now()->subHours(3),
        'raised_by' => $manager->id,
    ]);

    $this->actingAs($manager)
        ->get("/operations/clients/{$client->id}")
        ->assertOk()
        ->assertInertia(
            fn ($page) => $page
                ->where('client_finance.purchase_requests.0.description', 'New winter jacket')
                ->where('client_finance.purchase_requests.0.amount', 189.5)
                ->where('client_finance.purchase_requests.0.status', 'requested')
                ->where('client_finance.discrepancies.0.description', 'Petty cash receipt missing for community outing')
                ->where('client_finance.discrepancies.0.amount', 42.75)
                ->where('client_finance.discrepancies.0.status', 'open'),
        );
});

it('exposes a categorised relationship for each next-of-kin via the enum', function () {
    $manager = User::factory()->create();
    grantPhaseTwoThreePermissions($manager, [
        'clients.viewAny',
        'clients.update',
    ]);

    $client = Client::factory()->create();

    $parentUser = User::factory()->create(['name' => 'Sue Parent']);
    $guardianUser = User::factory()->create(['name' => 'Pat Guardian']);
    $legacyUser = User::factory()->create(['name' => 'Mx Legacy']);

    NextOfKin::query()->create([
        'user_id' => $parentUser->id,
        'client_id' => $client->id,
        'relationship' => NextOfKinRelationship::Parent->value,
        'is_primary_contact' => true,
    ]);
    NextOfKin::query()->create([
        'user_id' => $guardianUser->id,
        'client_id' => $client->id,
        'relationship' => NextOfKinRelationship::Guardian->value,
    ]);
    NextOfKin::query()->create([
        'user_id' => $legacyUser->id,
        'client_id' => $client->id,
        // Free-text value that pre-dates the enum — controller should
        // still hand back a sensible category fallback.
        'relationship' => 'second cousin once removed',
    ]);

    $this->actingAs($manager)
        ->get("/operations/clients/{$client->id}")
        ->assertOk()
        ->assertInertia(
            fn ($page) => $page
                ->where('next_of_kins.0.relationship_label', 'Parent')
                ->where('next_of_kins.0.relationship_category', 'family')
                ->where('next_of_kins.1.relationship_label', 'Legal Guardian')
                ->where('next_of_kins.1.relationship_category', 'guardian')
                ->where('next_of_kins.2.relationship_label', 'second cousin once removed')
                ->where('next_of_kins.2.relationship_category', 'other'),
        );
});

it('honours DataRetentionPolicy overrides when pruning audit logs and timeline events', function () {
    $client = Client::factory()->create();

    // Stored retention policies override the config defaults of 2 yrs audit /
    // 5 yrs timeline. Bumping audit to 7 yrs should spare a 3-year-old log;
    // shrinking timeline to 1 yr should sweep a 2-year-old event.
    DataRetentionPolicy::query()->create([
        'model_type' => 'audit_logs',
        'policy_name' => 'Audit logs',
        'retention_period_years' => 7,
        'active' => true,
    ]);
    DataRetentionPolicy::query()->create([
        'model_type' => 'timeline_events',
        'policy_name' => 'Timeline events',
        'retention_period_years' => 1,
        'active' => true,
    ]);

    AuditLog::query()->create([
        'action' => 'retention.test.kept',
        'auditable_type' => Client::class,
        'auditable_id' => $client->id,
        'created_at' => now()->subYears(3), // older than 2-yr default, younger than 7-yr override
        'updated_at' => now()->subYears(3),
    ]);

    TimelineEvent::query()->create([
        'type' => 'retention_test_swept',
        'occurred_at' => now()->subYears(2), // older than 1-yr override, younger than 5-yr default
        'client_id' => $client->id,
        'is_pinned' => false,
    ]);

    $this->artisan('oblivion:prune-retention')->assertSuccessful();

    // The 3-yr-old audit log survives because the override raised retention
    // from 2 yrs (config default) to 7 yrs.
    expect(
        AuditLog::query()->where('action', 'retention.test.kept')->exists(),
    )->toBeTrue();

    // The 2-yr-old timeline event is swept because the override shrank
    // retention from 5 yrs (config default) to 1 yr.
    expect(
        TimelineEvent::query()->where('type', 'retention_test_swept')->exists(),
    )->toBeFalse();
});

it('migrates a legacy ProgressNote into a ClientNote idempotently', function () {
    $user = User::factory()->create();
    $client = Client::factory()->create();

    $progress = ProgressNote::create([
        'organization_id' => 1,
        'client_id' => $client->id,
        'author_id' => $user->id,
        'note_type' => 'activity',
        'content' => 'Joined the cooking group and helped prep dinner.',
        'mood_rating' => 8,
        'emotions' => ['happy', 'engaged'],
        'is_flagged' => false,
        'visibility' => 'staff_only',
    ]);

    $this->artisan('oblivion:migrate-progress-notes-to-client-notes')
        ->assertSuccessful();

    $migrated = ClientNote::query()
        ->where('client_id', $client->id)
        ->where('type', 'daily_note')
        ->first();

    expect($migrated)->not->toBeNull()
        ->and(($migrated->attachments ?? [])['legacy_progress_note_id'] ?? null)
        ->toBe($progress->id)
        ->and($migrated->mood_rating)->toBe(8)
        ->and($migrated->category)->toBe('activity');

    // Re-running should skip already-migrated rows.
    $countBefore = ClientNote::query()->where('client_id', $client->id)->count();
    $this->artisan('oblivion:migrate-progress-notes-to-client-notes')
        ->assertSuccessful();
    $countAfter = ClientNote::query()->where('client_id', $client->id)->count();

    expect($countAfter)->toBe($countBefore);
});
