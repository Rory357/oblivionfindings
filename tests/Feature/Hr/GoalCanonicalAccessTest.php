<?php

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Domain\Hr\Models\HrGoal;
use App\Domain\Hr\Models\HrGoalCycle;
use App\Domain\Hr\Models\HrKeyResult;
use App\Domain\Hr\Notifications\GoalCheckinDueNotification;
use App\Domain\Hr\Notifications\GoalOverdueNotification;
use App\Domain\Hr\Notifications\GoalWeeklyDigestNotification;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Database\Seeders\SeedHrPermissionsSeeder;
use Illuminate\Support\Facades\Notification;

beforeEach(function (): void {
    $this->seed(RbacSeeder::class);
    $this->seed(SeedHrPermissionsSeeder::class);
    Notification::fake();

    $this->site = Site::factory()->create(['name' => 'Canonical Goals Site']);
    $this->hiddenSite = Site::factory()->create(['name' => 'Hidden Goals Site']);
    $this->manager = goalCanonicalStaff('Canonical Goals Manager', $this->site, 'hr');
    $this->manager->roles()->syncWithoutDetaching([
        Role::query()->where('name', 'hr')->firstOrFail()->id,
    ]);
    $this->owner = goalCanonicalStaff('Canonical Goal Owner', $this->site, 'support_worker');
    $this->peer = goalCanonicalStaff('Canonical Goal Peer', $this->site, 'support_worker');
    $this->hiddenOwner = goalCanonicalStaff('Hidden Goal Owner', $this->hiddenSite, 'support_worker');
    $this->formerOwner = goalCanonicalStaff('Former Goal Owner', $this->site, 'support_worker');
    $this->formerOwner->hrEmployeeProfile->update([
        'is_active' => false,
        'end_date' => today()->subDay(),
    ]);
});

function goalCanonicalStaff(string $name, Site $site, string $role): User
{
    $user = User::factory()->create([
        'name' => $name,
        'email' => str($name)->slug().'-'.str()->random(6).'@example.test',
        'role' => $role,
        'approved_at' => now(),
    ]);
    HrEmployeeProfile::factory()->create([
        'user_id' => $user->id,
        'primary_site_id' => $site->id,
        'secondary_site_ids' => [],
        'start_date' => today()->subYear(),
        'end_date' => null,
        'is_active' => true,
    ]);

    return $user->fresh('hrEmployeeProfile');
}

function goalCanonicalObjective(User $owner, User $manager, array $overrides = []): HrGoal
{
    return HrGoal::query()->create([
        'user_id' => $owner->id,
        'created_by' => $manager->id,
        'title' => 'Canonical objective '.str()->random(6),
        'goal_type' => 'individual',
        'priority' => 'medium',
        'status' => 'active',
        'confidence' => 'on_track',
        'start_date' => '2026-07-01',
        'due_date' => '2026-12-31',
        ...$overrides,
    ]);
}

function goalCanonicalCycle(string $name, array $overrides = []): HrGoalCycle
{
    return HrGoalCycle::query()->create([
        'name' => $name,
        'type' => 'quarter',
        'starts_at' => '2027-01-01',
        'ends_at' => '2027-03-31',
        'status' => 'active',
        ...$overrides,
    ]);
}

test('the goals hub uses retained Site visibility and only current Site-visible picker options', function (): void {
    $visible = goalCanonicalObjective($this->owner, $this->manager, ['title' => 'Visible objective']);
    $former = goalCanonicalObjective($this->formerOwner, $this->manager, ['title' => 'Retained former objective']);
    $hidden = goalCanonicalObjective($this->hiddenOwner, $this->manager, ['title' => 'Hidden objective']);

    $response = $this->actingAs($this->manager)->get('/hr/goals?cycle=all');
    $response->assertOk();

    $objectiveIds = collect($response->inertiaProps('objectives'))->pluck('id');
    $pickerIds = collect($response->inertiaProps('users'))->pluck('id');

    expect($objectiveIds)
        ->toContain($visible->id, $former->id)
        ->not->toContain($hidden->id)
        ->and($pickerIds)
        ->toContain($this->owner->id)
        ->not->toContain($this->hiddenOwner->id, $this->formerOwner->id);

    $this->actingAs($this->manager)->get('/hr/goals/'.$former->id)->assertOk();
    $this->actingAs($this->manager)->get('/hr/goals/'.$hidden->id)->assertNotFound();
});

