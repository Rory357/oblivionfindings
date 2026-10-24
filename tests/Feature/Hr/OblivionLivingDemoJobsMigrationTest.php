<?php

use App\Domain\Hr\Models\HrJobRequisition;
use App\Models\Site;

/*
 * The client demo website's three fictional open roles: installed only on a
 * demonstration server (config('demo.site')), only when there are no open
 * postings, never touching an existing posting, and idempotent.
 */

function runDemoJobsMigration(): void
{
    $migration = require database_path('migrations/2026_10_23_120200_create_oblivion_living_demo_jobs.php');
    $migration->up();
}

function demoJobSlugs(): array
{
    return [
        'support-worker-kapiti-coast',
        'team-leader-porirua',
        'night-support-lower-hutt',
    ];
}

test('the demo jobs migration does nothing when the demo switch is off', function () {
    config(['demo.site' => false]);
    Site::factory()->create(['name' => 'Kāpiti Lodge']);

    runDemoJobsMigration();

    expect(HrJobRequisition::query()->whereIn('slug', demoJobSlugs())->count())->toBe(0);
});

test('on a demo server with no open roles it adds three published, open roles, once', function () {
    config(['demo.site' => true]);
    $kapiti = Site::factory()->create(['name' => 'Kāpiti Lodge', 'region' => 'Wellington']);
    $wellington = Site::factory()->create(['name' => 'Pōneke Community', 'region' => 'Wellington', 'city' => 'Wellington']);

    runDemoJobsMigration();
    runDemoJobsMigration();

    $jobs = HrJobRequisition::query()->whereIn('slug', demoJobSlugs())->get()->keyBy('slug');
    expect($jobs)->toHaveCount(3);

    $support = $jobs['support-worker-kapiti-coast'];
    expect($support->title)->toBe('Support Worker');
    expect($support->site_id)->toBe($kapiti->id);
    expect($support->employment_type)->toBe('part_time');
    expect($support->status)->toBe('published');
    expect($support->closing_at)->toBeNull();
    expect($support->published_at)->not->toBeNull();
    expect($support->show_salary)->toBeTrue();
    expect((float) $support->salary_range_min)->toBe(28.5);
    expect($support->screening_questions)->toHaveCount(2);
    expect($support->requirements)->toContain('driver licence');

    // No Site named for Porirua or Lower Hutt: the place goes in the title.
    expect($jobs['team-leader-porirua']->title)->toBe('Team Leader, Porirua');
    expect($jobs['team-leader-porirua']->position_role)->toBe('team_lead');
    expect($jobs['team-leader-porirua']->site_id)->toBeIn([$kapiti->id, $wellington->id]);
    expect($jobs['night-support-lower-hutt']->title)->toBe('Night Support, Lower Hutt');

    // They show on the public careers page and can be applied for.
    $this->get('/careers')
        ->assertOk()
        ->assertInertia(fn ($page) => $page->has('jobs', 3));
    $this->post('/careers/jobs/support-worker-kapiti-coast/apply', [
        'first_name' => 'Demo',
        'last_name' => 'Applicant',
        'personal_email' => 'demo.applicant@example.test',
        'privacy_consent' => '1',
    ])->assertSessionHasNoErrors()->assertSessionHas('success');
});

test('it leaves an organisation that already has an open role alone', function () {
    config(['demo.site' => true]);
    $site = Site::factory()->create(['name' => 'Kāpiti Lodge']);
    $existing = HrJobRequisition::query()->create([
        'title' => 'Cook',
        'slug' => 'cook-existing',
        'site_id' => $site->id,
        'employment_type' => 'casual',
        'openings' => 1,
        'status' => 'published',
        'published_at' => now(),
    ]);
    $before = $existing->fresh()->toArray();

    runDemoJobsMigration();

    expect(HrJobRequisition::query()->whereIn('slug', demoJobSlugs())->count())->toBe(0);
    expect($existing->fresh()->toArray())->toBe($before);
});

test('it never touches a posting that already uses a demo slug', function () {
    config(['demo.site' => true]);
    $site = Site::factory()->create(['name' => 'Kāpiti Lodge']);
    // A closed posting doesn't count as open, but its slug is taken.
    HrJobRequisition::query()->create([
        'title' => 'Our own support worker role',
        'slug' => 'support-worker-kapiti-coast',
        'site_id' => $site->id,
        'employment_type' => 'full_time',
        'openings' => 1,
        'status' => 'closed',
    ]);

    runDemoJobsMigration();

    $kept = HrJobRequisition::query()->where('slug', 'support-worker-kapiti-coast')->get();
    expect($kept)->toHaveCount(1);
    expect($kept->first()->title)->toBe('Our own support worker role');
    expect($kept->first()->status)->toBe('closed');
    expect(HrJobRequisition::query()->whereIn('slug', ['team-leader-porirua', 'night-support-lower-hutt'])->count())->toBe(2);
});

test('with no active Site it adds nothing, because no one could apply', function () {
    config(['demo.site' => true]);
    Site::factory()->create(['name' => 'Kāpiti Lodge', 'is_active' => false]);

    runDemoJobsMigration();

    expect(HrJobRequisition::query()->whereIn('slug', demoJobSlugs())->count())->toBe(0);
});
