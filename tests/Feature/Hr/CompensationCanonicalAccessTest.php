<?php

use App\Domain\Hr\Models\HrBonusPayment;
use App\Domain\Hr\Models\HrCompensationHistory;
use App\Domain\Hr\Models\HrCompensationReview;
use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Domain\Hr\Models\HrSalaryBand;
use App\Domain\Hr\Notifications\BonusStatusNotification;
use App\Domain\Hr\Notifications\CompensationAppliedNotification;
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

    $this->site = Site::factory()->create(['name' => 'Compensation canonical visible Site']);
    $this->hiddenSite = Site::factory()->create(['name' => 'Compensation canonical hidden Site']);
    $this->manager = User::factory()->create([
        'name' => 'Compensation HR manager',
        'role' => 'hr',
        'approved_at' => now(),
    ]);
    $this->manager->roles()->syncWithoutDetaching([
        Role::query()->where('name', 'hr')->firstOrFail()->id,
    ]);
    $this->worker = User::factory()->create([
        'name' => 'Compensation visible worker',
        'role' => 'support_worker',
        'approved_at' => now(),
    ]);
    $this->hiddenWorker = User::factory()->create([
        'name' => 'Compensation hidden worker',
        'role' => 'support_worker',
        'approved_at' => now(),
    ]);
    $this->formerWorker = User::factory()->create([
        'name' => 'Compensation former worker',
        'role' => 'support_worker',
        'approved_at' => now(),
    ]);

    $this->managerProfile = compensationCanonicalProfile($this->manager, $this->site, [
        'position_role' => 'hr_manager',
    ]);
    $this->workerProfile = compensationCanonicalProfile($this->worker, $this->site, [
        'annual_salary' => 65000,
        'hourly_rate' => 31.25,
        'hours_per_week' => 40,
    ]);
    $this->hiddenProfile = compensationCanonicalProfile($this->hiddenWorker, $this->hiddenSite, [
        'annual_salary' => 91000,
        'hourly_rate' => 43.75,
        'hours_per_week' => 40,
    ]);
    $this->formerProfile = compensationCanonicalProfile($this->formerWorker, $this->site, [
        'is_active' => false,
        'end_date' => today()->subDay(),
    ]);
});

function compensationCanonicalProfile(User $user, Site $site, array $overrides = []): HrEmployeeProfile
{
    return HrEmployeeProfile::factory()->create([
        'user_id' => $user->id,
        'primary_site_id' => $site->id,
        'secondary_site_ids' => [],
        'position_role' => 'support_worker',
        'start_date' => today()->subYear(),
        'end_date' => null,
        'is_active' => true,
        ...$overrides,
    ]);
}

function compensationCanonicalBand(string $name = 'Support worker standard'): HrSalaryBand
{
    return HrSalaryBand::query()->create([
        'position_role' => 'support_worker',
        'band_name' => $name,
        'min_salary' => 55000,
        'mid_salary' => 65000,
        'max_salary' => 75000,
        'min_hourly' => 26,
        'max_hourly' => 36,
        'currency' => 'NZD',
        'effective_from' => today()->subMonth(),
        'is_active' => true,
    ]);
}

function compensationCanonicalBonus(
    HrEmployeeProfile $profile,
    User $creator,
    array $overrides = [],
): HrBonusPayment {
    return HrBonusPayment::query()->create([
        'employee_profile_id' => $profile->id,
        'bonus_type' => 'spot',
        'amount' => 500,
        'currency' => 'NZD',
        'reason' => 'Canonical compensation test',
        'payment_date' => today()->addWeek(),
        'status' => 'pending',
        'created_by' => $creator->id,
        ...$overrides,
    ]);
}

function compensationCanonicalHistory(
    HrEmployeeProfile $profile,
    User $creator,
): HrCompensationHistory {
    return HrCompensationHistory::query()->create([
        'employee_profile_id' => $profile->id,
        'change_type' => 'adjustment',
        'previous_hourly_rate' => 30,
        'new_hourly_rate' => 31.25,
        'previous_annual_salary' => 62400,
        'new_annual_salary' => 65000,
        'change_percentage' => 4.17,
        'reason' => 'Canonical compensation test',
        'effective_date' => today()->subWeek(),
        'created_by' => $creator->id,
    ]);
}

function compensationCanonicalReview(User $creator, array $profiles): HrCompensationReview
{
    $review = HrCompensationReview::query()->create([
        'title' => 'Canonical review '.fake()->unique()->numberBetween(1000, 999999),
        'review_cycle' => 'annual',
        'effective_date' => today()->addMonth(),
        'status' => 'planning',
        'created_by' => $creator->id,
    ]);

    foreach ($profiles as $profile) {
        $current = (float) ($profile->annual_salary ?? 0);
        $review->items()->create([
            'employee_profile_id' => $profile->id,
            'current_salary' => $current,
            'proposed_salary' => $current + 6500,
            'change_percentage' => $current > 0 ? 10 : 0,
            'status' => 'pending',
        ]);
    }

    return $review;
}

