<?php

namespace Tests\Feature\Emar;

use App\Models\MedicationRound;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * The redesigned Medication Rounds page serves the guided-round modal payload
 * (round + ordered doses + progress) when opened with `?guided={id}`, auto-starts
 * a pending round for a competent viewer, and the retired standalone guided page
 * redirects deep links into the modal.
 */
class RoundsPagePayloadTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_rounds_page_serves_guided_payload_and_auto_starts(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-05-04 08:00:00', config('app.worker_timezone', 'Pacific/Auckland')));
        $this->seed(\Database\Seeders\RbacSeeder::class);

        $user = $this->makeRoleUser('admin');
        $this->grantPermissions($user, ['medications.view', 'medications.administer.record', 'medications.controlled.witness']);

        $round = MedicationRound::create([
            'name' => 'Morning round',
            'round_type' => 'scheduled',
            'scheduled_time' => '08:00',
            'window_minutes' => 60,
            'round_date' => '2026-05-04',
            'status' => 'pending',
            'total_medications' => 0,
        ]);

        $this->actingAs($user)
            ->get('/emar/rounds?date=2026-05-04&guided='.$round->id)
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('emar/Rounds')
                ->where('guidedRound.round.id', $round->id)
                ->where('guidedRound.round.status', 'in_progress')
                ->has('guidedRound.items')
                ->has('witnesses')
                ->has('not_given_reasons')
                ->where('board_user.med_competent', true)
            );

        // Auto-started.
        $this->assertSame('in_progress', $round->fresh()->status);
    }

    public function test_legacy_guided_page_redirects_into_modal(): void
    {
        $this->seed(\Database\Seeders\RbacSeeder::class);

        $user = $this->makeRoleUser('admin');
        $this->grantPermissions($user, ['medications.view', 'medications.administer.record']);

        $round = MedicationRound::create([
            'name' => 'Evening round',
            'round_type' => 'scheduled',
            'scheduled_time' => '18:00',
            'window_minutes' => 60,
            'round_date' => '2026-05-04',
            'status' => 'pending',
            'total_medications' => 0,
        ]);

        $this->actingAs($user)
            ->get('/emar/rounds/'.$round->id.'/guided')
            ->assertRedirect('/emar/rounds?date=2026-05-04&guided='.$round->id);
    }

    protected function makeRoleUser(string $roleName): User
    {
        $user = User::factory()->create(['role' => $roleName, 'approved_at' => now()]);
        $role = Role::query()->where('name', $roleName)->first();
        if ($role) {
            $user->roles()->syncWithoutDetaching([$role->id]);
        }

        return $user;
    }

    /**
     * @param  array<int, string>  $permissionKeys
     */
    protected function grantPermissions(User $user, array $permissionKeys): void
    {
        $permissionMap = Permission::query()
            ->whereIn('key', $permissionKeys)
            ->pluck('id')
            ->mapWithKeys(fn (int $id) => [$id => ['allowed' => true]])
            ->all();

        $user->permissionOverrides()->syncWithoutDetaching($permissionMap);
    }
}
