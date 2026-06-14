<?php

namespace Tests\Feature\Emar;

use App\Models\Client;
use App\Models\ClientControlledDrugEntry;
use App\Models\ClientMedication;
use App\Models\MedicationError;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * The redesigned Audit Trail resolves the active site's brand colour, now folds
 * controlled-drug movements and medication errors into the unified feed, and
 * flags compliance gaps (a CD transaction without a recorded witness counts as
 * an open gap).
 */
class AuditTrailTest extends TestCase
{
    use RefreshDatabase;

    private function seedAudit(): array
    {
        $this->seed(RbacSeeder::class);
        $user = $this->makeRoleUser('admin');
        $this->grantPermissions($user, ['medications.audit.view']);
        $site = Site::factory()->create(['type' => 'house', 'is_active' => true, 'brand_colour' => '#5E35B1']);
        $client = Client::factory()->create(['site_id' => $site->id, 'status' => 'active']);

        return compact('user', 'site', 'client');
    }

    public function test_page_serves_brand_colour_and_flags_cd_witness_gap(): void
    {
        ['user' => $user, 'site' => $site, 'client' => $client] = $this->seedAudit();
        $med = ClientMedication::query()->create([
            'client_id' => $client->id, 'name' => 'Morphine', 'dosage' => '10mg', 'frequency' => 'PRN',
            'controlled_drug' => true, 'is_prn' => true, 'active' => true, 'state' => 'active', 'approval_status' => 'verified',
        ]);
        // A controlled-drug administration with NO witness → an open compliance gap.
        ClientControlledDrugEntry::query()->create([
            'client_id' => $client->id, 'client_medication_id' => $med->id, 'entry_type' => 'administration',
            'quantity' => 2, 'unit' => 'tablets', 'on_hand_before' => 10, 'on_hand_after' => 8,
            'reason' => 'PRN dose', 'recorded_by' => $user->id, 'witnessed_by' => null, 'recorded_at' => now(),
        ]);

        $this->actingAs($user)
            ->get('/emar/audit?site_id='.$site->id)
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('emar/AuditLog')
                ->where('site_brand_colour', '#5E35B1')
                ->where('stats.open_gaps', 1)
                ->has('events')
                ->has('staff')
                ->has('sites')
                ->where('events', fn ($events) => collect($events)->contains('event_type', 'cd_given'))
            );
    }

    public function test_medication_errors_fold_into_the_feed(): void
    {
        ['user' => $user, 'client' => $client] = $this->seedAudit();
        MedicationError::query()->create([
            'client_id' => $client->id, 'error_type' => 'wrong_dose', 'severity' => 'moderate', 'description' => 'Double dose.',
            'status' => 'reported', 'reported_by' => $user->id, 'reported_at' => now(),
        ]);

        $this->actingAs($user)
            ->get('/emar/audit')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('emar/AuditLog')
                ->where('events', fn ($events) => collect($events)->contains(fn ($e) => $e['event_type'] === 'medication_error' && $e['category'] === 'errors'))
            );
    }

    protected function makeRoleUser(string $roleName): User
    {
        $user = User::factory()->create(['role' => $roleName, 'approved_at' => now()]);
        $role = Role::query()->where('name', $roleName)->first();
        if ($role) {
            $user->roles()->syncWithoutDetaching([$role->id]);
        }

        return $user;
    }

    /**
     * @param  array<int, string>  $permissionKeys
     */
    protected function grantPermissions(User $user, array $permissionKeys): void
    {
        $permissionMap = Permission::query()
            ->whereIn('key', $permissionKeys)
            ->pluck('id')
            ->mapWithKeys(fn (int $id) => [$id => ['allowed' => true]])
            ->all();

        $user->permissionOverrides()->syncWithoutDetaching($permissionMap);
    }
}
