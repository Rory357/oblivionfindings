<?php

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Domain\Hr\Models\HrKudos;
use App\Domain\Hr\Models\HrKudosReaction;
use App\Domain\Hr\Models\HrKudosReply;
use App\Domain\Hr\Models\HrSupervisionNote;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Database\Seeders\SeedHrPermissionsSeeder;

beforeEach(function () {
    $this->seed(RbacSeeder::class);
    $this->seed(SeedHrPermissionsSeeder::class);
    $this->site = Site::factory()->create(['name' => 'My HR Self Service Site']);

    $this->employee = User::factory()->create([
        'role' => 'support_worker',
        'approved_at' => now(),
    ]);
    HrEmployeeProfile::query()->create([
        'user_id' => $this->employee->id,
        'employee_number' => 'EMP-SS-'.$this->employee->id,
        'work_email' => 'ss'.$this->employee->id.'@example.test',
        'position_title' => 'Support Worker',
        'position_role' => 'support_worker',
        'primary_site_id' => $this->site->id,
        'secondary_site_ids' => [],
        'employment_type' => 'full_time',
        'start_date' => now()->subYear()->toDateString(),
        'is_active' => true,
    ]);

    $this->teammate = User::factory()->create([
        'role' => 'support_worker',
        'approved_at' => now(),
    ]);

    $supportWorkerRole = Role::query()->where('name', 'support_worker')->firstOrFail();
    $this->employee->roles()->syncWithoutDetaching([$supportWorkerRole->id]);
    $this->teammate->roles()->syncWithoutDetaching([$supportWorkerRole->id]);

    HrEmployeeProfile::query()->create([
        'user_id' => $this->teammate->id,
        'employee_number' => 'EMP-SS-'.$this->teammate->id,
        'work_email' => 'ss'.$this->teammate->id.'@example.test',
        'position_title' => 'Senior Support Worker',
        'position_role' => 'support_worker',
        'primary_site_id' => $this->site->id,
        'secondary_site_ids' => [],
        'employment_type' => 'full_time',
        'start_date' => now()->subYears(2)->toDateString(),
        'is_active' => true,
    ]);
});

test('the 1:1s page renders and surfaces an employee-visible supervision note', function () {
    HrSupervisionNote::query()->create([
        'employee_user_id' => $this->employee->id,
        'supervisor_user_id' => $this->teammate->id,
        'session_date' => now()->subWeek()->toDateString(),
        'session_type' => 'one_to_one',
        'topics_discussed' => 'Settling in and study plan.',
        'actions_agreed' => ['Book first-aid refresher'],
        'is_visible_to_employee' => true,
        'created_by' => $this->teammate->id,
    ]);

    $response = $this->actingAs($this->employee)->get('/hr/my/one');
    $response->assertOk();

    expect($response->inertiaProps('sessions'))->toHaveCount(1);
    expect($response->inertiaProps('openActions'))->toHaveCount(1);
});

test('an employee-hidden supervision note is NOT surfaced on the 1:1s page', function () {
    HrSupervisionNote::query()->create([
        'employee_user_id' => $this->employee->id,
        'supervisor_user_id' => $this->teammate->id,
        'session_date' => now()->subWeek()->toDateString(),
        'session_type' => 'one_to_one',
        'topics_discussed' => 'Private manager-only note.',
        'is_visible_to_employee' => false,
        'created_by' => $this->teammate->id,
    ]);

    $response = $this->actingAs($this->employee)->get('/hr/my/one');
    $response->assertOk();

    expect($response->inertiaProps('sessions'))->toHaveCount(0);
});

test('an employee can acknowledge a 1:1 with a comment', function () {
    $note = HrSupervisionNote::query()->create([
        'employee_user_id' => $this->employee->id,
        'supervisor_user_id' => $this->teammate->id,
        'session_date' => now()->subWeek()->toDateString(),
        'session_type' => 'one_to_one',
        'topics_discussed' => 'Caseload review.',
        'is_visible_to_employee' => true,
        'created_by' => $this->teammate->id,
    ]);

    $this->actingAs($this->employee)
        ->post("/hr/my/one/{$note->id}/acknowledge", [
            'employee_comments' => 'Thanks, all clear.',
        ])
        ->assertRedirect();

    $note->refresh();
    expect($note->employee_acknowledged)->toBeTrue();
    expect($note->employee_comments)->toBe('Thanks, all clear.');
    expect($note->employee_acknowledged_at)->not->toBeNull();
});

test('an employee cannot acknowledge a 1:1 that is not theirs', function () {
    $note = HrSupervisionNote::query()->create([
        'employee_user_id' => $this->teammate->id,
        'supervisor_user_id' => $this->employee->id,
        'session_date' => now()->subWeek()->toDateString(),
        'session_type' => 'one_to_one',
        'topics_discussed' => 'Someone else’s note.',
        'is_visible_to_employee' => true,
        'created_by' => $this->employee->id,
    ]);

    $this->actingAs($this->employee)
        ->post("/hr/my/one/{$note->id}/acknowledge")
        ->assertNotFound();
});

