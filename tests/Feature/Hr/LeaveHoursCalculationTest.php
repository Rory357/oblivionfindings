<?php

use App\Domain\Hr\Models\HrPublicHoliday;
use App\Domain\Hr\Services\LeaveService;
use App\Models\Role;
use App\Models\User;
use Carbon\Carbon;
use Database\Seeders\RbacSeeder;
use Database\Seeders\SeedHrPermissionsSeeder;

beforeEach(function () {
    $this->seed(RbacSeeder::class);
    $this->seed(SeedHrPermissionsSeeder::class);

    $this->manager = User::factory()->create(['role' => 'hr', 'approved_at' => now()]);
    $this->manager->roles()->syncWithoutDetaching([Role::query()->where('name', 'hr')->first()->id]);
    $this->site = ensureCanonicalHrStaffProfile($this->manager);

    $this->staff = User::factory()->create(['role' => 'support_worker', 'approved_at' => now()]);
    ensureCanonicalHrStaffProfile($this->staff, $this->site);
});

test('a public holiday inside a leave range is not charged to the balance', function () {
    $monday = Carbon::parse('2026-09-07')->startOfWeek(Carbon::MONDAY);
    $wednesday = $monday->copy()->addDays(2);

    HrPublicHoliday::query()->create([
        'name' => 'Test Stat Day',
        'date' => $wednesday->toDateString(),
        'year' => $wednesday->year,
        'region' => null,
        'is_national' => true,
    ]);

    $request = app(LeaveService::class)->submitRequest($this->staff, [
        'leave_type' => 'annual',
        'starts_at' => $monday->toDateString(),
        'ends_at' => $monday->copy()->addDays(4)->toDateString(), // Mon–Fri
    ]);

    // 5 weekdays − 1 stat day = 4 days × 8h
    expect((float) $request->hours_requested)->toBe(32.0);
});

test('a single-day half-day request charges half the contracted day and stores the period', function () {
    $monday = Carbon::parse('2026-09-14')->startOfWeek(Carbon::MONDAY);

    $request = app(LeaveService::class)->submitRequest($this->staff, [
        'leave_type' => 'annual',
        'starts_at' => $monday->toDateString(),
        'ends_at' => $monday->toDateString(),
        'period' => 'half_day_am',
    ]);

    expect((float) $request->hours_requested)->toBe(4.0);
    expect($request->period)->toBe('half_day_am');
});

test('the leave form rejects a half-day request that spans multiple days', function () {
    $this->actingAs($this->manager)
        ->post('/hr/leave', [
            'user_id' => $this->staff->id,
            'leave_type' => 'annual',
            'period' => 'half_day_am',
            'starts_at' => now()->addDays(5)->toDateString(),
            'ends_at' => now()->addDays(7)->toDateString(),
        ])
        ->assertSessionHasErrors('period');
});
