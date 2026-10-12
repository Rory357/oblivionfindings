<?php

namespace Tests\Feature\Emar;

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Models\MedicationSettingChange;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use App\Services\Medication\Recording\OfflineReplayReview;
use App\Services\Medication\Recording\PackPhotoSettings;
use App\Services\Medication\Settings\MedicationSettingsRegistry;
use App\Services\Medication\WitnessPinSettings;
use Carbon\CarbonImmutable;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * Lane A settings (Stephan, 9 Oct: "if it needs a toggle setting add it to
 * the settings"): policy choices Lane A made are organisation settings with a
 * safe default, loosening that asks for confirmation, and a history row.
 * Safety floors stay fixed in code.
 */
class LaneASettingsTest extends TestCase
{
    use RefreshDatabase;

    private Site $site;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RbacSeeder::class);
        $this->site = Site::factory()->create(['is_active' => true]);
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    public function test_the_settings_are_defined_with_safe_defaults_and_loosening_ranks(): void
    {
        $registry = app(MedicationSettingsRegistry::class);

        $offline = $registry->definition('offline', 'max_hours');
        $this->assertNotNull($offline);
        $this->assertSame(OfflineReplayReview::MAX_AGE_SETTING, $offline->storageKey);
        $this->assertSame('12', $offline->default);
        $this->assertSame([1, 48], $offline->range);
        $this->assertTrue($offline->loosens('12', '24'));
        $this->assertFalse($offline->loosens('12', '6'));
        $this->assertSame(MedicationSettingsRegistry::VIEW_ROUNDS, $registry->group('offline')->view);

        $fallback = $registry->definition('forgotten', 'forgotten_fallback');
        $this->assertNotNull($fallback);
        $this->assertSame(WitnessPinSettings::FORGOTTEN_FALLBACK, $fallback->storageKey);
        $this->assertSame('no', $fallback->default, 'Off until a manager switches it on (Stephan, 9 Oct).');
        $this->assertTrue($fallback->loosens('no', 'yes'));
        $this->assertFalse($fallback->loosens('yes', 'no'));

        $minutes = $registry->definition('forgotten', 'confirm_minutes');
        $this->assertNotNull($minutes);
        $this->assertSame('30', $minutes->default);
        $this->assertTrue($minutes->loosens('30', '60'));
        $this->assertFalse($minutes->loosens('30', '15'));
    }

    public function test_the_offline_age_limit_is_read_from_the_saved_setting_and_saving_it_is_in_the_history(): void
    {
        $manager = $this->organisationManager();
        $review = app(OfflineReplayReview::class);
        $sevenHoursAgo = ['queued_offline' => true, 'administered_at' => now()->subHours(7)->toIso8601String()];

        $this->assertSame(12, OfflineReplayReview::maxAgeHours());
        $this->assertFalse($review->tooOld($sevenHoursAgo));

        $this->actingAs($manager)
            ->put('/emar/settings/changes', $this->save('rounds', [['offline', 'max_hours', '6', '12']]))
            ->assertSessionHasNoErrors();

        $this->assertSame(6, OfflineReplayReview::maxAgeHours());
        $this->assertTrue($review->tooOld($sevenHoursAgo));
        $this->assertStringContainsString('more than 6 hours ago', OfflineReplayReview::tooOldMessage());
        $change = MedicationSettingChange::query()->sole();
        $this->assertSame(['offline', 'max_hours', '12', '6', false], [
            $change->setting_group, $change->setting_key, $change->before_value, $change->after_value, (bool) $change->loosens,
        ]);

        // Longer is looser: it asks for confirmation and is labelled.
        $this->actingAs($manager)
            ->put('/emar/settings/changes', $this->save('rounds', [['offline', 'max_hours', '24', '6']]))
            ->assertSessionHasErrors('confirm_loosening');
        $this->assertSame(6, OfflineReplayReview::maxAgeHours());
        $this->actingAs($manager)
            ->put('/emar/settings/changes', [...$this->save('rounds', [['offline', 'max_hours', '24', '6']]), 'confirm_loosening' => true])
            ->assertSessionHasNoErrors();
        $this->assertSame(24, OfflineReplayReview::maxAgeHours());
        $this->assertTrue((bool) MedicationSettingChange::query()->latest('id')->first()->loosens);

        // Out of range is refused, never stored.
        $this->actingAs($manager)
            ->put('/emar/settings/changes', [...$this->save('rounds', [['offline', 'max_hours', '200', '24']]), 'confirm_loosening' => true])
            ->assertSessionHasErrors();
        $this->assertSame(24, OfflineReplayReview::maxAgeHours());

        $this->actingAs($manager)->get('/emar/settings')->assertInertia(fn (Assert $page) => $page
            ->where('settings.values.offline.max_hours', '24')
            ->where('settings.definitions.offline.max_hours.unit', 'hours'));
    }

    public function test_switching_the_forgotten_pin_fallback_on_is_a_loosening_and_off_is_not(): void
    {
        $manager = $this->organisationManager();
        // Off on a fresh install, whatever the environment says (Stephan, 9 Oct).
        config(['medications.witness_pin.forgotten_fallback_enabled' => true]);
        $this->assertFalse(app(WitnessPinSettings::class)->forgottenFallbackAllowed());

        $this->actingAs($manager)
            ->put('/emar/settings/changes', $this->save('staff', [['forgotten', 'forgotten_fallback', 'yes', 'no']]))
            ->assertSessionHasErrors('confirm_loosening');
        $this->assertFalse(app(WitnessPinSettings::class)->forgottenFallbackAllowed());

        $this->actingAs($manager)
            ->put('/emar/settings/changes', [...$this->save('staff', [['forgotten', 'forgotten_fallback', 'yes', 'no']]), 'confirm_loosening' => true])
            ->assertSessionHasNoErrors();
        $this->assertTrue(app(WitnessPinSettings::class)->forgottenFallbackAllowed());
        $on = MedicationSettingChange::query()->where('setting_group', 'forgotten')->sole();
        $this->assertSame(['no', 'yes', true], [$on->before_value, $on->after_value, (bool) $on->loosens]);

        $this->actingAs($manager)
            ->put('/emar/settings/changes', $this->save('staff', [['forgotten', 'forgotten_fallback', 'no', 'yes']]))
            ->assertSessionHasNoErrors();
        $this->assertFalse(app(WitnessPinSettings::class)->forgottenFallbackAllowed());

        $this->actingAs($manager)
            ->put('/emar/settings/changes', $this->save('staff', [['forgotten', 'confirm_minutes', '20', '30']]))
            ->assertSessionHasNoErrors();
        $this->assertSame(20, app(WitnessPinSettings::class)->confirmWithinMinutes());
        $this->assertSame(3, MedicationSettingChange::query()->where('setting_group', 'forgotten')->count());
    }

    /** A11 with Lane C11's photos served: shown with a warning by default. */
    public function test_the_pack_photo_at_recording_settings_default_to_shown_with_a_warning(): void
    {
        $registry = app(MedicationSettingsRegistry::class);
        $show = $registry->definition('packPhoto', 'show');
        $noPhoto = $registry->definition('packPhoto', 'no_photo');
        $this->assertSame(['yes', 'warn'], [$show->default, $noPhoto->default]);
        $this->assertSame(MedicationSettingsRegistry::VIEW_RULES, $registry->group('packPhoto')->view);
        $this->assertTrue($show->loosens('yes', 'no'));
        $this->assertTrue($noPhoto->loosens('tick', 'warn'));
        $this->assertFalse($noPhoto->loosens('warn', 'tick'));
        $this->assertSame(['show' => true, 'no_photo' => 'warn'], app(PackPhotoSettings::class)->policy());

        $manager = $this->organisationManager();
        $this->actingAs($manager)
            ->put('/emar/settings/changes', $this->save('rules', [['packPhoto', 'no_photo', 'tick', 'warn']]))
            ->assertSessionHasNoErrors();
        $this->assertSame(['show' => true, 'no_photo' => 'tick'], app(PackPhotoSettings::class)->policy());

        $this->actingAs($manager)
            ->put('/emar/settings/changes', $this->save('rules', [['packPhoto', 'show', 'no', 'yes']]))
            ->assertSessionHasErrors('confirm_loosening');
        $this->assertTrue(app(PackPhotoSettings::class)->showAtRecording());
    }

    private function save(string $view, array $changes): array
    {
        return [
            'view' => $view,
            'changes' => array_map(fn (array $c): array => [
                'group' => $c[0],
                'key' => $c[1],
                'site_id' => null,
                'value' => $c[2],
                'from' => $c[3],
            ], $changes),
        ];
    }

    private function organisationManager(): User
    {
        $user = User::factory()->create(['role' => 'support_worker', 'approved_at' => now()]);
        $role = Role::query()->where('name', 'support_worker')->first();
        if ($role) {
            $user->roles()->syncWithoutDetaching([$role->id]);
        }
        $user->permissionOverrides()->syncWithoutDetaching(
            Permission::query()
                ->whereIn('key', ['medications.settings.manage', 'sites.viewAll'])
                ->pluck('id')
                ->mapWithKeys(fn ($id) => [$id => ['allowed' => true]])
                ->all(),
        );
        HrEmployeeProfile::factory()->create([
            'user_id' => $user->id,
            'primary_site_id' => $this->site->id,
            'secondary_site_ids' => [],
            'start_date' => now()->subMonth(),
            'end_date' => null,
            'is_active' => true,
        ]);

        return $user->fresh();
    }
}