test('an employee can send kudos to a teammate via self-service', function () {
    $this->actingAs($this->employee)
        ->post('/hr/my/kudos', [
            'to_user_id' => $this->teammate->id,
            'category' => 'teamwork',
            'message' => 'Thanks for covering my round at short notice — legend. 🙌',
        ])
        ->assertRedirect();

    $kudos = HrKudos::query()
        ->where('from_user_id', $this->employee->id)
        ->where('to_user_id', $this->teammate->id)
        ->first();

    expect($kudos)->not->toBeNull();
    expect($kudos->category)->toBe('teamwork');
});

test('sending kudos rejects an unknown category', function () {
    $this->actingAs($this->employee)
        ->post('/hr/my/kudos', [
            'to_user_id' => $this->teammate->id,
            'category' => 'not_a_real_value',
            'message' => 'Nice work.',
        ])
        ->assertSessionHasErrors('category');

    expect(HrKudos::query()->count())->toBe(0);
});

/* ------------------------------------------------------------------ */
/*  Shout-outs (kudos reactions + reply thread) */
/* ------------------------------------------------------------------ */

function myHrMakeKudos(int $from, int $to): HrKudos
{
    return HrKudos::query()->create([
        'from_user_id' => $from,
        'to_user_id' => $to,
        'category' => 'teamwork',
        'message' => 'Calm and kind on a tough shift. Ngā mihi.',
        'is_public' => true,
    ]);
}

test('the overview surfaces received shout-outs with reactions + replies', function () {
    $kudos = myHrMakeKudos($this->teammate->id, $this->employee->id);
    HrKudosReaction::query()->create([
        'kudos_id' => $kudos->id, 'user_id' => $this->teammate->id, 'emoji' => 'heart',
    ]);
    HrKudosReply::query()->create([
        'kudos_id' => $kudos->id, 'user_id' => $this->employee->id, 'body' => 'Thanks!',
    ]);

    $response = $this->actingAs($this->employee)->get('/hr/my');
    $response->assertOk();

    $shoutouts = $response->inertiaProps('overview.shoutouts');
    expect($shoutouts)->toHaveCount(1);
    expect($shoutouts[0]['giver']['id'])->toBe($this->teammate->id);
    expect($shoutouts[0]['reactions']['heart'])->toHaveCount(1);
    expect($shoutouts[0]['replies'])->toHaveCount(1);
});

test('the shout-outs tab renders received and given boxes', function () {
    myHrMakeKudos($this->teammate->id, $this->employee->id); // received
    myHrMakeKudos($this->employee->id, $this->teammate->id); // given

    $response = $this->actingAs($this->employee)->get('/hr/my/shoutouts');
    $response->assertOk();

    expect($response->inertiaProps('received'))->toHaveCount(1);
    expect($response->inertiaProps('given'))->toHaveCount(1);
});

test('an employee can toggle a reaction on a kudos (add then remove)', function () {
    $kudos = myHrMakeKudos($this->teammate->id, $this->employee->id);

    $this->actingAs($this->employee)
        ->post("/hr/my/kudos/{$kudos->id}/react", ['emoji' => 'heart'])
        ->assertRedirect();
    expect(HrKudosReaction::query()
        ->where('kudos_id', $kudos->id)
        ->where('user_id', $this->employee->id)
        ->where('emoji', 'heart')
        ->exists())->toBeTrue();

    // Same emoji again removes it.
    $this->actingAs($this->employee)
        ->post("/hr/my/kudos/{$kudos->id}/react", ['emoji' => 'heart'])
        ->assertRedirect();
    expect(HrKudosReaction::query()->where('kudos_id', $kudos->id)->count())->toBe(0);
});

test('reacting rejects an unknown emoji', function () {
    $kudos = myHrMakeKudos($this->teammate->id, $this->employee->id);

    $this->actingAs($this->employee)
        ->post("/hr/my/kudos/{$kudos->id}/react", ['emoji' => 'rocket'])
        ->assertSessionHasErrors('emoji');

    expect(HrKudosReaction::query()->count())->toBe(0);
});

test('a party can reply on a kudos thread', function () {
    $kudos = myHrMakeKudos($this->teammate->id, $this->employee->id);

    $this->actingAs($this->employee)
        ->post("/hr/my/kudos/{$kudos->id}/reply", ['body' => 'Thank you so much. 💛'])
        ->assertRedirect();

    $reply = HrKudosReply::query()->where('kudos_id', $kudos->id)->first();
    expect($reply)->not->toBeNull();
    expect($reply->user_id)->toBe($this->employee->id);
    expect($reply->body)->toBe('Thank you so much. 💛');
});

test('a non-party cannot reply on a kudos thread', function () {
    $outsider = User::factory()->create([
        'role' => 'support_worker',
        'approved_at' => now(),
    ]);
    HrEmployeeProfile::query()->create([
        'user_id' => $outsider->id,
        'employee_number' => 'EMP-SS-'.$outsider->id,
        'work_email' => 'ss'.$outsider->id.'@example.test',
        'position_title' => 'Support Worker',
        'position_role' => 'support_worker',
        'primary_site_id' => $this->site->id,
        'secondary_site_ids' => [],
        'employment_type' => 'full_time',
        'start_date' => now()->subYear()->toDateString(),
        'is_active' => true,
    ]);

    $kudos = myHrMakeKudos($this->teammate->id, $this->employee->id);

    $this->actingAs($outsider)
        ->post("/hr/my/kudos/{$kudos->id}/reply", ['body' => 'Butting in.'])
        ->assertForbidden();

    expect(HrKudosReply::query()->count())->toBe(0);
});
