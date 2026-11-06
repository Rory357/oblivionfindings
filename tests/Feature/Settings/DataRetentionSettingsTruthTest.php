<?php

namespace Tests\Feature\Settings;

use App\Models\DataRetentionPolicy;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Readiness audit F7 (6 Nov 2026): Settings › Data offers only the retention the weekly clean-up enforces (audit logs,
 * timeline events). The rows nothing acted on — including "Closed incidents: 2 years", below the 5-year HSWA duty —
 * are gone, and a value the row doesn't offer can't be saved.
 */
final class DataRetentionSettingsTruthTest extends TestCase
{
    use RefreshDatabase;

    public function test_only_enforced_retention_rows_are_offered_and_saved(): void
    {
        $this->seed(RbacSeeder::class);
        $admin = User::factory()->create(['role' => 'admin', 'approved_at' => now()]);
        $admin->roles()->attach(Role::where('name', 'admin')->sole());

        $this->actingAs($admin)->get('/settings/data')->assertOk()->assertInertia(fn ($page) => $page
            ->where('retention_values', ['audit-logs' => '5yr', 'timeline-events' => '5yr']));

        $this->actingAs($admin)->putJson('/settings/data/retention', ['values' => [
            'audit-logs' => 'forever', 'timeline-events' => '30d', 'incidents' => '2yr',
        ]])->assertOk()->assertJsonPath('values', ['audit-logs' => 'forever', 'timeline-events' => '5yr']);

        $this->assertSame(['audit_logs', 'timeline_events'], DataRetentionPolicy::query()->orderBy('model_type')->pluck('model_type')->all());
        $this->assertNull(DataRetentionPolicy::where('model_type', 'audit_logs')->sole()->retention_period_years);
    }
}