test('hidden and former owners and hidden parent objectives fail closed on assignment', function (): void {
    $hiddenParent = goalCanonicalObjective($this->hiddenOwner, $this->manager, [
        'title' => 'Hidden parent objective',
        'goal_type' => 'company',
    ]);

    foreach ([$this->hiddenOwner, $this->formerOwner] as $owner) {
        $this->actingAs($this->manager)
            ->post('/hr/goals', [
                'user_id' => $owner->id,
                'title' => 'Rejected owner objective',
                'goal_type' => 'individual',
                'priority' => 'medium',
                'start_date' => '2026-07-01',
                'due_date' => '2026-12-31',
            ])
            ->assertNotFound();
    }

    $this->actingAs($this->manager)
        ->post('/hr/goals', [
            'user_id' => $this->owner->id,
            'title' => 'Rejected parent objective',
            'goal_type' => 'individual',
            'priority' => 'medium',
            'parent_goal_id' => $hiddenParent->id,
            'start_date' => '2026-07-01',
            'due_date' => '2026-12-31',
        ])
        ->assertNotFound();

    expect(HrGoal::query()->where('title', 'like', 'Rejected%')->exists())->toBeFalse();
});

test('hidden direct objects and mixed bulk selections are concealed before mutation', function (): void {
    $visible = goalCanonicalObjective($this->owner, $this->manager, [
        'title' => 'Visible bulk objective',
    ]);
    $hidden = goalCanonicalObjective($this->hiddenOwner, $this->manager, [
        'title' => 'Hidden bulk objective',
    ]);
    $hiddenKeyResult = HrKeyResult::query()->create([
        'goal_id' => $hidden->id,
        'title' => 'Hidden key result',
        'target_value' => 100,
        'current_value' => 0,
        'status' => 'not_started',
        'owner_id' => $this->hiddenOwner->id,
    ]);

    $this->actingAs($this->manager)
        ->put('/hr/goals/'.$hidden->id, ['title' => str_repeat('x', 500)])
        ->assertNotFound();
    $this->actingAs($this->manager)
        ->put('/hr/goals/key-results/'.$hiddenKeyResult->id, ['current_value' => 50])
        ->assertNotFound();
    $this->actingAs($this->manager)
        ->delete('/hr/goals/key-results/'.$hiddenKeyResult->id)
        ->assertNotFound();
    $this->actingAs($this->manager)
        ->post('/hr/goals/bulk', [
            'action' => 'archive',
            'ids' => [$visible->id, $hidden->id],
        ])
        ->assertNotFound();

    expect($visible->fresh()->status)->toBe('active')
        ->and($hidden->fresh()->title)->toBe('Hidden bulk objective')
        ->and($hiddenKeyResult->fresh())->not->toBeNull();
});

test('key result ownership uses current Site-visible staff', function (): void {
    $goal = goalCanonicalObjective($this->owner, $this->manager);

    foreach ([$this->hiddenOwner, $this->formerOwner] as $owner) {
        $this->actingAs($this->manager)
            ->post('/hr/goals/'.$goal->id.'/key-results', [
                'title' => 'Rejected key result',
                'target_value' => 100,
                'owner_id' => $owner->id,
            ])
            ->assertNotFound();
    }

    expect(HrKeyResult::query()->where('title', 'Rejected key result')->exists())->toBeFalse();
});

