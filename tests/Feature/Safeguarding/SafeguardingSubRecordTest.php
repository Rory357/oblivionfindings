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
 * Safeguarding redesign — Step 4b (detail Options-bar action panes).
 *
 * The panes POST to existing sub-record endpoints. This locks the contracts the
 * panes depend on, plus the new server guard that an investigation can't be
 * started on an un-triaged (reported) concern.
 */
class SafeguardingSubRecordTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RbacSeeder::class);
    }

    private function makeSafeguardingUser(array $permissionKeys): User
    {
        $user = User::factory()->create(['role' => 'admin', 'approved_at' => now()]);

        $adminRole = Role::query()->where('name', 'admin')->first();
        if ($adminRole) {
            $user->roles()->syncWithoutDetaching([$adminRole->id]);
        }

        foreach ($permissionKeys as $permissionKey) {
            $permission = Permission::query()->firstOrCreate(
                ['key' => $permissionKey],
                ['description' => str_replace('.', ' ', $permissionKey), 'group' => explode('.', $permissionKey)[0]],
            );
            $user->permissionOverrides()->syncWithoutDetaching([$permission->id => ['allowed' => true]]);
        }

        return $user;
    }

    public function test_investigation_cannot_be_started_on_a_reported_concern(): void
    {
        $user = $this->makeSafeguardingUser(['safeguarding.investigate']);
        $concern = SafeguardingConcern::factory()->create(['status' => 'reported']);

        $this->actingAs($user)
            ->post("/safeguarding/{$concern->id}/investigations", [
                'investigation_type' => 'internal',
                'lead_investigator_id' => $user->id,
                'started_at' => now()->toDateString(),
            ])
            ->assertSessionHasErrors('investigation');

        $this->assertSame(0, $concern->investigations()->count());
        $this->assertSame('reported', $concern->fresh()->status);
    }

    public function test_investigation_start_on_triaged_concern_advances_to_investigating(): void
    {
        $user = $this->makeSafeguardingUser(['safeguarding.investigate']);
        $concern = SafeguardingConcern::factory()->create(['status' => 'triaged']);

        $this->actingAs($user)
            ->post("/safeguarding/{$concern->id}/investigations", [
                'investigation_type' => 'internal',
                'lead_investigator_id' => $user->id,
                'started_at' => now()->toDateString(),
            ])
            ->assertRedirect();

        $this->assertSame(1, $concern->investigations()->count());
        $this->assertSame('investigating', $concern->fresh()->status);
    }

    public function test_risk_assessment_pane_creates_assessment(): void
    {
        $user = $this->makeSafeguardingUser(['safeguarding.update']);
        $concern = SafeguardingConcern::factory()->create(['status' => 'triaged']);

        $this->actingAs($user)
            ->post("/safeguarding/{$concern->id}/risk-assessments", [
                'overall_risk_level' => 'high',
                'protective_measures' => "Increased observations\nDaily check-ins",
                'next_review_date' => now()->addWeek()->toDateString(),
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('safeguarding_risk_assessments', [
            'safeguarding_concern_id' => $concern->id,
            'overall_risk_level' => 'high',
        ]);
    }

    public function test_external_report_pane_creates_report(): void
    {
        $user = $this->makeSafeguardingUser(['safeguarding.report.external']);
        $concern = SafeguardingConcern::factory()->create(['status' => 'triaged', 'requires_external_referral' => true]);

        $this->actingAs($user)
            ->post("/safeguarding/{$concern->id}/external-reports", [
                'authority_type' => 'police',
                'authority_name' => 'NZ Police',
                'report_method' => 'phone',
                'reported_at' => now()->toDateString(),
                'report_summary' => 'Initial safeguarding notification made.',
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('safeguarding_external_reports', [
            'safeguarding_concern_id' => $concern->id,
            'authority_type' => 'police',
        ]);
    }
}
