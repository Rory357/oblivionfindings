<?php

namespace Tests\Feature\Emar;

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Models\MedicationRound;
use App\Models\MedicationRoundTemplate;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use Carbon\Carbon;
use Database\Seeders\RbacSeeder;
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
        $this->seed(RbacSeeder::class);

        $user = $this->makeRoleUser('admin');
        $site = Site::factory()->create(['is_active' => true]);
        $this->assignCurrentSiteStaff($user, $site);
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
                'site_id' => $site->id,
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
        $this->seed(RbacSeeder::class);

        $user = $this->makeRoleUser('admin');
        $site = Site::factory()->create(['is_active' => true]);
        $this->assignCurrentSiteStaff($user, $site);
        $this->grantPermissions($user, ['medications.orders.manage']);

        $this->actingAs($user)
            ->from('/emar/rounds')
            ->post('/emar/rounds/templates', [
                'name' => 'Bad Day',
                'scheduled_time' => '08:00',
                'window_minutes' => 60,
                'days_of_week' => [0],
                'site_id' => $site->id,
            ])
            ->assertSessionHasErrors('days_of_week.0');
    }

    public function test_round_templates_reject_foreign_default_assignees_and_command_skips_unscoped_or_stale_templates(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-05-03 08:00:00', config('app.worker_timezone', 'Pacific/Auckland')));
        $this->seed(RbacSeeder::class);
        $site = Site::factory()->create(['is_active' => true]);
        $foreignSite = Site::factory()->create(['is_active' => true]);
        $actor = $this->makeRoleUser('admin');
        $this->assignCurrentSiteStaff($actor, $site);
        $this->grantPermissions($actor, ['medications.orders.manage']);
        $currentAssignee = $this->makeRoleUser('support_worker');
        $foreignAssignee = $this->makeRoleUser('support_worker');
        foreach ([[$currentAssignee, $site], [$foreignAssignee, $foreignSite]] as [$staff, $staffSite]) {
            HrEmployeeProfile::factory()->create([
                'user_id' => $staff->id,
                'primary_site_id' => $staffSite->id,
                'secondary_site_ids' => [],
                'start_date' => now()->subMonth(),
                'end_date' => null,
                'is_active' => true,
            ]);
        }

        $payload = [
            'name' => 'Foreign assignee probe',
            'scheduled_time' => '18:00',
            'window_minutes' => 60,
            'days_of_week' => [7],
            'site_id' => $site->id,
            'default_assigned_to' => $foreignAssignee->id,
        ];
        $this->actingAs($actor)
            ->post('/emar/rounds/templates', $payload)
            ->assertNotFound();

        $valid = MedicationRoundTemplate::query()->create([
            ...$payload,
            'name' => 'Current Site assignee',
            'default_assigned_to' => $currentAssignee->id,
            'active' => true,
        ]);
        $foreign = MedicationRoundTemplate::query()->create([
            ...$payload,
            'active' => true,
        ]);
        $unscoped = MedicationRoundTemplate::query()->create([
            ...$payload,
            'name' => 'Legacy null Site template',
            'site_id' => null,
            'default_assigned_to' => null,
            'active' => true,
        ]);

        $this->actingAs($actor)
            ->put('/emar/rounds/templates/'.$valid->id, $payload)
            ->assertNotFound();
        $this->assertSame($currentAssignee->id, (int) $valid->refresh()->default_assigned_to);

        $this->artisan('emar:generate-rounds', [
            '--date' => '2026-05-03',
            '--generate-all' => true,
        ])->assertExitCode(0);

        $this->assertDatabaseHas('medication_rounds', [
            'round_template_id' => $valid->id,
            'site_id' => $site->id,
            'assigned_to' => $currentAssignee->id,
        ]);
        $this->assertDatabaseMissing('medication_rounds', ['round_template_id' => $foreign->id]);
        $this->assertDatabaseMissing('medication_rounds', ['round_template_id' => $unscoped->id]);
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

    private function assignCurrentSiteStaff(User $user, Site $site): void
    {
        HrEmployeeProfile::factory()->create([
            'user_id' => $user->id,
            'primary_site_id' => $site->id,
            'secondary_site_ids' => [],
            'start_date' => today()->subMonth(),
            'end_date' => null,
            'is_active' => true,
        ]);
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