test('only the exact current owner or a Site-visible manager can check in', function (): void {
    $goal = goalCanonicalObjective($this->owner, $this->manager, ['title' => 'Owner check-in objective']);

    $this->actingAs($this->peer)
        ->post('/hr/my/goals/'.$goal->id.'/checkin', [
            'confidence' => 'on_track',
            'manual_progress' => 25,
        ])
        ->assertNotFound();

    $this->actingAs($this->owner)
        ->post('/hr/my/goals/'.$goal->id.'/checkin', [
            'confidence' => 'at_risk',
            'manual_progress' => 35,
            'comment' => 'Owner update',
        ])
        ->assertRedirect();
    expect($goal->fresh()->progress_percentage)->toBe(35);

    $this->owner->hrEmployeeProfile->update([
        'is_active' => false,
        'end_date' => today()->subDay(),
    ]);
    $this->actingAs($this->owner)
        ->post('/hr/my/goals/'.$goal->id.'/checkin', [
            'confidence' => 'on_track',
            'manual_progress' => 60,
        ])
        ->assertNotFound();
    $this->actingAs($this->manager)
        ->put('/hr/goals/'.$goal->id, ['title' => 'Former owner mutation'])
        ->assertNotFound();

    expect($goal->fresh()->progress_percentage)->toBe(35)
        ->and($goal->fresh()->title)->toBe('Owner check-in objective');
});

test('a check-in rejects key results belonging to another objective', function (): void {
    $goal = goalCanonicalObjective($this->owner, $this->manager, ['title' => 'Protected check-in objective']);
    $otherGoal = goalCanonicalObjective($this->owner, $this->manager, ['title' => 'Other objective']);
    HrKeyResult::query()->create([
        'goal_id' => $goal->id,
        'title' => 'Own key result',
        'target_value' => 100,
        'current_value' => 0,
        'status' => 'not_started',
    ]);
    $otherKeyResult = HrKeyResult::query()->create([
        'goal_id' => $otherGoal->id,
        'title' => 'Other key result',
        'target_value' => 100,
        'current_value' => 0,
        'status' => 'not_started',
    ]);

    $this->actingAs($this->owner)
        ->post('/hr/my/goals/'.$goal->id.'/checkin', [
            'confidence' => 'on_track',
            'key_results' => [[
                'id' => $otherKeyResult->id,
                'current_value' => 50,
            ]],
        ])
        ->assertStatus(422);

    expect((float) $otherKeyResult->fresh()->current_value)->toBe(0.0)
        ->and((int) $goal->fresh()->progress_percentage)->toBe(0);
});

test('cycle rollover requires an exact source-cycle and Site-visible selection', function (): void {
    $source = goalCanonicalCycle('Canonical source '.str()->random(6));
    $target = goalCanonicalCycle('Canonical target '.str()->random(6), [
        'starts_at' => '2027-04-01',
        'ends_at' => '2027-06-30',
        'status' => 'upcoming',
    ]);
    $otherSource = goalCanonicalCycle('Canonical other '.str()->random(6), [
        'starts_at' => '2027-07-01',
        'ends_at' => '2027-09-30',
    ]);
    $visible = goalCanonicalObjective($this->owner, $this->manager, [
        'title' => 'Visible rollover objective',
        'cycle_id' => $source->id,
    ]);
    $hidden = goalCanonicalObjective($this->hiddenOwner, $this->manager, [
        'title' => 'Hidden rollover objective',
        'cycle_id' => $source->id,
    ]);
    $wrongSource = goalCanonicalObjective($this->owner, $this->manager, [
        'title' => 'Wrong source objective',
        'cycle_id' => $otherSource->id,
    ]);
    HrKeyResult::query()->create([
        'goal_id' => $visible->id,
        'title' => 'Rollover key result',
        'start_value' => 10,
        'current_value' => 40,
        'target_value' => 100,
        'progress_percentage' => 33,
        'status' => 'in_progress',
    ]);

    $this->actingAs($this->manager)
        ->post('/hr/goals/cycles/'.$source->id.'/rollover', [
            'target_cycle_id' => $target->id,
            'goal_ids' => [$visible->id, $hidden->id],
        ])
        ->assertNotFound();
    $this->actingAs($this->manager)
        ->post('/hr/goals/cycles/'.$source->id.'/rollover', [
            'target_cycle_id' => $target->id,
            'goal_ids' => [$wrongSource->id],
        ])
        ->assertNotFound();
    expect(HrGoal::query()->where('cycle_id', $target->id)->exists())->toBeFalse();

    $this->actingAs($this->manager)
        ->post('/hr/goals/cycles/'.$source->id.'/rollover', [
            'target_cycle_id' => $target->id,
            'goal_ids' => [$visible->id],
            'with_key_results' => true,
        ])
        ->assertRedirect();

    $clone = HrGoal::query()
        ->where('title', 'Visible rollover objective')
        ->where('cycle_id', $target->id)
        ->with('keyResults')
        ->firstOrFail();
    expect($clone->status)->toBe('draft')
        ->and((int) $clone->progress_percentage)->toBe(0)
        ->and($clone->keyResults)->toHaveCount(1)
        ->and((float) $clone->keyResults->first()->current_value)->toBe(10.0);
});

