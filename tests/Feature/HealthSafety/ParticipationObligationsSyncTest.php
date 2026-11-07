<?php

namespace Tests\Feature\HealthSafety;

use App\Domain\Governance\Models\ComplianceObligation;
use App\Models\HsCommittee;
use App\Models\HsCommitteeMeeting;
use App\Models\HsRepresentative;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Readiness audit O5 (6 Nov 2026): the HSWA participation reminders (a) go to an H&S officer, not the first admin;
 * (b) count only meetings that were held; (c) check training against 2 days for each year in office.
 */
final class ParticipationObligationsSyncTest extends TestCase
{
    use RefreshDatabase;

    private Site $site;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RbacSeeder::class);
        $this->site = Site::factory()->create(['is_active' => true]);
    }

    public function test_reminders_go_to_an_hs_officer_before_an_admin(): void
    {
        $admin = $this->roleUser('admin');
        $officer = $this->roleUser('health_safety_officer');
        $committee = $this->committee();

        $this->artisan('participation:sync-obligations')->assertSuccessful();

        $this->assertSame($officer->id, (int) ComplianceObligation::where('obligation_code', "HSC-CADENCE-{$committee->id}")->sole()->owner_id);
        $this->assertNotSame($admin->id, $officer->id);
    }

    public function test_a_cancelled_meeting_is_not_a_meeting(): void
    {
        $this->roleUser('health_safety_officer');
        $cancelled = $this->committee();
        HsCommitteeMeeting::query()->create(['hs_committee_id' => $cancelled->id, 'scheduled_at' => now()->subWeek(), 'status' => 'cancelled']);
        $held = $this->committee();
        HsCommitteeMeeting::query()->create(['hs_committee_id' => $held->id, 'scheduled_at' => now()->subWeek(), 'status' => 'completed']);

        $this->artisan('participation:sync-obligations')->assertSuccessful();

        $this->assertTrue(ComplianceObligation::where('obligation_code', "HSC-CADENCE-{$cancelled->id}")->exists());
        $this->assertFalse(ComplianceObligation::where('obligation_code', "HSC-CADENCE-{$held->id}")->exists());
    }

    public function test_training_is_two_days_for_each_year_in_office(): void
    {
        $this->roleUser('health_safety_officer');
        $firstYear = $this->rep(now()->subMonths(6), 2);
        $thirdYear = $this->rep(now()->subMonths(26), 4);
        $upToDate = $this->rep(now()->subMonths(26), 6);

        $this->artisan('participation:sync-obligations')->assertSuccessful();

        $codes = ComplianceObligation::where('obligation_code', 'like', 'HSR-TRAINING-%')->pluck('obligation_code')->all();
        $this->assertNotContains("HSR-TRAINING-{$firstYear->id}-Y1", $codes, '2 days in the first year is enough.');
        $this->assertContains("HSR-TRAINING-{$thirdYear->id}-Y3", $codes, 'The lifetime total of 4 is below 6 by year 3.');
        $this->assertSame([], array_values(array_filter($codes, fn ($code) => str_starts_with($code, "HSR-TRAINING-{$upToDate->id}-"))));
    }

    private function committee(): HsCommittee
    {
        return HsCommittee::query()->create(['name' => 'Committee '.fake()->unique()->word(), 'site_id' => $this->site->id,
            'meeting_frequency' => 'quarterly', 'established_at' => now()->subYear(), 'status' => 'active', 'members' => []]);
    }

    private function rep(\DateTimeInterface $elected, int $days): HsRepresentative
    {
        return HsRepresentative::query()->create(['user_id' => User::factory()->create()->id, 'site_id' => $this->site->id,
            'election_method' => 'election', 'elected_at' => $elected, 'term_expires_at' => now()->addYears(2), 'status' => 'active',
            'training_days_completed' => $days]);
    }

    private function roleUser(string $role): User
    {
        $user = User::factory()->create(['role' => $role, 'approved_at' => now()]);
        $user->roles()->attach(Role::where('name', $role)->sole());

        return $user;
    }
}
