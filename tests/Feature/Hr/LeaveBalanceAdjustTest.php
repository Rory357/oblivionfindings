<?php

use App\Domain\Hr\Models\HrLeaveBalance;
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
    $this->manager->setAttribute('tenant_id', 1);

    $this->staff = User::factory()->create(['role' => 'support_worker', 'approved_at' => now()]);
    $this->staff->setAttribute('tenant_id', 1);
});

test('a manager can credit a balance and it writes an adjustment ledger row', function () {
    $this->actingAs($this->manager)
        ->post(route('hr.leave.balances.adjust'), [
            'user_id' => $this->staff->id,
            'leave_type' => 'annual',
            'mode' => 'credit',
            'hours' => 8,
            'reason' => 'Goodwill credit',
        ])
        ->assertSessionHas('success');

    $balance = HrLeaveBalance::query()->where('user_id', $this->staff->id)->where('leave_type', 'annual')->first();
    expect((float) $balance->balance_hours)->toBe(160.0); // 152 default + 8

    $this->assertDatabaseHas('hr_leave_balance_ledgers', [
        'user_id' => $this->staff->id,
        'leave_type' => 'annual',
        'entry_type' => 'adjustment',
        'hours_delta' => 8,
    ]);
});

test('set_opening overrides the balance and records an opening ledger entry', function () {
    $this->actingAs($this->manager)
        ->post(route('hr.leave.balances.adjust'), [
            'user_id' => $this->staff->id,
            'leave_type' => 'annual',
            'mode' => 'set_opening',
            'hours' => 100,
            'reason' => 'Migrated from PayHero',
        ])
        ->assertSessionHas('success');

    $balance = HrLeaveBalance::query()->where('user_id', $this->staff->id)->where('leave_type', 'annual')->first();
    expect((float) $balance->balance_hours)->toBe(100.0);

    $this->assertDatabaseHas('hr_leave_balance_ledgers', [
        'user_id' => $this->staff->id,
        'leave_type' => 'annual',
        'entry_type' => 'opening',
    ]);
});

test('the ledger read endpoint returns entries with the actor name', function () {
    $this->actingAs($this->manager)->post(route('hr.leave.balances.adjust'), [
        'user_id' => $this->staff->id, 'leave_type' => 'annual', 'mode' => 'credit', 'hours' => 4, 'reason' => 'x',
    ])->assertSessionHas('success');

    $response = $this->actingAs($this->manager)
        ->getJson(route('hr.leave.balances.ledger', ['user' => $this->staff->id, 'year' => now()->year]));

    $response->assertOk();
    $entries = $response->json('entries');
    expect($entries)->not->toBeEmpty();
    expect($entries[0]['entry_type'])->toBe('adjustment');
    expect($entries[0]['created_by'])->toBe($this->manager->name);
});

test('the preview endpoint returns engine hours, balance and approver without persisting', function () {
    $monday = Carbon::parse('2026-09-21')->startOfWeek(Carbon::MONDAY);

    $response = $this->actingAs($this->manager)->getJson(route('hr.leave.preview', [
        'user_id' => $this->staff->id,
        'leave_type' => 'annual',
        'starts_at' => $monday->toDateString(),
        'ends_at' => $monday->toDateString(),
    ]));

    $response->assertOk();
    expect((float) $response->json('hours'))->toBe(8.0);
    expect($response->json())->toHaveKeys(['available_before', 'projected_remaining', 'has_roster_conflict', 'approval_due_at']);

    // No balance row should have been created by a preview.
    expect(HrLeaveBalance::query()->where('user_id', $this->staff->id)->exists())->toBeFalse();
});

test('self-service preview works for the current user', function () {
    $monday = Carbon::parse('2026-09-28')->startOfWeek(Carbon::MONDAY);

    $this->actingAs($this->staff)->getJson(route('hr.my.leave.preview', [
        'leave_type' => 'annual',
        'period' => 'half_day_am',
        'starts_at' => $monday->toDateString(),
        'ends_at' => $monday->toDateString(),
    ]))
        ->assertOk()
        ->assertJson(['hours' => 4.0, 'period' => 'half_day_am']);
});

test('a non-manager cannot adjust balances', function () {
    $this->actingAs($this->staff)
        ->post(route('hr.leave.balances.adjust'), [
            'user_id' => $this->staff->id, 'leave_type' => 'annual', 'mode' => 'credit', 'hours' => 8,
        ])
        ->assertForbidden();
});
