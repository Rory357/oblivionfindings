<?php

use App\Domain\Hr\Models\HrProbationReview;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Database\Seeders\SeedHrPermissionsSeeder;

beforeEach(function () {
    $this->seed(RbacSeeder::class);
    $this->seed(SeedHrPermissionsSeeder::class);

    $this->hr = User::factory()->create(['role' => 'hr', 'approved_at' => now()]);
    $this->hr->roles()->syncWithoutDetaching([
        Role::query()->where('name', 'hr')->first()->id,
    ]);

    $this->employee = User::factory()->create(['role' => 'support_worker', 'approved_at' => now()]);
});

test('a probation review can be recorded via the dialog endpoint', function () {
    $this->actingAs($this->hr)
        ->post('/hr/performance/probation', [
            'employee_user_id' => $this->employee->id,
            'review_number' => 1,
            'review_date' => now()->toDateString(),
            'status' => 'completed',
            'areas_assessed' => ['Punctuality', 'Medication competency'],
            'concerns' => 'None significant.',
            'recommendation' => 'pass',
        ])
        ->assertRedirect();

    $review = HrProbationReview::query()
        ->where('employee_user_id', $this->employee->id)
        ->first();

    expect($review)->not->toBeNull();
    expect($review->reviewer_user_id)->toBe($this->hr->id);
    expect($review->review_number)->toBe(1);
    expect($review->recommendation)->toBe('pass');
});

test('a probation review can be edited via the dialog endpoint', function () {
    $review = HrProbationReview::query()->create([
        'tenant_id' => 1,
        'employee_user_id' => $this->employee->id,
        'reviewer_user_id' => $this->hr->id,
        'review_number' => 1,
        'review_date' => now()->subWeek()->toDateString(),
        'status' => 'scheduled',
        'created_by' => $this->hr->id,
    ]);

    $this->actingAs($this->hr)
        ->put("/hr/performance/probation/{$review->id}", [
            'status' => 'extended',
            'recommendation' => 'extend',
            'extension_weeks' => 4,
        ])
        ->assertRedirect();

    $review->refresh();
    expect($review->status)->toBe('extended');
    expect($review->recommendation)->toBe('extend');
    expect($review->extension_weeks)->toBe(4);
});

test('recording a probation review requires the key fields', function () {
    $this->actingAs($this->hr)
        ->post('/hr/performance/probation', [
            'employee_user_id' => $this->employee->id,
        ])
        ->assertSessionHasErrors(['review_number', 'review_date', 'status']);

    expect(HrProbationReview::query()->count())->toBe(0);
});
