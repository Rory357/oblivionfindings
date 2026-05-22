<?php

use App\Models\CarePlan;
use App\Models\Client;
use App\Models\ClientAppointment;
use App\Models\ClientAssessment;
use App\Models\ClientBowelEntry;
use App\Models\ClientDocument;
use App\Models\ClientFluidEntry;
use App\Models\ClientIncident;
use App\Models\ClientNote;
use App\Models\ClientRisk;
use App\Models\ClientRoutine;
use App\Models\ClientSeizureEntry;
use App\Models\Permission;
use App\Models\Role;
use App\Models\TimelineEvent;
use App\Models\User;
use App\Services\Client\ActionsAggregator;

function grantClientProfilePhaseOnePermissions(User $user, array $permissionKeys): void
{
    $role = Role::query()->firstOrCreate(
        ['name' => 'client_profile_phase_one_test_'.$user->id],
        ['label' => 'Client Profile Phase One Test', 'level' => 50, 'type' => 'custom'],
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

it('projects client notes through the canonical timeline emitter and retracts deleted notes', function () {
    $user = User::factory()->create();
    $client = Client::factory()->create();

    $note = ClientNote::query()->create([
        'client_id' => $client->id,
        'user_id' => $user->id,
        'type' => 'quick',
        'category' => 'other',
        'subject' => 'Settled after lunch',
        'body' => 'Settled after lunch and joined the group activity.',
        'occurred_at' => now()->subMinutes(20),
        'appears_on_timeline' => true,
    ]);

    expect(TimelineEvent::query()
        ->where('source_type', ClientNote::class)
        ->where('source_id', $note->id)
        ->where('type', 'quick')
        ->count())->toBe(1);

    $note->update(['type' => 'communication', 'contact_person' => 'Mum']);

    expect(TimelineEvent::query()
        ->where('source_type', ClientNote::class)
        ->where('source_id', $note->id)
        ->where('type', 'quick')
        ->count())->toBe(0)
        ->and(TimelineEvent::query()
            ->where('source_type', ClientNote::class)
            ->where('source_id', $note->id)
            ->where('type', 'communication')
            ->count())->toBe(1);

    $draft = ClientNote::query()->create([
        'client_id' => $client->id,
        'user_id' => $user->id,
        'type' => 'daily_note',
        'category' => 'health',
        'subject' => 'Draft only',
        'body' => 'Needs more detail before submitting.',
        'visibility' => 'portal',
        'is_draft' => true,
        'appears_on_timeline' => true,
    ]);

    expect($draft->fresh()->visibility)->toBe('internal')
        ->and(TimelineEvent::query()
            ->where('source_type', ClientNote::class)
            ->where('source_id', $draft->id)
            ->exists())->toBeFalse();

    $note->delete();

    expect(TimelineEvent::query()
        ->where('source_type', ClientNote::class)
        ->where('source_id', $note->id)
        ->exists())->toBeFalse();
});

it('stores quick notes and daily-note drafts through the client daily note endpoint', function () {
    $user = User::factory()->create();
    $client = Client::factory()->create();
    grantClientProfilePhaseOnePermissions($user, [
        'clients.viewAny',
        'progress_notes.viewAny',
        'progress_notes.create',
        'timeline.create',
    ]);

    $this->actingAs($user)
        ->post("/operations/clients/{$client->id}/daily-notes", [
            'type' => 'quick',
            'category' => 'other',
            'subject' => 'Quick handover',
            'body' => 'Preferred a quiet lunch and asked for music.',
            'is_flagged' => true,
        ])
        ->assertRedirect();

    $quickNote = ClientNote::query()
        ->where('client_id', $client->id)
        ->where('type', 'quick')
        ->firstOrFail();

    expect($quickNote->category)->toBe('other')
        ->and($quickNote->is_flagged)->toBeTrue()
        ->and(TimelineEvent::query()
            ->where('source_type', ClientNote::class)
            ->where('source_id', $quickNote->id)
            ->exists())->toBeTrue();

    $this->actingAs($user)
        ->post("/operations/clients/{$client->id}/daily-notes", [
            'type' => 'daily_note',
            'category' => 'health',
            'subject' => 'Draft health note',
            'body' => 'Draft text.',
            'visibility' => 'portal',
            'is_draft' => true,
        ])
        ->assertRedirect();

    $draft = ClientNote::query()
        ->where('client_id', $client->id)
        ->where('type', 'daily_note')
        ->where('is_draft', true)
        ->firstOrFail();

    expect($draft->visibility)->toBe('internal')
        ->and(TimelineEvent::query()
            ->where('source_type', ClientNote::class)
            ->where('source_id', $draft->id)
            ->exists())->toBeFalse();
});

it('limits flagged note review to users with the review permission', function () {
    $worker = User::factory()->create();
    $manager = User::factory()->create();
    $client = Client::factory()->create();
    $note = ClientNote::query()->create([
        'client_id' => $client->id,
        'user_id' => $worker->id,
        'type' => 'daily_note',
        'category' => 'concern',
        'subject' => 'Needs review',
        'body' => 'Flagged for manager follow-up.',
        'is_flagged' => true,
    ]);

    grantClientProfilePhaseOnePermissions($worker, ['clients.viewAny', 'progress_notes.viewAny']);
    grantClientProfilePhaseOnePermissions($manager, [
        'clients.viewAny',
        'progress_notes.viewAny',
        'progress_notes.review',
    ]);

    $this->actingAs($worker)
        ->post("/operations/clients/{$client->id}/daily-notes/{$note->id}/review")
        ->assertForbidden();

    $this->actingAs($manager)
        ->post("/operations/clients/{$client->id}/daily-notes/{$note->id}/review")
        ->assertRedirect();

    expect($note->fresh()->reviewed_by)->toBe($manager->id)
        ->and($note->fresh()->reviewed_at)->not->toBeNull();
});

it('stores health chart entries and projects them into the client timeline', function () {
    $user = User::factory()->create();
    $client = Client::factory()->create();
    grantClientProfilePhaseOnePermissions($user, [
        'clients.viewAny',
        'medications.view',
        'medications.administer.record',
    ]);

    $this->actingAs($user)
        ->post("/operations/clients/{$client->id}/health/bowel", [
            'occurred_at' => now()->subHour()->toISOString(),
            'bristol_type' => 4,
            'volume' => 'medium',
            'notes' => 'No concerns.',
        ])
        ->assertRedirect();

    $this->actingAs($user)
        ->post("/operations/clients/{$client->id}/health/fluid", [
            'occurred_at' => now()->subMinutes(30)->toISOString(),
            'direction' => 'in',
            'fluid_type' => 'water',
            'volume_ml' => 250,
            'notes' => 'Prompted once.',
        ])
        ->assertRedirect();

    $this->actingAs($user)
        ->post("/operations/clients/{$client->id}/health/seizure", [
            'occurred_at' => now()->subMinutes(10)->toISOString(),
            'duration_seconds' => 360,
            'seizure_type' => 'tonic-clonic',
            'response_taken' => 'Followed seizure plan and escalated.',
            'escalated' => true,
        ])
        ->assertRedirect();

    expect(ClientBowelEntry::query()->where('client_id', $client->id)->count())->toBe(1)
        ->and(ClientFluidEntry::query()->where('client_id', $client->id)->count())->toBe(1)
        ->and(ClientSeizureEntry::query()->where('client_id', $client->id)->count())->toBe(1)
        ->and(TimelineEvent::query()->where('client_id', $client->id)->where('type', 'health_bowel')->count())->toBe(1)
        ->and(TimelineEvent::query()->where('client_id', $client->id)->where('type', 'health_fluid')->count())->toBe(1)
        ->and(TimelineEvent::query()->where('client_id', $client->id)->where('type', 'status_critical')->count())->toBe(1);
});

it('updates and deletes health chart entries while keeping timeline projections in sync', function () {
    $user = User::factory()->create();
    $client = Client::factory()->create();
    grantClientProfilePhaseOnePermissions($user, [
        'clients.viewAny',
        'clients.update',
        'medications.view',
        'medications.administer.record',
    ]);

    $bowel = ClientBowelEntry::query()->create([
        'client_id' => $client->id,
        'occurred_at' => now()->subHour(),
        'bristol_type' => 3,
        'volume' => 'small',
        'notes' => 'Initial note.',
        'recorded_by' => $user->id,
    ]);
    $fluid = ClientFluidEntry::query()->create([
        'client_id' => $client->id,
        'occurred_at' => now()->subHour(),
        'direction' => 'in',
        'fluid_type' => 'water',
        'volume_ml' => 200,
        'recorded_by' => $user->id,
    ]);
    $seizure = ClientSeizureEntry::query()->create([
        'client_id' => $client->id,
        'occurred_at' => now()->subHour(),
        'duration_seconds' => 60,
        'seizure_type' => 'absence',
        'recorded_by' => $user->id,
    ]);

    $this->actingAs($user)
        ->put("/operations/clients/{$client->id}/health/bowel/{$bowel->id}", [
            'bristol_type' => 5,
            'volume' => 'large',
            'notes' => 'Updated note.',
        ])
        ->assertRedirect();
    $this->actingAs($user)
        ->put("/operations/clients/{$client->id}/health/fluid/{$fluid->id}", [
            'direction' => 'out',
            'fluid_type' => 'urine',
            'volume_ml' => 150,
        ])
        ->assertRedirect();
    $this->actingAs($user)
        ->put("/operations/clients/{$client->id}/health/seizure/{$seizure->id}", [
            'duration_seconds' => 360,
            'seizure_type' => 'tonic-clonic',
            'escalated' => true,
        ])
        ->assertRedirect();

    expect($bowel->fresh()->bristol_type)->toBe(5)
        ->and($fluid->fresh()->direction)->toBe('out')
        ->and($seizure->fresh()->escalated)->toBeTrue()
        ->and(TimelineEvent::query()->where('source_type', ClientBowelEntry::class)->where('source_id', $bowel->id)->where('meta->bristol_type', 5)->exists())->toBeTrue()
        ->and(TimelineEvent::query()->where('source_type', ClientFluidEntry::class)->where('source_id', $fluid->id)->where('meta->direction', 'out')->exists())->toBeTrue()
        ->and(TimelineEvent::query()->where('source_type', ClientSeizureEntry::class)->where('source_id', $seizure->id)->where('type', 'status_critical')->exists())->toBeTrue();

    $this->actingAs($user)
        ->delete("/operations/clients/{$client->id}/health/bowel/{$bowel->id}")
        ->assertRedirect();

    expect(ClientBowelEntry::withTrashed()->find($bowel->id)?->trashed())->toBeTrue()
        ->and(TimelineEvent::query()->where('source_type', ClientBowelEntry::class)->where('source_id', $bowel->id)->exists())->toBeFalse();
});

it('projects the remaining phase-one timeline sources through observers', function () {
    $user = User::factory()->create();
    $client = Client::factory()->create();

    $appointment = ClientAppointment::query()->create([
        'client_id' => $client->id,
        'title' => 'GP review',
        'appointment_type' => 'gp_visit',
        'starts_at' => now()->addDay(),
        'ends_at' => now()->addDay()->addHour(),
        'provider_name' => 'Dr Smith',
        'status' => 'scheduled',
        'created_by' => $user->id,
    ]);
    $assessment = ClientAssessment::query()->create([
        'client_id' => $client->id,
        'created_by_user_id' => $user->id,
        'type' => 'needs',
        'assessed_at' => now(),
        'next_review_at' => now()->addWeek(),
        'notes' => 'Review support needs next week.',
    ]);
    $document = ClientDocument::query()->create([
        'client_id' => $client->id,
        'uploaded_by_user_id' => $user->id,
        'title' => 'Medication authority',
        'category' => 'medical',
        'expiry_date' => now()->addDays(20),
        'storage_path' => 'client-documents/authority.pdf',
        'original_name' => 'authority.pdf',
    ]);
    $incident = ClientIncident::query()->create([
        'client_id' => $client->id,
        'reported_by' => $user->id,
        'type' => 'near_miss',
        'severity' => 'medium',
        'status' => 'draft',
        'occurred_at' => now()->subHour(),
        'description' => 'Trip risk noticed before outing.',
        'title' => 'Near miss incident',
    ]);

    expect(TimelineEvent::query()->where('source_type', ClientAppointment::class)->where('source_id', $appointment->id)->where('type', 'appointment')->exists())->toBeTrue()
        ->and(TimelineEvent::query()->where('source_type', ClientAssessment::class)->where('source_id', $assessment->id)->where('type', 'assessment_review_due')->exists())->toBeTrue()
        ->and(TimelineEvent::query()->where('source_type', ClientDocument::class)->where('source_id', $document->id)->where('type', 'document_expiring')->exists())->toBeTrue()
        ->and(TimelineEvent::query()->where('source_type', ClientIncident::class)->where('source_id', $incident->id)->where('type', 'incident')->count())->toBe(1);

    $document->delete();
    $incident->delete();

    expect(TimelineEvent::query()->where('source_type', ClientDocument::class)->where('source_id', $document->id)->exists())->toBeFalse()
        ->and(TimelineEvent::query()->where('source_type', ClientIncident::class)->where('source_id', $incident->id)->exists())->toBeFalse();
});

it('upserts routines and returns a schema-aware actions and reviews list', function () {
    $user = User::factory()->create();
    $client = Client::factory()->create();
    grantClientProfilePhaseOnePermissions($user, [
        'clients.viewAny',
        'clients.update',
        'progress_notes.viewAny',
        'progress_notes.review',
        'medications.view',
        'risks.viewAny',
        'care_plans.viewAny',
        'consents.viewAny',
    ]);

    $this->actingAs($user)
        ->post("/operations/clients/{$client->id}/routines/morning", [
            'body' => 'Offer breakfast before personal cares. Keep choices simple.',
        ])
        ->assertRedirect();

    expect(ClientRoutine::query()
        ->where('client_id', $client->id)
        ->where('time_block', 'morning')
        ->value('body'))->toBe('Offer breakfast before personal cares. Keep choices simple.')
        ->and(TimelineEvent::query()
            ->where('client_id', $client->id)
            ->where('type', 'routine_updated')
            ->exists())->toBeTrue();

    ClientNote::query()->create([
        'client_id' => $client->id,
        'user_id' => $user->id,
        'type' => 'daily_note',
        'category' => 'concern',
        'subject' => 'Follow-up',
        'body' => 'Call GP if symptoms continue.',
        'is_flagged' => true,
        'follow_up_action' => 'Call GP',
        'follow_up_due_at' => now()->subDay(),
    ]);
    ClientDocument::query()->create([
        'client_id' => $client->id,
        'uploaded_by_user_id' => $user->id,
        'title' => 'Medication authority',
        'category' => 'medical',
        'expiry_date' => now()->addDays(10),
        'storage_path' => 'client-documents/authority.pdf',
        'original_name' => 'authority.pdf',
    ]);
    ClientRisk::query()->create([
        'client_id' => $client->id,
        'label' => 'Community access',
        'severity' => 'medium',
        'controls' => 'Two staff for unfamiliar routes.',
        'review_date' => now()->subDay(),
        'active' => true,
    ]);
    CarePlan::query()->create([
        'client_id' => $client->id,
        'title' => 'Active plan',
        'status' => 'active',
        'plan_type' => 'support',
        'next_review_at' => now()->addDays(5),
    ]);
    ClientAssessment::query()->create([
        'client_id' => $client->id,
        'created_by_user_id' => $user->id,
        'type' => 'needs',
        'next_review_at' => now()->addDays(3),
    ]);

    $items = app(ActionsAggregator::class)->forClient($client, $user);
    $types = collect($items)->pluck('type')->all();

    expect($types)->toContain(
        'overdue_follow_up',
        'flagged_note_review',
        'document_expiring',
        'risk_review_due',
        'care_plan_review_due',
        'assessment_due',
    );
});
