<?php

namespace Tests\Feature\Rostering;

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Jobs\RefreshWorkforceEligibility;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use App\Services\Operations\WorkforcePreferences;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class WorkforceSettingsPreferencesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.worker_timezone' => 'Pacific/Auckland']);
        Carbon::setTestNow(Carbon::parse('2026-10-04 11:30:00', 'UTC'));
        Queue::fake([RefreshWorkforceEligibility::class]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_workforce_settings_save_only_personal_defaults_and_detect_a_competing_save(): void
    {
        [$actor] = $this->actor(['rostering.viewAny']);
        [$other] = $this->actor(['rostering.viewAny']);
        config(['hr.fatigue.max_hours_per_week' => 47]);
        $before = app(WorkforcePreferences::class)->for($actor);
        $this->actingAs($actor)->get(route('operations.workforce.settings'))->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('workforceSettings.worker_timezone', 'Pacific/Auckland')
                ->where('workforceSettings.fatigue.max_hours_per_week', 47)->where('preferences.default_tab', 'shifts')
                ->has('ownerLinks', 1)->where('ownerLinks.0.href', '/settings/notifications'));
        $url = '/operations/workforce-settings';
        $this->patch($url, ['default_tab' => 'calendar', 'roster_view' => 'list', 'expected_revision' => $before['revision']])->assertRedirect();
        $saved = app(WorkforcePreferences::class)->for($actor);
        $this->assertSame('calendar', $saved['default_tab']);
        $this->assertSame('list', $saved['roster_view']);
        $this->assertSame('shifts', app(WorkforcePreferences::class)->for($other)['default_tab']);
        $this->patchJson($url, ['default_tab' => 'shifts', 'roster_view' => 'grid', 'expected_revision' => $before['revision']])
            ->assertUnprocessable()->assertJsonValidationErrors('expected_revision');
        $this->assertSame('calendar', app(WorkforcePreferences::class)->for($actor)['default_tab']);
        $this->patchJson($url, ['default_tab' => 'invalid', 'roster_view' => 'grid', 'expected_revision' => $saved['revision']])->assertUnprocessable();
    }

    private function actor(array $permissions, ?Site $site = null): array
    {
        $site ??= Site::factory()->create(['is_active' => true, 'archived' => false, 'archived_at' => null]);
        $user = User::factory()->create(['approved_at' => now(), 'role' => 'support_worker']);
        HrEmployeeProfile::factory()->create(['user_id' => $user->id, 'primary_site_id' => $site->id, 'secondary_site_ids' => [], 'start_date' => '2025-01-01', 'end_date' => null, 'is_active' => true]);
        $role = Role::create(['name' => 'workforce-test-'.uniqid(), 'label' => 'Workforce test', 'level' => 10, 'type' => 'custom']);
        $role->permissions()->sync(collect($permissions)->map(fn ($key) => Permission::firstOrCreate(['key' => $key], ['description' => $key, 'group' => 'Workforce', 'module' => 'operations'])->id));
        $user->roles()->attach($role);

        return [$user, $site];
    }
}
