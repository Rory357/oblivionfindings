<?php

namespace Tests\Feature\Safeguarding;

use App\Models\Permission;
use App\Models\Role;
use App\Models\SafeguardingConcern;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Safeguarding redesign — Step 1 (schema & enum).
 *
 * Covers W1 (store sets an explicit `reported` status) and W2 (the terminal
 * `no_action_required` status is a real enum value with open/closed semantics).
 * Transition guards (W3/W6/W7) and the dedicated triage action (W4) arrive in
 * Step 2 — this file deliberately exercises only the data layer + create/status
 * acceptance, not legal-transition sequencing.
 */
class SafeguardingStatusEnumTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RbacSeeder::class);
    }

    private function makeSafeguardingUser(array $permissionKeys): User
    {
        $user = User::factory()->create([
            'role' => 'admin',
            'approved_at' => now(),
        ]);

        $adminRole = Role::query()->where('name', 'admin')->first();
        if ($adminRole) {
            $user->roles()->syncWithoutDetaching([$adminRole->id]);
        }

        foreach ($permissionKeys as $permissionKey) {
            $permission = Permission::query()->firstOrCreate(
                ['key' => $permissionKey],
                [
                    'description' => str_replace('.', ' ', $permissionKey),
                    'group' => explode('.', $permissionKey)[0],
                ]
            );

            $user->permissionOverrides()->syncWithoutDetaching([
                $permission->id => ['allowed' => true],
            ]);
        }

        return $user;
    }

    public function test_store_sets_status_to_reported(): void
    {
        $user = $this->makeSafeguardingUser(['safeguarding.create']);

        $this->actingAs($user)
            ->post('/safeguarding', [
                'subject_type' => 'other',
                'other_subject_name' => 'Resident A',
                'concern_type' => 'abuse',
                'severity' => 'high',
                'description' => 'Unexplained bruising noticed during the morning routine.',
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('safeguarding_concerns', [
            'concern_type' => 'abuse',
            'status' => 'reported',
        ]);
    }

    public function test_status_column_accepts_no_action_required(): void
    {
        // Proves the migration widened the MySQL enum: a value rejected before
        // (would be coerced to '' / throw) now persists round-trip.
        $concern = SafeguardingConcern::factory()->create([
            'status' => 'no_action_required',
        ]);

        $this->assertSame('no_action_required', $concern->fresh()->status);
        $this->assertDatabaseHas('safeguarding_concerns', [
            'id' => $concern->id,
            'status' => 'no_action_required',
        ]);
    }

    public function test_update_status_does_not_set_no_action_required(): void
    {
        // The enum value is valid (validation passes), but `no_action_required`
        // is reached through triage — not a generic status change. The Step 2
        // lifecycle guard rejects it here. (Triage coverage lives in
        // SafeguardingLifecycleTest.)
        $user = $this->makeSafeguardingUser(['safeguarding.update']);
        $concern = SafeguardingConcern::factory()->create(['status' => 'reported']);

        $this->actingAs($user)
            ->patch("/safeguarding/{$concern->id}/status", ['status' => 'no_action_required'])
            ->assertSessionHasErrors('status');

        $this->assertSame('reported', $concern->fresh()->status);
    }

    public function test_update_status_rejects_unknown_value(): void
    {
        $user = $this->makeSafeguardingUser(['safeguarding.update']);
        $concern = SafeguardingConcern::factory()->create(['status' => 'reported']);

        $this->actingAs($user)
            ->patch("/safeguarding/{$concern->id}/status", ['status' => 'banana'])
            ->assertSessionHasErrors('status');

        $this->assertSame('reported', $concern->fresh()->status);
    }

    public function test_terminal_statuses_are_excluded_from_open_scope(): void
    {
        SafeguardingConcern::factory()->create(['status' => 'reported']);
        SafeguardingConcern::factory()->create(['status' => 'investigating']);
        SafeguardingConcern::factory()->create(['status' => 'closed']);
        SafeguardingConcern::factory()->create(['status' => 'no_action_required']);

        // Open = everything except closed + no_action_required.
        $this->assertSame(2, SafeguardingConcern::open()->count());
        // Closed scope folds in no_action_required.
        $this->assertSame(2, SafeguardingConcern::closed()->count());
    }
}
