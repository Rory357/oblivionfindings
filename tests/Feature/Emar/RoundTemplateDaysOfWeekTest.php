<?php

namespace Tests\Feature\Emar;

use App\Models\MedicationRound;
use App\Models\MedicationRoundTemplate;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * G1: round-template `days_of_week` must use ISO 1–7 (Mon–Sun). The old
 * validation capped at 6, so Sunday (7) — which `generateRounds` matches via
 * `dayOfWeekIso` — was silently rejected, and no Sunday rounds could exist.
 */
class RoundTemplateDaysOfWeekTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_template_accepts_iso_sunday_and_generates_on_sunday(): void
    {
        // 2026-05-03 is a Sunday (dayOfWeekIso = 7).
        Carbon::setTestNow(Carbon::parse('2026-05-03 08:00:00', config('app.worker_timezone', 'Pacific/Auckland')));
        $this->seed(\Database\Seeders\RbacSeeder::class);

        $user = $this->makeRoleUser('admin');
        $this->grantPermissions($user, [
            'medications.view',
            'medications.administer.record',
            'medications.orders.manage',
            'medications.orders.verify',
        ]);

        $this->actingAs($user)
            ->post('/emar/rounds/templates', [
                'name' => 'Sunday Evening',
                'scheduled_time' => '18:00',
                'window_minutes' => 60,
                'days_of_week' => [7],
            ])
            ->assertSessionHasNoErrors();

        $template = MedicationRoundTemplate::where('name', 'Sunday Evening')->first();
        $this->assertNotNull($template);
        $this->assertSame([7], $template->days_of_week);

        $this->actingAs($user)
            ->post('/emar/rounds/generate', [
                'date' => '2026-05-03',
                'generate_all' => false,
            ])
            ->assertSessionHasNoErrors();

        $this->assertSame(
            1,
            MedicationRound::where('round_template_id', $template->id)
                ->whereDate('round_date', '2026-05-03')
                ->count(),
        );
    }

    public function test_template_rejects_day_of_week_zero(): void
    {
        $this->seed(\Database\Seeders\RbacSeeder::class);

        $user = $this->makeRoleUser('admin');
        $this->grantPermissions($user, ['medications.orders.manage']);

        $this->actingAs($user)
            ->from('/emar/rounds')
            ->post('/emar/rounds/templates', [
                'name' => 'Bad Day',
                'scheduled_time' => '08:00',
                'window_minutes' => 60,
                'days_of_week' => [0],
            ])
            ->assertSessionHasErrors('days_of_week.0');
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