test('compensation workspaces expose application configuration and only Site-visible people records', function (): void {
    $band = compensationCanonicalBand();
    $visibleBonus = compensationCanonicalBonus($this->workerProfile, $this->manager);
    $hiddenBonus = compensationCanonicalBonus($this->hiddenProfile, $this->manager);
    $visibleHistory = compensationCanonicalHistory($this->workerProfile, $this->manager);
    $hiddenHistory = compensationCanonicalHistory($this->hiddenProfile, $this->manager);
    $mixedReview = compensationCanonicalReview($this->manager, [
        $this->workerProfile,
        $this->hiddenProfile,
    ]);
    $hiddenReview = compensationCanonicalReview($this->manager, [$this->hiddenProfile]);

    $bands = $this->actingAs($this->manager)
        ->get('/hr/compensation/bands')
        ->assertOk();
    expect(collect($bands->inertiaProps('bands.data'))->pluck('id'))->toContain($band->id)
        ->and($bands->inertiaProps('bands.data.0.employee_count'))->toBe(1)
        ->and($bands->inertiaProps('stats.people_placed'))->toBe(1);

    $bonuses = $this->actingAs($this->manager)
        ->get('/hr/compensation/bonuses')
        ->assertOk();
    expect(collect($bonuses->inertiaProps('bonuses.data'))->pluck('id'))
        ->toContain($visibleBonus->id)
        ->not->toContain($hiddenBonus->id)
        ->and(collect($bonuses->inertiaProps('employees'))->pluck('id'))
        ->toContain($this->managerProfile->id, $this->workerProfile->id)
        ->not->toContain($this->hiddenProfile->id, $this->formerProfile->id)
        ->and($bonuses->inertiaProps('tabCounts.bonuses'))->toBe(1);

    $history = $this->actingAs($this->manager)
        ->get('/hr/compensation/history')
        ->assertOk();
    expect(collect($history->inertiaProps('history.data'))->pluck('id'))
        ->toContain($visibleHistory->id)
        ->not->toContain($hiddenHistory->id);

    $reviews = $this->actingAs($this->manager)
        ->get('/hr/compensation/reviews')
        ->assertOk();
    $listed = collect($reviews->inertiaProps('reviews.data'));
    expect($listed->pluck('id'))->toContain($mixedReview->id)
        ->not->toContain($hiddenReview->id)
        ->and($listed->firstWhere('id', $mixedReview->id)['items_count'])->toBe(1)
        ->and(collect($reviews->inertiaProps('employees'))->pluck('id'))
        ->toContain($this->managerProfile->id, $this->workerProfile->id)
        ->not->toContain($this->hiddenProfile->id, $this->formerProfile->id)
        ->and($reviews->inertiaProps('tabCounts.reviews'))->toBe(1);

    $detail = $this->actingAs($this->manager)
        ->get("/hr/compensation/reviews/{$mixedReview->id}")
        ->assertOk();
    expect(collect($detail->inertiaProps('review.items'))->pluck('employee_profile_id'))
        ->toContain($this->workerProfile->id)
        ->not->toContain($this->hiddenProfile->id);
});

test('hidden compensation records and partially visible review mutations are concealed', function (): void {
    $hiddenBonus = compensationCanonicalBonus($this->hiddenProfile, $this->manager);
    $mixedReview = compensationCanonicalReview($this->manager, [
        $this->workerProfile,
        $this->hiddenProfile,
    ]);
    $hiddenItem = $mixedReview->items()->where('employee_profile_id', $this->hiddenProfile->id)->firstOrFail();
    $hiddenReview = compensationCanonicalReview($this->manager, [$this->hiddenProfile]);

    $this->actingAs($this->manager)
        ->post('/hr/compensation/bonuses', [
            'employee_profile_id' => $this->hiddenProfile->id,
            'bonus_type' => 'spot',
            'amount' => 250,
            'payment_date' => today()->toDateString(),
        ])
        ->assertNotFound();
    $this->actingAs($this->manager)
        ->post("/hr/compensation/bonuses/{$hiddenBonus->id}/approve")
        ->assertNotFound();
    $this->actingAs($this->manager)
        ->post("/hr/compensation/bonuses/{$hiddenBonus->id}/cancel")
        ->assertNotFound();
    $this->actingAs($this->manager)
        ->get("/hr/compensation/history/{$this->hiddenProfile->id}")
        ->assertNotFound();
    $this->actingAs($this->manager)
        ->get("/hr/compensation/reviews/{$hiddenReview->id}")
        ->assertNotFound();
    $this->actingAs($this->manager)
        ->post("/hr/compensation/reviews/{$mixedReview->id}/items/{$hiddenItem->id}/approve")
        ->assertNotFound();
    $this->actingAs($this->manager)
        ->post("/hr/compensation/reviews/{$mixedReview->id}/approve")
        ->assertNotFound();

    expect($hiddenBonus->fresh()->status)->toBe('pending')
        ->and($mixedReview->fresh()->status)->toBe('planning')
        ->and($mixedReview->items()->where('status', 'approved')->exists())->toBeFalse();
    Notification::assertNothingSent();
});

