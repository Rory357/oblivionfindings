<?php

namespace Tests\Feature\Emar;

use App\Models\Permission;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * NAV (eMAR second review §2–§3): the Medication sidebar decides lead hubs
 * from orders.verify and settings.manage, so HandleInertiaRequests shares
 * them. They are navigation only: a page the sidebar hides is still guarded
 * by its own route permission.
 */
class MedicationNavigationSharesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RbacSeeder::class);
    }

    public function test_a_support_worker_is_shared_neither_lead_key(): void
    {
        $worker = $this->userWithPermissions([
            'medications.view',
            'medications.administer.record',
        ]);

        $page = $this->actingAs($worker)->get(route('profile.edit'))->assertOk();

        $this->assertFalse($page->inertiaProps('auth.can.medications.ordersVerify'));
        $this->assertFalse($page->inertiaProps('auth.can.medications.settingsManage'));
    }

    public function test_the_lead_keys_follow_their_permissions(): void
    {
        $lead = $this->userWithPermissions([
            'medications.view',
            'medications.orders.verify',
            'medications.settings.manage',
        ]);

        $page = $this->actingAs($lead)->get(route('profile.edit'))->assertOk();

        $this->assertTrue($page->inertiaProps('auth.can.medications.ordersVerify'));
        $this->assertTrue($page->inertiaProps('auth.can.medications.settingsManage'));
    }

    public function test_reports_view_any_alone_does_not_grant_medication_pages_beyond_reports(): void
    {
        // The sidebar shows such a user Medication › Reports & audit › Reports
        // only. The server agrees: Reports opens, the other hubs do not.
        $reader = $this->userWithPermissions(['reports.viewAny'], denySupportWorkerMedications: true);

        $page = $this->actingAs($reader)->get(route('profile.edit'))->assertOk();
        $this->assertTrue($page->inertiaProps('auth.can.reports.viewAny'));
        $this->assertFalse($page->inertiaProps('auth.can.medications.view'));

        $this->actingAs($reader)->get('/emar/mar')->assertForbidden();
        $this->actingAs($reader)->get('/emar/settings')->assertForbidden();
    }

    /** @param list<string> $permissions */
    private function userWithPermissions(array $permissions, bool $denySupportWorkerMedications = false): User
    {
        $user = User::factory()->create([
            'role' => 'support_worker',
            'approved_at' => now(),
        ]);
        $permissionIds = Permission::query()->whereIn('key', $permissions)->pluck('id');
        $this->assertCount(count($permissions), $permissionIds);
        $overrides = $permissionIds->mapWithKeys(fn ($id) => [$id => ['allowed' => true]]);

        if ($denySupportWorkerMedications) {
            // Strip the support worker role's own medication grants so the
            // user holds reports.viewAny and nothing medication-specific.
            Permission::query()
                ->where('key', 'like', 'medications.%')
                ->pluck('id')
                ->each(function ($id) use ($overrides): void {
                    $overrides->put($id, ['allowed' => false]);
                });
        }

        $user->permissionOverrides()->sync($overrides->all());

        return $user->fresh();
    }
}
