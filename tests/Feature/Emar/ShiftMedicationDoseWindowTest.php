<?php

namespace Tests\Feature\Emar;

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Models\AppSetting;
use App\Models\Client;
use App\Models\Permission;
use App\Models\Shift;
use App\Models\Site;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The shift page's medication card decides when to ask for a reason on the
 * dose window the server sends, so that window must be the one the
 * administration endpoint enforces (MarScheduleService: org settings first,
 * then config).
 */
class ShiftMedicationDoseWindowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RbacSeeder::class);
    }

    public function test_shift_medication_summary_carries_the_effective_dose_window(): void
    {
        $site = Site::factory()->create();
        $client = Client::factory()->create(['site_id' => $site->id]);
        $viewer = $this->userWithPermissions([
            'timeline.viewAny',
            'timeline.create',
            'clients.viewAny',
            'calendar.viewAny',
            'shifts.viewAny',
            'shifts.manageAny',
            'medications.view',
        ], $site);
        $shift = Shift::factory()->create([
            'client_id' => $client->id,
            'site_id' => $site->id,
            'user_id' => $viewer->id,
            'starts_at' => now()->addHour(),
            'ends_at' => now()->addHours(5),
            'status' => 'scheduled',
        ]);

        $defaults = $this->actingAs($viewer)
            ->get(route('operations.shifts.show', $shift))
            ->assertOk();
        $this->assertSame(
            [
                'early_minutes' => (int) config('medications.mar.window_before_minutes'),
                'late_minutes' => (int) config('medications.mar.window_after_minutes'),
            ],
            $defaults->inertiaProps('medications.dose_window'),
        );

        AppSetting::query()->create(['key' => 'medications.mar.window_before_minutes', 'value' => 90]);
        AppSetting::query()->create(['key' => 'medications.mar.window_after_minutes', 'value' => 15]);

        $configured = $this->actingAs($viewer)
            ->get(route('operations.shifts.show', $shift))
            ->assertOk();
        $this->assertSame(
            ['early_minutes' => 90, 'late_minutes' => 15],
            $configured->inertiaProps('medications.dose_window'),
        );
    }

    private function userWithPermissions(array $permissions, Site $site): User
    {
        $user = User::factory()->create([
            'role' => 'support_worker',
            'approved_at' => now(),
        ]);
        HrEmployeeProfile::factory()->create([
            'user_id' => $user->id,
            'primary_site_id' => $site->id,
            'secondary_site_ids' => [],
            'is_active' => true,
            'start_date' => today()->subDay(),
            'end_date' => null,
        ]);
        $permissionIds = Permission::query()->whereIn('key', $permissions)->pluck('id');
        $this->assertCount(count($permissions), $permissionIds);
        $user->permissionOverrides()->sync(
            $permissionIds->mapWithKeys(fn ($id) => [$id => ['allowed' => true]])->all(),
        );

        return $user->fresh();
    }
}
