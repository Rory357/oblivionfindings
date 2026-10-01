<?php

namespace Tests\Feature\Emar;

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Models\AppSetting;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use App\Services\MarScheduleService;
use App\Services\Medication\DoseSlots\DoseWindowResolver;
use App\Services\Medication\DoseTimingSettings;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * DoseTimingSettings is read once per request (P01 C4 follow-up): the dose
 * window resolver asks it per order, so a house-wide or org-wide read would
 * otherwise run two queries per order. A save in the same request must
 * still read fresh.
 */
class DoseTimingSettingsCacheTest extends TestCase
{
    use RefreshDatabase;

    public function test_one_request_reads_the_timing_settings_once(): void
    {
        AppSetting::query()->create(['key' => DoseTimingSettings::LATE_MINUTES, 'value' => 45]);
        app()->forgetScopedInstances();

        $reads = $this->appSettingReads(function (): void {
            $resolver = app(DoseWindowResolver::class);
            foreach (range(1, 200) as $orderId) {
                $resolver->forOrder($orderId);
            }
            $schedule = app(MarScheduleService::class);
            $schedule->windowBeforeMinutes();
            $schedule->windowAfterMinutes();
            $schedule->dueSoonMinutes();
            app(DoseTimingSettings::class)->lateIncidentMinutes();
        });

        $this->assertSame(1, $reads);
        $this->assertSame(45, app(DoseWindowResolver::class)->forOrder(1)->afterMinutes);
        $this->assertSame(app(DoseTimingSettings::class), app(DoseTimingSettings::class));
    }

    public function test_a_save_in_the_same_request_is_read_fresh(): void
    {
        $settings = app(DoseTimingSettings::class);
        $this->assertSame(30, $settings->earlyMinutes());

        // How Medication Settings saves an organisation value.
        AppSetting::query()->updateOrCreate(['key' => DoseTimingSettings::EARLY_MINUTES], ['value' => 15]);
        $this->assertSame(15, app(DoseTimingSettings::class)->earlyMinutes());
        $this->assertSame(15, app(DoseWindowResolver::class)->forOrder(1)->beforeMinutes);

        AppSetting::query()->where('key', DoseTimingSettings::EARLY_MINUTES)->firstOrFail()->delete();
        $this->assertSame(30, app(MarScheduleService::class)->windowBeforeMinutes());

        // A key that isn't dose timing leaves the read values alone.
        AppSetting::query()->updateOrCreate(['key' => 'branding.name'], ['value' => 'Kōwhai']);
        $reads = $this->appSettingReads(fn () => app(DoseTimingSettings::class)->lateMinutes());
        $this->assertSame(0, $reads);
    }

    public function test_saving_through_medication_settings_is_read_fresh_in_the_same_request(): void
    {
        $this->seed(RbacSeeder::class);
        $site = Site::factory()->create(['is_active' => true]);
        $manager = User::factory()->create(['role' => 'support_worker', 'approved_at' => now()]);
        $manager->roles()->syncWithoutDetaching([Role::query()->where('name', 'support_worker')->firstOrFail()->id]);
        $manager->permissionOverrides()->syncWithoutDetaching(
            Permission::query()->whereIn('key', ['medications.settings.manage', 'sites.viewAll'])->pluck('id')
                ->mapWithKeys(fn (int $id) => [$id => ['allowed' => true]])
                ->all(),
        );
        HrEmployeeProfile::factory()->create([
            'user_id' => $manager->id,
            'primary_site_id' => $site->id,
            'secondary_site_ids' => [],
            'start_date' => today()->subMonth(),
            'end_date' => null,
            'is_active' => true,
        ]);

        // Read (and keep) the values first, as an earlier part of a request would.
        $this->assertSame(60, app(DoseWindowResolver::class)->forOrder(1)->afterMinutes);

        $this->actingAs($manager->fresh())
            ->from('/emar/settings')
            ->put('/emar/settings/changes', [
                'view' => 'rounds',
                'changes' => [['group' => 'timing', 'key' => 'late', 'site_id' => null, 'value' => '45', 'from' => '60']],
                'confirm_loosening' => true,
            ])
            ->assertRedirect('/emar/settings')
            ->assertSessionHasNoErrors();

        $this->assertSame(45, app(DoseWindowResolver::class)->forOrder(1)->afterMinutes);
        $this->assertSame(45, app(MarScheduleService::class)->windowAfterMinutes());
    }

    public function test_the_next_request_or_job_reads_again(): void
    {
        $first = app(DoseTimingSettings::class);
        $first->earlyMinutes();

        // As the queue worker does between jobs (and Octane between requests).
        app()->forgetScopedInstances();
        DB::table('app_settings')->insert([
            'key' => DoseTimingSettings::EARLY_MINUTES,
            'value' => json_encode(20),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->assertNotSame($first, app(DoseTimingSettings::class));
        $this->assertSame(20, app(DoseTimingSettings::class)->earlyMinutes());
    }

    private function appSettingReads(callable $callback): int
    {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $callback();
        $reads = collect(DB::getQueryLog())
            ->filter(fn (array $query): bool => str_contains($query['query'], 'from `app_settings`'))
            ->count();
        DB::disableQueryLog();

        return $reads;
    }
}
