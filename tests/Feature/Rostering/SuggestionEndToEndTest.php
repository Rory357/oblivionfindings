<?php

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Models\Client;
use App\Models\Permission;
use App\Models\RosterSuggestion;
use App\Models\RosterSuggestionRun;
use App\Models\Shift;
use App\Models\Site;
use App\Models\User;
use Database\Seeders\OperationsPermissionsSeeder;
use Database\Seeders\RbacSeeder;
use Illuminate\Support\Carbon;

/*
 * End-to-end coverage for the manual-first auto-schedule pipeline over HTTP:
 * generate suggestions -> accept the top suggestion -> bulk apply accepted.
 * Unit tests (SuggestionApplierTest) cover the applier edge cases with the
 * eligibility boundary mocked; this test drives the real routes, permission
 * middleware, feature flag, controller org checks and the applier together.
 */

beforeEach(function () {
    $this->seed(RbacSeeder::class);
    $this->seed(OperationsPermissionsSeeder::class);
    config()->set('features.rostering.auto_schedule', true);

    $this->manager = User::factory()->create([
        'role' => 'coordinator',
        'approved_at' => now(),
    ]);
    $permission = Permission::query()->where('key', 'rostering.autoSchedule')->first();
    expect($permission)->not->toBeNull();
    $this->manager->permissionOverrides()->syncWithoutDetaching([
        $permission->id => ['allowed' => true],
    ]);
});

it('drives suggest, accept and apply end to end over HTTP', function () {
    $site = Site::factory()->create();
    assignSuggestionUserToSite($this->manager, $site);
    $client = Client::factory()->create([
        'site_id' => $site->id,
    ]);
    $candidateA = User::factory()->create(['approved_at' => now()]);
    $candidateB = User::factory()->create(['approved_at' => now()]);
    assignSuggestionUserToSite($candidateA, $site);
    assignSuggestionUserToSite($candidateB, $site);

    // Future week so the shift is actionable and generation runs synchronously.
    $weekStart = Carbon::parse(now()->addWeek()->startOfWeek()->toDateString(), 'Pacific/Auckland')->startOfDay();
    $shift = Shift::factory()->unassigned()->create([
        'client_id' => $client->id,
        'site_id' => $site->id,
        'starts_at' => $weekStart->copy()->setTime(9, 0)->utc(),
        'ends_at' => $weekStart->copy()->setTime(13, 0)->utc(),
        'status' => 'scheduled',
    ]);

    // 1. Generate.
    $response = $this->actingAs($this->manager)->post(route('operations.rostering.auto_schedule'), [
        'week' => $weekStart->toDateString(),
        'site_id' => $site->id,
    ]);

    $run = RosterSuggestionRun::query()->latest('id')->first();
    expect($run)->not->toBeNull()
        ->and($run->status)->toBe(RosterSuggestionRun::STATUS_COMPLETED);
    $response->assertRedirect(route('operations.rostering.suggestions.show', $run));

    $top = $run->suggestions()->where('shift_id', $shift->id)->orderBy('rank')->first();
    expect($top)->not->toBeNull();

    // 2. Accept the top suggestion.
    $this->actingAs($this->manager)
        ->post(route('operations.rostering.suggestions.accept', $top))
        ->assertRedirect();

    $top->refresh();
    expect($top->status)->toBe(RosterSuggestion::STATUS_ACCEPTED)
        ->and($top->accepted_by)->toBe($this->manager->id)
        ->and($top->accepted_at)->not->toBeNull();

    // 3. Bulk apply accepted.
    $this->actingAs($this->manager)
        ->post(route('operations.rostering.suggestions.apply_accepted', $run))
        ->assertRedirect();

    $top->refresh();
    expect($shift->fresh()->user_id)->toBe($top->candidate_user_id)
        ->and($top->status)->toBe(RosterSuggestion::STATUS_APPLIED)
        ->and($top->applied_by)->toBe($this->manager->id)
        ->and($top->applied_at)->not->toBeNull()
        // Attribution from the accept step survives the apply.
        ->and($top->accepted_by)->toBe($this->manager->id)
        ->and($top->accepted_at)->not->toBeNull();
});

it('denies a suggestion run outside the manager Site assignment', function () {
    $accessibleSite = Site::factory()->create();
    $outsideSite = Site::factory()->create();
    assignSuggestionUserToSite($this->manager, $accessibleSite);
    $outsideRun = RosterSuggestionRun::factory()->create(['site_id' => $outsideSite->id]);

    $this->actingAs($this->manager)
        ->get(route('operations.rostering.suggestions.show', $outsideRun))
        ->assertForbidden();
});

it('refuses suggestion actions without the autoSchedule permission', function () {
    $worker = User::factory()->create([
        'role' => 'support_worker',
        'approved_at' => now(),
    ]);
    $suggestion = RosterSuggestion::factory()->create([
        'roster_suggestion_run_id' => RosterSuggestionRun::factory()->create()->id,
        'shift_id' => Shift::factory()->unassigned()->create([
            'status' => 'scheduled',
        ])->id,
        'candidate_user_id' => User::factory()->create()->id,
        'rank' => 1,
        'status' => RosterSuggestion::STATUS_SUGGESTED,
    ]);

    $this->actingAs($worker)
        ->post(route('operations.rostering.suggestions.accept', $suggestion))
        ->assertForbidden();
});

function assignSuggestionUserToSite(User $user, Site $site): void
{
    HrEmployeeProfile::factory()->create([
        'user_id' => $user->id,
        'primary_site_id' => $site->id,
        'secondary_site_ids' => [],
        'start_date' => today()->subYear(),
        'end_date' => null,
        'is_active' => true,
    ]);
}