test('closing an application cycle cannot mutate current goals outside approved Sites', function (): void {
    $cycle = goalCanonicalCycle('Protected close '.str()->random(6));
    $visible = goalCanonicalObjective($this->owner, $this->manager, [
        'cycle_id' => $cycle->id,
        'progress_percentage' => 100,
    ]);
    $hidden = goalCanonicalObjective($this->hiddenOwner, $this->manager, [
        'cycle_id' => $cycle->id,
        'progress_percentage' => 100,
    ]);

    $this->actingAs($this->manager)
        ->post('/hr/goals/cycles/'.$cycle->id.'/close')
        ->assertForbidden();

    expect($cycle->fresh()->status)->toBe('active')
        ->and($visible->fresh()->status)->toBe('active')
        ->and($hidden->fresh()->status)->toBe('active');
});

test('closing a fully visible application cycle completes eligible objectives', function (): void {
    $cycle = goalCanonicalCycle('Visible close '.str()->random(6));
    $completed = goalCanonicalObjective($this->owner, $this->manager, [
        'cycle_id' => $cycle->id,
        'progress_percentage' => 100,
    ]);
    $open = goalCanonicalObjective($this->peer, $this->manager, [
        'cycle_id' => $cycle->id,
        'progress_percentage' => 75,
    ]);

    $this->actingAs($this->manager)
        ->from('/hr/goals')
        ->post('/hr/goals/cycles/'.$cycle->id.'/close')
        ->assertRedirect('/hr/goals');

    expect($cycle->fresh()->status)->toBe('closed')
        ->and($completed->fresh()->status)->toBe('completed')
        ->and($completed->fresh()->completed_at)->not->toBeNull()
        ->and($open->fresh()->status)->toBe('active');
});

test('goal reminders and weekly digests exclude former staff', function (): void {
    goalCanonicalObjective($this->owner, $this->manager, [
        'title' => 'Current owner reminder',
        'checkin_frequency' => 'weekly',
        'last_checkin_at' => null,
        'due_date' => today()->subDay()->toDateString(),
    ]);
    goalCanonicalObjective($this->formerOwner, $this->manager, [
        'title' => 'Former owner reminder',
        'checkin_frequency' => 'weekly',
        'last_checkin_at' => null,
        'due_date' => today()->subDay()->toDateString(),
    ]);

    $this->artisan('hr:goal-reminders')->assertExitCode(0);
    $this->artisan('hr:goal-weekly-digest')->assertExitCode(0);

    Notification::assertSentTo($this->owner, GoalCheckinDueNotification::class);
    Notification::assertSentTo($this->owner, GoalOverdueNotification::class);
    Notification::assertSentTo($this->owner, GoalWeeklyDigestNotification::class);
    Notification::assertNotSentTo($this->formerOwner, GoalCheckinDueNotification::class);
    Notification::assertNotSentTo($this->formerOwner, GoalOverdueNotification::class);
    Notification::assertNotSentTo($this->formerOwner, GoalWeeklyDigestNotification::class);
});
