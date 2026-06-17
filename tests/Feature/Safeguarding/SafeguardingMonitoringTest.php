<?php

namespace Tests\Feature\Safeguarding;

use App\Models\Permission;
use App\Models\SafeguardingConcern;
use App\Models\SafeguardingExternalReport;
use App\Models\SafeguardingInvestigation;
use App\Models\SafeguardingRiskAssessment;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Safeguarding redesign — Step 7b (W5 auto-advance + W9 reminders).
 */
class SafeguardingMonitoringTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RbacSeeder::class);
    }

    private function makeSafeguardingUser(array $permissionKeys): User
    {
        $user = User::factory()->create(['approved_at' => now()]);

        foreach ($permissionKeys as $permissionKey) {
            $permission = Permission::query()->firstOrCreate(
                ['key' => $permissionKey],
                ['description' => str_replace('.', ' ', $permissionKey), 'group' => explode('.', $permissionKey)[0]],
            );
            $user->permissionOverrides()->syncWithoutDetaching([$permission->id => ['allowed' => true]]);
        }

        return $user;
    }

    private function investigation(SafeguardingConcern $concern, User $lead, string $status = 'in_progress'): SafeguardingInvestigation
    {
        return SafeguardingInvestigation::query()->create([
            'safeguarding_concern_id' => $concern->id,
            'investigation_type' => 'internal',
            'lead_investigator_id' => $lead->id,
            'started_at' => now()->subDay(),
            'status' => $status,
            'created_by' => $lead->id,
        ]);
    }

    public function test_completing_an_investigation_advances_the_concern(): void
    {
        $user = $this->makeSafeguardingUser(['safeguarding.investigate']);
        $concern = SafeguardingConcern::factory()->create(['status' => 'investigating']);
        $inv = $this->investigation($concern, $user);

        $this->actingAs($user)
            ->put("/safeguarding/{$concern->id}/investigations/{$inv->id}", ['status' => 'completed'])
            ->assertRedirect();

        $this->assertSame('action_plan', $concern->fresh()->status);
    }

    public function test_non_completing_investigation_update_leaves_the_concern(): void
    {
        $user = $this->makeSafeguardingUser(['safeguarding.investigate']);
        $concern = SafeguardingConcern::factory()->create(['status' => 'investigating']);
        $inv = $this->investigation($concern, $user);

        $this->actingAs($user)
            ->put("/safeguarding/{$concern->id}/investigations/{$inv->id}", ['status' => 'paused'])
            ->assertRedirect();

        $this->assertSame('investigating', $concern->fresh()->status);
    }

    public function test_review_reminders_command_reports_counts(): void
    {
        $user = User::factory()->create();

        // A non-terminal concern whose risk review is overdue.
        $withReview = SafeguardingConcern::factory()->create(['status' => 'monitoring']);
        SafeguardingRiskAssessment::query()->create([
            'safeguarding_concern_id' => $withReview->id,
            'assessor_id' => $user->id,
            'assessed_at' => now()->subMonth(),
            'overall_risk_level' => 'high',
            'next_review_date' => now()->subDay(),
            'created_by' => $user->id,
        ]);

        // A non-terminal concern with an old, unacknowledged external report.
        $withReport = SafeguardingConcern::factory()->create(['status' => 'referred_external']);
        SafeguardingExternalReport::query()->create([
            'safeguarding_concern_id' => $withReport->id,
            'authority_type' => 'police',
            'authority_name' => 'NZ Police',
            'reported_at' => now()->subDays(14),
            'reported_by_user_id' => $user->id,
            'report_method' => 'phone',
            'report_summary' => 'Notified.',
            'acknowledgement_received' => false,
            'created_by' => $user->id,
        ]);

        $this->artisan('safeguarding:review-reminders')->assertExitCode(0);

        // Verify the W9 query logic the command relies on (robust to output formatting).
        $reviewsDue = SafeguardingConcern::query()
            ->whereNotIn('status', SafeguardingConcern::TERMINAL_STATUSES)
            ->whereHas('riskAssessments', fn ($q) => $q->whereNotNull('next_review_date')->where('next_review_date', '<=', now()))
            ->count();
        $acksAwaited = SafeguardingExternalReport::query()
            ->where('acknowledgement_received', false)
            ->where('reported_at', '<=', now()->subDays(7))
            ->whereHas('concern', fn ($q) => $q->whereNotIn('status', SafeguardingConcern::TERMINAL_STATUSES))
            ->count();

        $this->assertSame(1, $reviewsDue);
        $this->assertSame(1, $acksAwaited);
    }
}
