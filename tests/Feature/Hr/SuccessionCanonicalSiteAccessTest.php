<?php

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Domain\Hr\Models\HrPerformanceReview;
use App\Domain\Hr\Models\HrSuccessionCandidate;
use App\Domain\Hr\Models\HrSuccessionPlan;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Database\Seeders\SeedHrPermissionsSeeder;

function successionSiteProfile(User $user, Site $site, array $overrides = []): HrEmployeeProfile
{
    return HrEmployeeProfile::factory()->create([
        'user_id' => $user->id,
        'primary_site_id' => $site->id,
        'secondary_site_ids' => [],
        'position_title' => 'Team Leader',
        'position_role' => 'team_lead',
        'start_date' => today()->subYear(),
        'end_date' => null,
        'is_active' => true,
        ...$overrides,
    ]);
}

function successionSitePlan(Site $site, User $creator, array $overrides = []): HrSuccessionPlan
{
    return HrSuccessionPlan::query()->create([
        'site_id' => $site->id,
        'role_title' => "{$site->name} Service Manager",
        'department' => 'Operations',
        'risk_level' => 'high',
        'is_active' => true,
        'created_by' => $creator->id,
        ...$overrides,
    ]);
}

beforeEach(function (): void {
    $this->seed(RbacSeeder::class);
    $this->seed(SeedHrPermissionsSeeder::class);

    $this->site = Site::factory()->create(['name' => 'Succession visible Site']);
    $this->hiddenSite = Site::factory()->create(['name' => 'Succession hidden Site']);
    $this->manager = User::factory()->create([
        'name' => 'Succession Site manager',
        'role' => 'team_lead',
        'approved_at' => now(),
    ]);
    $this->manager->roles()->syncWithoutDetaching([
        Role::query()->where('name', 'team_lead')->firstOrFail()->id,
    ]);
    successionSiteProfile($this->manager, $this->site);

    $this->visibleStaff = User::factory()->create([
        'name' => 'Visible succession staff',
        'role' => 'support_worker',
        'approved_at' => now(),
    ]);
    $this->visibleProfile = successionSiteProfile($this->visibleStaff, $this->site);
    $this->hiddenStaff = User::factory()->create([
        'name' => 'Hidden succession staff',
        'role' => 'support_worker',
        'approved_at' => now(),
    ]);
    $this->hiddenProfile = successionSiteProfile($this->hiddenStaff, $this->hiddenSite);
    $this->formerStaff = User::factory()->create([
        'name' => 'Former succession staff',
        'role' => 'support_worker',
        'approved_at' => now(),
    ]);
    $this->formerProfile = successionSiteProfile($this->formerStaff, $this->site, [
        'is_active' => false,
        'end_date' => today()->subDay(),
    ]);

    $this->visiblePlan = successionSitePlan($this->site, $this->manager, [
        'current_holder_user_id' => $this->visibleStaff->id,
    ]);
    $this->visibleCandidate = HrSuccessionCandidate::query()->create([
        'succession_plan_id' => $this->visiblePlan->id,
        'employee_profile_id' => $this->visibleProfile->id,
        'readiness' => 'ready_1_year',
        'assessed_by' => $this->manager->id,
        'assessed_at' => today(),
    ]);
    $this->hiddenPlan = successionSitePlan($this->hiddenSite, $this->hiddenStaff, [
        'current_holder_user_id' => $this->hiddenStaff->id,
        'risk_level' => 'critical',
    ]);
    $this->hiddenCandidate = HrSuccessionCandidate::query()->create([
        'succession_plan_id' => $this->hiddenPlan->id,
        'employee_profile_id' => $this->hiddenProfile->id,
        'readiness' => 'ready_now',
        'assessed_by' => $this->hiddenStaff->id,
        'assessed_at' => today(),
    ]);
});

test('succession register statistics and picker options use canonical Site access', function (): void {
    $response = $this->actingAs($this->manager)->get('/hr/succession')->assertOk();

    expect(collect($response->inertiaProps('plans.data'))->pluck('id')->all())
        ->toBe([$this->visiblePlan->id])
        ->and($response->inertiaProps('stats.total'))->toBe(1)
        ->and($response->inertiaProps('stats.high_risk'))->toBe(1)
        ->and($response->inertiaProps('stats.ready_now'))->toBe(0)
        ->and(collect($response->inertiaProps('holders'))->pluck('id'))
        ->toContain($this->visibleStaff->id)
        ->not->toContain($this->hiddenStaff->id, $this->formerStaff->id)
        ->and(collect($response->inertiaProps('sites'))->pluck('id')->all())
        ->toBe([$this->site->id]);
});

test('hidden Site plans and candidates are concealed across direct and Performance hub reads', function (): void {
    $this->actingAs($this->manager)
        ->get("/hr/succession/{$this->hiddenPlan->id}")
        ->assertNotFound();

    $performance = $this->actingAs($this->manager)
        ->get('/hr/performance?tab=succession')
        ->assertOk();

    expect(collect($performance->inertiaProps('succession.critical_roles'))->pluck('id')->all())
        ->toBe([$this->visiblePlan->id]);
});

