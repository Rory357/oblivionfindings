<?php

namespace Tests\Feature\Emar;

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Models\BreakGlassPolicy;
use App\Models\Client;
use App\Models\ClientBreakGlassAccess;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use App\Services\Medication\EmergencyAccess\EmergencyAccessNotifications;
use App\Services\Tasks\Providers\MedicationEmergencyAccessReviewProvider;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * EA-017 / EA-030 / EA-065 / EA-196 / EA-203: emergency-access review,
 * notifications, All Tasks entries, End access and repeat-use flags are all
 * Site-scoped with the eMAR Site bypass (MedicationGovernanceScopeService::
 * SITE_BYPASS_PERMISSIONS), never medications.audit.view or
 * medications.breakglass.end, so they agree with ClientPolicy::reviewBreakGlass.
 */
class EmergencyAccessSiteScopeTest extends TestCase
{
    use RefreshDatabase;

    private Site $kowhai;

    private Site $rimu;

    private User $coordinator;

    private User $rimuWorker;

    private Client $rimuResident;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        $this->seed(RbacSeeder::class);
        $this->kowhai = Site::factory()->create(['type' => 'house', 'is_active' => true, 'name' => 'Kowhai House']);
        $this->rimu = Site::factory()->create(['type' => 'house', 'is_active' => true, 'name' => 'Rimu House']);
        $this->coordinator = $this->staff('coordinator', $this->kowhai);
        $this->rimuWorker = $this->staff('provider_manager', $this->rimu, ['medications.breakglass']);
        $this->rimuResident = Client::factory()->create(['site_id' => $this->rimu->id, 'status' => 'active', 'first_name' => 'Rimu', 'last_name' => 'Resident']);
    }

    public function test_review_lists_and_all_tasks_leave_out_other_houses(): void
    {
        $ended = $this->endedGrant();

        $tasks = app(MedicationEmergencyAccessReviewProvider::class)->authorizedTasks($this->coordinator);
        $this->assertSame([], collect($tasks)->pluck('id')->filter(fn ($id) => str_ends_with((string) $id, '-'.$ended->id))->values()->all());

        $this->actingAs($this->coordinator)
            ->get('/emar/emergency-access?view=review&grant='.$ended->id)
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('reviewQueue', [])
                ->where('auditLog', [])
                ->where('open_grant', null));
    }

    public function test_reviewer_notifications_only_go_to_reviewers_at_the_house(): void
    {
        $ids = app(EmergencyAccessNotifications::class)->reviewers($this->rimu->id)->pluck('id');

        $this->assertFalse($ids->contains($this->coordinator->id));
    }

    public function test_a_coordinator_cannot_end_live_access_at_a_house_they_are_not_approved_for(): void
    {
        $running = ClientBreakGlassAccess::create([
            'client_id' => $this->rimuResident->id, 'user_id' => $this->rimuWorker->id,
            'reason' => 'Seizure PRN needed overnight', 'expires_at' => now()->addHour(),
        ]);

        $this->actingAs($this->coordinator)
            ->deleteJson('/emar/clients/'.$this->rimuResident->id.'/break-glass/'.$running->id, ['reason' => 'Ending this from another house'])
            ->assertNotFound();

        $this->assertNull($running->fresh()->ended_at);
        $this->assertNull($running->fresh()->deleted_at);
    }

    public function test_repeat_use_flags_at_other_houses_cannot_be_dismissed(): void
    {
        BreakGlassPolicy::updateApplicationPolicy(['repeat_threshold_count' => 2]);
        foreach ([1, 2] as $n) {
            ClientBreakGlassAccess::create([
                'client_id' => $this->rimuResident->id, 'user_id' => $this->rimuWorker->id,
                'reason' => 'Synthetic repeated use '.$n, 'expires_at' => now()->subMinutes($n),
            ]);
        }

        $this->actingAs($this->coordinator)
            ->postJson('/emar/break-glass-flags/dismiss', [
                'type' => 'repeat', 'key' => $this->rimu->id.':'.$this->rimuWorker->id,
                'reason' => 'Dismissing a flag at another house',
            ])
            ->assertNotFound();

        $this->assertDatabaseCount('break_glass_flag_dismissals', 0);
    }

    public function test_an_out_of_scope_house_filter_is_not_found(): void
    {
        $this->actingAs($this->coordinator)
            ->get('/emar/emergency-access?site_id='.$this->rimu->id)
            ->assertNotFound();
    }

    public function test_the_end_access_affordance_follows_the_end_permission_not_audit_view(): void
    {
        $auditor = $this->staff('auditor', $this->kowhai);

        $this->actingAs($auditor)
            ->get('/emar')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('can.revoke_break_glass', false));
    }

    private function endedGrant(): ClientBreakGlassAccess
    {
        return ClientBreakGlassAccess::create([
            'client_id' => $this->rimuResident->id, 'user_id' => $this->rimuWorker->id,
            'reason' => 'Covering an absence overnight', 'expires_at' => now()->subDays(5),
        ]);
    }

    /** @param array<int, string> $extra */
    private function staff(string $role, Site $site, array $extra = []): User
    {
        $user = User::factory()->create(['role' => $role, 'approved_at' => now()]);
        $user->roles()->syncWithoutDetaching([Role::where('name', $role)->firstOrFail()->id]);
        foreach ($extra as $key) {
            $permission = Permission::firstOrCreate(['key' => $key], ['description' => $key, 'group' => 'medications', 'module' => 'Clinical']);
            $user->permissionOverrides()->syncWithoutDetaching([$permission->id => ['allowed' => true]]);
        }
        HrEmployeeProfile::factory()->create([
            'user_id' => $user->id, 'primary_site_id' => $site->id, 'secondary_site_ids' => [],
            'position_role' => $role, 'is_active' => true, 'start_date' => today()->subDay(), 'end_date' => null,
            'created_by' => $user->id, 'updated_by' => $user->id,
        ]);

        return $user->fresh();
    }
}
