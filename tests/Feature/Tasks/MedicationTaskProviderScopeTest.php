<?php

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Models\Client;
use App\Models\ClientBreakGlassAccess;
use App\Models\ClientMedication;
use App\Models\MedicationDowntime;
use App\Models\MedicationError;
use App\Models\MedicationErrorAction;
use App\Models\MedicationPaperEntry;
use App\Models\MedicationReview;
use App\Models\MedicationReviewItem;
use App\Models\Permission;
use App\Models\Site;
use App\Models\User;
use App\Services\Tasks\Providers\MedicationEmergencyAccessReviewProvider;
use App\Services\Tasks\Providers\MedicationErrorActionProvider;
use App\Services\Tasks\Providers\MedicationPaperGiverConfirmationProvider;
use App\Services\Tasks\Providers\MedicationPaperWitnessConfirmationProvider;
use App\Services\Tasks\Providers\MedicationReviewChangeProvider;
use App\Services\Tasks\Providers\MedicationReviewProvider;
use App\Services\Tasks\TaskAggregator;
use Database\Seeders\RbacSeeder;
use Illuminate\Support\Str;

beforeEach(function () {
    $this->seed(RbacSeeder::class);
});

function medicationTaskGrant(User $actor, array $keys, bool $allowed = true): void
{
    foreach ($keys as $key) {
        $permission = Permission::query()->firstOrCreate(['key' => $key], ['description' => $key, 'group' => 'medications']);
        $actor->permissionOverrides()->syncWithoutDetaching([$permission->id => ['allowed' => $allowed]]);
    }
    $actor->unsetRelation('permissionOverrides')->unsetRelation('roles');
}

function medicationTaskActor(Site $site): User
{
    $actor = User::factory()->frontlineWorker()->create();
    HrEmployeeProfile::factory()->create([
        'user_id' => $actor->id, 'primary_site_id' => $site->id, 'secondary_site_ids' => [],
        'start_date' => today()->subYear(), 'end_date' => null, 'is_active' => true,
    ]);
    medicationTaskGrant($actor, ['medications.view', 'clients.viewAssigned']);
    medicationTaskGrant($actor, [
        'clients.viewAny', 'clinical.accessAllSites', 'sites.viewAll', 'medications.audit.view',
        'medications.stock.update', 'medications.reports.view', 'medications.reports.export',
        'medications.controlled.view',
    ], false);

    return $actor;
}

function medicationTaskOrder(Client $client): ClientMedication
{
    return ClientMedication::query()->create([
        'client_id' => $client->id, 'name' => 'Synthetic ordinary medicine', 'dosage' => '1 tablet',
        'frequency' => 'Daily', 'dose_times' => ['09:00'], 'controlled_drug' => false,
        'is_prn' => false, 'active' => true, 'state' => 'active', 'approval_status' => 'verified',
    ]);
}

