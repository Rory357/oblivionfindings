<?php

use App\Models\Client;
use App\Models\ClientIncident;
use App\Models\HsCorrectiveAction;
use App\Models\HsEvent;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use App\Services\HealthSafety\HsEventService;
use App\Services\Incidents\IncidentJourneyReconciler;
use Illuminate\Support\Facades\DB;

function tidyFixture(): array
{
    $site = Site::factory()->create(['tenant_id' => 1]);
    $client = Client::factory()->create(['organization_id' => 1, 'site_id' => $site->id]);
    $reporter = User::factory()->create(['organization_id' => 1, 'approved_at' => now()]);
    $incident = fn (array $attributes, bool $submitted = true) => ClientIncident::withoutEvents(fn () => ($submitted
        ? ClientIncident::factory()->submitted()->atSite($site)
        : ClientIncident::factory()->atSite($site))->create(['client_id' => $client->id, 'reported_by' => $reporter->id,
            // The handover can only be accepted for a submitted incident with its submit time (HsEventService::acceptHandover).
            'submitted_at' => $submitted ? now()->subHour() : null, ...$attributes]));
    $event = fn (ClientIncident $source, array $attributes = []) => HsEvent::factory()->forClientIncident($source)->create([
        'organization_id' => 1, 'site_id' => $site->id, 'client_id' => $client->id, 'status' => HsEvent::STATUS_OPEN,
        'handover_status' => HsEvent::HANDOVER_NOT_REQUIRED, 'owner_user_id' => null, ...$attributes]);

    return compact('site', 'client', 'reporter', 'incident', 'event');
}

function tidyUserWithRole(string $role): User
{
    // The kept test databases start from the schema dump without roles; seed them when this test needs one.
    if (! Role::where('name', $role)->exists()) {
        test()->seed(\Database\Seeders\RbacSeeder::class);
    }
    $user = User::factory()->create(['organization_id' => 1, 'approved_at' => now(), 'role' => $role]);
    $user->roles()->sync([Role::where('name', $role)->sole()->id]);

    return $user;
}

it('lists everything on a dry run and writes nothing', function () {
    $f = tidyFixture();
    $submitted = ($f['incident'])(['description' => 'Resident fell in the lounge [H&S-DEMO-SEED]']);
    $stuck = ($f['event'])($submitted);
    $submitted->forceFill(['hs_event_id' => $stuck->id])->saveQuietly();

    $this->artisan('health-safety:tidy-demo-data', ['--dry-run' => true])
        ->expectsOutputToContain('DRY RUN')
        ->expectsOutputToContain('1. Marker: 1 rows')
        ->expectsOutputToContain('4. Stuck handovers: 1 → awaiting acceptance')
        ->assertSuccessful();

    expect($submitted->fresh()->description)->toContain('[H&S-DEMO-SEED]')
        ->and($stuck->fresh()->handover_status)->toBe(HsEvent::HANDOVER_NOT_REQUIRED);
});

it('refuses to apply without a manifest or with an action owner who is not an H&S lead', function () {
    $this->artisan('health-safety:tidy-demo-data')->assertFailed();
    $family = tidyUserWithRole('next_of_kin');
    $this->artisan('health-safety:tidy-demo-data', ['--dry-run' => true, '--action-owner' => $family->id])->assertFailed();
});