test('new plans require one accessible Site and an atomic exact-Site current staff set', function (): void {
    $base = [
        'site_id' => $this->site->id,
        'role_title' => 'Visible Site Clinical Lead',
        'department' => 'Clinical',
        'risk_level' => 'critical',
        'current_holder_user_id' => $this->visibleStaff->id,
        'candidates' => [[
            'employee_profile_id' => $this->visibleProfile->id,
            'readiness' => 'ready_now',
        ]],
    ];

    $before = HrSuccessionPlan::query()->count();
    $this->actingAs($this->manager)
        ->post('/hr/succession', [
            ...$base,
            'site_id' => $this->hiddenSite->id,
            'current_holder_user_id' => $this->hiddenStaff->id,
            'candidates' => [[
                'employee_profile_id' => $this->hiddenProfile->id,
                'readiness' => 'ready_now',
            ]],
        ])
        ->assertNotFound();
    expect(HrSuccessionPlan::query()->count())->toBe($before);

    $this->actingAs($this->manager)
        ->post('/hr/succession', [
            ...$base,
            'candidates' => [
                $base['candidates'][0],
                [
                    'employee_profile_id' => $this->hiddenProfile->id,
                    'readiness' => 'developing',
                ],
            ],
        ])
        ->assertNotFound();
    expect(HrSuccessionPlan::query()->count())->toBe($before);

    $this->actingAs($this->manager)
        ->post('/hr/succession', $base)
        ->assertRedirect();

    $created = HrSuccessionPlan::query()->where('role_title', $base['role_title'])->firstOrFail();
    expect((int) $created->site_id)->toBe($this->site->id)
        ->and($created->candidates)->toHaveCount(1)
        ->and((int) $created->candidates->first()->employee_profile_id)->toBe($this->visibleProfile->id);
});

test('candidate direct objects and duplicate or stale nominations fail closed', function (): void {
    $this->actingAs($this->manager)
        ->put("/hr/succession/candidates/{$this->hiddenCandidate->id}", [
            'readiness' => 'developing',
        ])
        ->assertNotFound();

    foreach ([$this->hiddenProfile, $this->formerProfile] as $profile) {
        $this->actingAs($this->manager)
            ->post("/hr/succession/{$this->visiblePlan->id}/candidates", [
                'employee_profile_id' => $profile->id,
                'readiness' => 'developing',
            ])
            ->assertNotFound();
    }

    $this->actingAs($this->manager)
        ->post("/hr/succession/{$this->visiblePlan->id}/candidates", [
            'employee_profile_id' => $this->visibleProfile->id,
            'readiness' => 'ready_now',
        ])
        ->assertSessionHasErrors('employee_profile_id');

    expect(HrSuccessionCandidate::query()
        ->where('succession_plan_id', $this->visiblePlan->id)
        ->count())->toBe(1);
});

test('a strong signed review enters the canonical Site plan with visible nomination provenance', function (): void {
    $review = HrPerformanceReview::query()->create([
        'employee_user_id' => $this->visibleStaff->id,
        'reviewer_user_id' => $this->manager->id,
        'review_type' => 'annual',
        'review_period_start' => today()->subYear(),
        'review_period_end' => today(),
        'status' => 'signed_off',
        'overall_rating' => 5,
        'manager_signed_off' => true,
        'manager_signed_off_at' => now(),
        'created_by' => $this->manager->id,
    ]);

    $response = $this->actingAs($this->manager)
        ->get("/hr/succession?new=1&source_review_id={$review->id}")
        ->assertOk();

    expect($response->inertiaProps('prefill.site_id'))->toBe($this->site->id)
        ->and($response->inertiaProps('prefill.source_review_id'))->toBe($review->id)
        ->and($response->inertiaProps('prefill.candidate.employee_profile_id'))->toBe($this->visibleProfile->id)
        ->and($response->inertiaProps('prefill.candidate.name'))->toBe($this->visibleStaff->name);

    $this->actingAs($this->manager)
        ->post('/hr/succession', [
            'site_id' => $this->site->id,
            'role_title' => 'Review-origin Clinical Lead',
            'risk_level' => 'high',
            'candidates' => [[
                'employee_profile_id' => $this->visibleProfile->id,
                'readiness' => 'developing',
            ]],
            'source_review_id' => $review->id,
        ])
        ->assertRedirect();

    $plan = HrSuccessionPlan::query()
        ->where('role_title', 'Review-origin Clinical Lead')
        ->firstOrFail();
    expect((int) $plan->site_id)->toBe($this->site->id)
        ->and($plan->notes)->toContain("performance review #{$review->id}")
        ->and($plan->candidates)->toHaveCount(1);
});