/** Read-only fixtures: no confirmation, paper posting or clinical writer is invoked. */
function medicationTaskWork(string $source, Client $client, User $actor, User $colleague): object
{
    if ($source === 'med_error_action') {
        $error = MedicationError::withoutEvents(fn () => MedicationError::query()->create([
            'reference_number' => 'TASK-'.Str::upper(Str::random(12)), 'client_id' => $client->id,
            'client_medication_id' => medicationTaskOrder($client)->id, 'error_type' => 'wrong_time',
            'severity' => 'minor', 'description' => 'Private investigation evidence',
            'reported_by' => $actor->id, 'reported_at' => now(), 'status' => 'reported',
        ]));

        return MedicationErrorAction::query()->create([
            'medication_error_id' => $error->id, 'owner_id' => $actor->id,
            'description' => 'Private investigation action', 'due_at' => now()->addDay(),
            'created_by' => $colleague->id, 'created_at' => now(),
        ]);
    }
    if (in_array($source, ['medication_review', 'medication_review_change'], true)) {
        $review = MedicationReview::query()->create([
            'client_id' => $client->id, 'owner_id' => $actor->id, 'review_type' => 'routine',
            'status' => $source === 'medication_review' ? 'scheduled' : 'completed',
            'scheduled_date' => today()->addDay(), 'requested_by' => $colleague->id,
        ]);
        if ($source === 'medication_review') {
            return $review;
        }

        return MedicationReviewItem::query()->create([
            'review_id' => $review->id, 'client_id' => $client->id,
            'client_medication_id' => medicationTaskOrder($client)->id,
            'name_snapshot' => 'Synthetic ordinary medicine', 'outcome' => 'change', 'decision' => 'agreed',
            'controlled_snapshot' => false, 'classification_pending' => false,
        ]);
    }

    $downtime = MedicationDowntime::query()->create([
        'site_id' => $client->site_id, 'created_by' => $colleague->id,
        'started_at' => now()->subHours(2), 'ended_at' => now()->subHour(),
        'description' => 'Synthetic read-only downtime evidence',
        'request_uuid' => (string) Str::uuid(), 'request_fingerprint' => hash('sha256', (string) Str::uuid()),
    ]);

    return MedicationPaperEntry::query()->create([
        'downtime_id' => $downtime->id, 'client_id' => $client->id,
        'client_medication_id' => medicationTaskOrder($client)->id, 'entered_by' => $colleague->id,
        'given_by' => $source === 'med_paper_giver' ? $actor->id : $colleague->id,
        'witness_id' => $source === 'med_paper_witness' ? $actor->id : null,
        'given_at' => now()->subMinutes(90), 'outcome' => 'given', 'dose_on_paper' => '1 tablet',
        'observations' => [], 'snapshot' => ['person' => $client->full_name, 'controlled' => false],
        'request_uuid' => (string) Str::uuid(), 'request_fingerprint' => hash('sha256', (string) Str::uuid()),
        'dose_identity' => 'task-scope:'.Str::uuid(),
    ]);
}

it('conceals foreign sites and unassigned people in each person-scoped medication task source', function (string $providerClass, string $source, string $permission) {
    $site = Site::factory()->create(['is_active' => true, 'archived' => false]);
    $foreignSite = Site::factory()->create(['is_active' => true, 'archived' => false]);
    $actor = medicationTaskActor($site);
    $colleague = User::factory()->frontlineWorker()->create();
    medicationTaskGrant($actor, [$permission]);
    $assigned = Client::factory()->create(['site_id' => $site->id, 'status' => 'active']);
    $unassigned = Client::factory()->create(['site_id' => $site->id, 'status' => 'active']);
    $foreign = Client::factory()->create(['site_id' => $foreignSite->id, 'status' => 'active']);
    $assigned->supportWorkers()->attach($actor->id);
    // Assignment alone cannot cross the actor's current approved Site.
    $foreign->supportWorkers()->attach($actor->id);
    $visible = medicationTaskWork($source, $assigned, $actor, $colleague);
    $hiddenPerson = medicationTaskWork($source, $unassigned, $actor, $colleague);
    $hiddenSite = medicationTaskWork($source, $foreign, $actor, $colleague);
    $provider = app($providerClass);
    $before = $visible->fresh()->getRawOriginal();

    expect(collect($provider->authorizedTasks($actor))->pluck('id')->all())->toBe([$source.'-'.$visible->id]);
    foreach ([$hiddenPerson, $hiddenSite] as $hidden) {
        expect($provider->authorizedTasks($actor, ['id' => $hidden->id]))->toBe([]);
        $this->actingAs($actor)->getJson(route('tasks.detail', ['source' => $source, 'id' => $hidden->id]))->assertNotFound();
    }
    expect((new TaskAggregator)->findItemFor($actor, $source, $visible->id)?->id)->toBe($source.'-'.$visible->id);
    if ($source === 'med_error_action') {
        expect($provider->authorizedTasks($actor)[0]->description)->not->toContain('Private investigation action');
    }

    expect($visible->fresh()->getRawOriginal())->toBe($before);
    $this->assertDatabaseCount('medication_paper_postings', 0);
    $this->assertDatabaseCount('client_medication_administrations', 0);
    if ($permission !== 'medications.view') {
        medicationTaskGrant($actor, [$permission], false);
        expect($provider->authorizedTasks($actor))->toBe([]);
        medicationTaskGrant($actor, [$permission]);
    }

    medicationTaskGrant($actor, ['medications.view'], false);
    expect($provider->authorizedTasks($actor))->toBe([]);
    $this->actingAs($actor)->getJson(route('tasks.detail', ['source' => $source, 'id' => $visible->id]))->assertNotFound();
})->with([
    'error actions' => [MedicationErrorActionProvider::class, 'med_error_action', 'medications.view'],
    'scheduled reviews' => [MedicationReviewProvider::class, 'medication_review', 'medications.reviews.manage'],
    'review changes' => [MedicationReviewChangeProvider::class, 'medication_review_change', 'medications.reviews.manage'],
    'paper giver' => [MedicationPaperGiverConfirmationProvider::class, 'med_paper_giver', 'medications.administer.record'],
    'paper witness' => [MedicationPaperWitnessConfirmationProvider::class, 'med_paper_witness', 'medications.administer.record'],
]);