test('review creation rejects hidden and duplicate people and derives salary facts server side', function (): void {
    $before = HrCompensationReview::query()->count();

    $this->actingAs($this->manager)
        ->post('/hr/compensation/reviews', [
            'title' => 'Hidden profile review',
            'review_cycle' => 'annual',
            'effective_date' => today()->addMonth()->toDateString(),
            'items' => [[
                'employee_profile_id' => $this->hiddenProfile->id,
                'current_salary' => 1,
                'proposed_salary' => 100000,
                'change_percentage' => 999,
            ]],
        ])
        ->assertNotFound();
    expect(HrCompensationReview::query()->count())->toBe($before);

    $this->actingAs($this->manager)
        ->post('/hr/compensation/reviews', [
            'title' => 'Derived salary review',
            'review_cycle' => 'annual',
            'effective_date' => today()->addMonth()->toDateString(),
            'items' => [[
                'employee_profile_id' => $this->workerProfile->id,
                'current_salary' => 1,
                'proposed_salary' => 71500,
                'change_percentage' => 999,
            ]],
        ])
        ->assertSessionHas('success');

    $item = HrCompensationReview::query()
        ->where('title', 'Derived salary review')
        ->firstOrFail()
        ->items()
        ->firstOrFail();
    expect((float) $item->current_salary)->toBe(65000.0)
        ->and((float) $item->proposed_salary)->toBe(71500.0)
        ->and((float) $item->change_percentage)->toBe(10.0);

    $this->actingAs($this->manager)
        ->post('/hr/compensation/reviews', [
            'title' => 'Duplicate profile review',
            'review_cycle' => 'annual',
            'effective_date' => today()->addMonth()->toDateString(),
            'items' => [
                [
                    'employee_profile_id' => $this->workerProfile->id,
                    'proposed_salary' => 72000,
                ],
                [
                    'employee_profile_id' => $this->workerProfile->id,
                    'proposed_salary' => 73000,
                ],
            ],
        ])
        ->assertSessionHasErrors('items.0.employee_profile_id');
});

test('visible bonus and compensation lifecycles notify the affected worker', function (): void {
    $bonus = compensationCanonicalBonus($this->workerProfile, $this->manager);

    $this->actingAs($this->manager)
        ->post("/hr/compensation/bonuses/{$bonus->id}/approve")
        ->assertSessionHas('success');
    $this->actingAs($this->manager)
        ->post("/hr/compensation/bonuses/{$bonus->id}/cancel")
        ->assertSessionHas('success');

    expect($bonus->fresh()->status)->toBe('cancelled');
    Notification::assertSentToTimes($this->worker, BonusStatusNotification::class, 2);

    $review = compensationCanonicalReview($this->manager, [$this->workerProfile]);
    $this->actingAs($this->manager)
        ->post("/hr/compensation/reviews/{$review->id}/approve")
        ->assertSessionHas('success');
    $this->actingAs($this->manager)
        ->post("/hr/compensation/reviews/{$review->id}/apply")
        ->assertSessionHas('success');

    expect($review->fresh()->status)->toBe('applied')
        ->and((float) $this->workerProfile->fresh()->annual_salary)->toBe(71500.0)
        ->and(HrCompensationHistory::query()
            ->where('employee_profile_id', $this->workerProfile->id)
            ->where('change_type', 'review')
            ->exists())->toBeTrue();
    Notification::assertSentToTimes($this->worker, CompensationAppliedNotification::class, 1);
});

test('salary band identity is application wide and returns useful validation errors', function (): void {
    $band = compensationCanonicalBand();
    $payload = [
        'position_role' => $band->position_role,
        'band_name' => $band->band_name,
        'min_salary' => 56000,
        'mid_salary' => 66000,
        'max_salary' => 76000,
        'min_hourly' => 27,
        'max_hourly' => 37,
        'currency' => 'NZD',
        'effective_from' => $band->effective_from->toDateString(),
    ];

    $this->actingAs($this->manager)
        ->post('/hr/compensation/bands', $payload)
        ->assertSessionHasErrors('band_name');

    $other = compensationCanonicalBand('Support worker progression');
    $this->actingAs($this->manager)
        ->put("/hr/compensation/bands/{$other->id}", [
            'band_name' => $band->band_name,
        ])
        ->assertSessionHasErrors('band_name');

    expect(HrSalaryBand::query()
        ->where('position_role', $band->position_role)
        ->where('band_name', $band->band_name)
        ->whereDate('effective_from', $band->effective_from)
        ->count())->toBe(1);
});