it('repairs stuck handovers, removes orphan reviews only when empty, strips the marker, replaces test text and moves family-owned actions', function () {
    $f = tidyFixture();
    // Submitted and linked: back to awaiting acceptance, then the normal handover works.
    $submitted = ($f['incident'])(['description' => 'Resident fell in the lounge [H&S-DEMO-SEED]']);
    $stuck = ($f['event'])($submitted);
    $submitted->forceFill(['hs_event_id' => $stuck->id])->saveQuietly();
    // Draft, not linked: an orphan review, soft-deleted.
    $draft = ($f['incident'])(['description' => 'Seed draft incident (editable until submitted).', 'type' => 'fall'], false);
    $orphan = ($f['event'])($draft);
    // Draft, not linked, but with an action: listed for a person, not deleted.
    $draftWithWork = ($f['incident'])(['description' => 'Draft with work'], false);
    $orphanWithWork = ($f['event'])($draftWithWork);
    $family = tidyUserWithRole('next_of_kin');
    $familyAction = HsCorrectiveAction::factory()->create(['hs_event_id' => $orphanWithWork->id, 'status' => HsCorrectiveAction::STATUS_OPEN,
        'assigned_to_user_id' => $family->id, 'title' => 'dfghdfgh', 'description' => 'dfghdfgh']);
    // A closed review and a non-incident review stay as they are.
    $closedSource = ($f['incident'])([]);
    $closed = ($f['event'])($closedSource, ['status' => HsEvent::STATUS_CLOSED]);
    $closedSource->forceFill(['hs_event_id' => $closed->id])->saveQuietly();
    $officer = tidyUserWithRole('health_safety_officer');
    $manifest = tempnam(sys_get_temp_dir(), 'tidy');

    $this->artisan('health-safety:tidy-demo-data', ['--action-owner' => $officer->id, '--manifest' => $manifest])->assertSuccessful();

    expect($stuck->fresh()->handover_status)->toBe(HsEvent::HANDOVER_AWAITING_ACCEPTANCE)
        ->and($stuck->fresh()->accepted_by_user_id)->toBeNull()
        ->and(HsEvent::withTrashed()->find($orphan->id)->trashed())->toBeTrue()
        ->and($orphanWithWork->fresh()->trashed())->toBeFalse()
        ->and($orphanWithWork->fresh()->handover_status)->toBe(HsEvent::HANDOVER_NOT_REQUIRED)
        ->and($closed->fresh()->handover_status)->toBe(HsEvent::HANDOVER_NOT_REQUIRED)
        ->and($submitted->fresh()->description)->toBe('Resident fell in the lounge')
        ->and($draft->fresh()->description)->not->toContain('Seed draft')
        ->and($familyAction->fresh()->assigned_to_user_id)->toBe($officer->id)
        ->and($familyAction->fresh()->title)->toBe('Check the handrail on the front steps');

    $saved = json_decode(file_get_contents($manifest), true);
    expect($saved['plan']['handover']['await'][0]['id'])->toBe($stuck->id)
        ->and($saved['plan']['handover']['list'][0]['id'])->toBe($orphanWithWork->id);
    @unlink($manifest);

    app(HsEventService::class)->acceptHandover($stuck->fresh(), $officer);
    expect($stuck->fresh()->handover_status)->toBe(HsEvent::HANDOVER_ACCEPTED);
});

it('K8: a review recorded from an incident never starts on "handover not required"', function () {
    $f = tidyFixture();
    $source = ($f['incident'])([]);

    expect(HsEventService::initialHandoverStatus($source, null))->toBe(HsEvent::HANDOVER_AWAITING_ACCEPTANCE)
        ->and(HsEventService::initialHandoverStatus($source, HsEvent::HANDOVER_NOT_REQUIRED))->toBe(HsEvent::HANDOVER_AWAITING_ACCEPTANCE)
        ->and(HsEventService::initialHandoverStatus($source, HsEvent::HANDOVER_ACCEPTED))->toBe(HsEvent::HANDOVER_ACCEPTED)
        ->and(HsEventService::initialHandoverStatus($f['site'], null))->toBe(HsEvent::HANDOVER_NOT_REQUIRED);

    $event = app(HsEventService::class)->recordEvent(['source' => $source, 'event_category' => HsEvent::CATEGORY_INCIDENT,
        'severity' => HsEvent::SEVERITY_LOW, 'site_id' => $f['site']->id, 'client_id' => $f['client']->id, 'created_by' => $f['reporter']->id]);
    expect($event->handover_status)->toBe(HsEvent::HANDOVER_AWAITING_ACCEPTANCE);
});

it('K8: the journey reconciler reports and repairs an open incident review left on "not required"', function () {
    $f = tidyFixture();
    $source = ($f['incident'])([]);
    $stuck = ($f['event'])($source);
    DB::table('client_incidents')->where('id', $source->id)->update(['hs_event_id' => $stuck->id]);

    $dry = app(IncidentJourneyReconciler::class)->reconcile();
    expect($dry->issues['handover_not_required'])->toBe(1)
        ->and($stuck->fresh()->handover_status)->toBe(HsEvent::HANDOVER_NOT_REQUIRED);

    $applied = app(IncidentJourneyReconciler::class)->reconcile(true);
    expect($applied->repairs['handover_not_required'])->toBe(1)
        ->and($stuck->fresh()->handover_status)->toBe(HsEvent::HANDOVER_AWAITING_ACCEPTANCE)
        ->and($stuck->fresh()->accepted_by_user_id)->toBeNull();
});