it('requires exact independent audit authority and approved sites for emergency review tasks', function () {
    $site = Site::factory()->create(['is_active' => true, 'archived' => false]);
    $foreignSite = Site::factory()->create(['is_active' => true, 'archived' => false]);
    $archivedSite = Site::factory()->create(['is_active' => true, 'archived' => true, 'archived_at' => now()]);
    $actor = medicationTaskActor($site);
    $grantOwner = User::factory()->frontlineWorker()->create();
    $person = Client::factory()->create(['site_id' => $site->id]);
    $foreignPerson = Client::factory()->create(['site_id' => $foreignSite->id]);
    $archivedPerson = Client::factory()->create(['site_id' => $archivedSite->id]);
    $make = fn (Client $client, array $overrides = []) => ClientBreakGlassAccess::query()->create(array_merge([
        'client_id' => $client->id, 'user_id' => $grantOwner->id, 'reason' => 'Synthetic ended evidence',
        'expires_at' => now()->subDays(3), 'ended_at' => now()->subDays(3), 'review_due_at' => now()->subDay(),
        'acknowledged_min_necessary' => true, 'acknowledged_incident_report' => true,
    ], $overrides));
    $visible = $make($person);
    $foreign = $make($foreignPerson);
    $archived = $make($archivedPerson);
    $own = $make($person, ['user_id' => $actor->id]);
    $cosigned = $make($person, ['co_signed_by' => $actor->id, 'authorization_mode' => 'co_sign']);
    $reviewed = $make($person, ['review_outcome' => 'appropriate']);
    $provider = app(MedicationEmergencyAccessReviewProvider::class);

    expect($provider->authorizedTasks($actor))->toBe([]);
    medicationTaskGrant($actor, ['medications.audit.view']);
    // This exact audit permission deliberately covers all active approved Sites
    // and people; the auditor's employment Site and support assignments do not
    // narrow that independent audit boundary.
    expect(collect($provider->authorizedTasks($actor))->pluck('id')->sort()->values()->all())
        ->toBe(collect([$visible, $foreign])->map(fn ($grant) => 'med_emergency_review-'.$grant->id)->sort()->values()->all());
    foreach ([$archived, $own, $cosigned, $reviewed] as $hidden) {
        expect($provider->authorizedTasks($actor, ['id' => $hidden->id]))->toBe([]);
        $this->actingAs($actor)->getJson(route('tasks.detail', ['source' => 'med_emergency_review', 'id' => $hidden->id]))->assertNotFound();
    }
    $actor->forceFill(['approved_at' => null])->save();
    expect($provider->authorizedTasks($actor))->toBe([]);
});
