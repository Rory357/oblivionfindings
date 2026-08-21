<?php

namespace Tests\Feature\Emar;

use App\Models\Client;
use App\Models\ClientControlledDrugEntry;
use App\Models\ClientMedication;
use App\Models\ClientMedicationAdministration;
use App\Models\MedicationError;
use App\Models\MedicationOrderVersion;
use App\Models\MedicationPharmacyOrder;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
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

    public function test_start_and_cease_carry_the_acting_staff_member(): void
    {
        ['user' => $user, 'client' => $client] = $this->seedAudit();
        $creator = User::factory()->create(['name' => 'Aroha Ngata']);
        $ceaser = User::factory()->create(['name' => 'Tom Reka']);
        ClientMedication::query()->create([
            'client_id' => $client->id, 'name' => 'Paracetamol', 'dosage' => '500mg', 'frequency' => 'BD',
            'is_prn' => true, 'active' => false, 'state' => 'ceased', 'approval_status' => 'verified',
            'created_by' => $creator->id, 'ceased_by' => $ceaser->id,
            'ceased_at' => now()->subDay(), 'ceased_reason' => 'No longer required',
        ]);

        $this->actingAs($user)->get('/emar/audit')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('events', function ($events) {
                $started = collect($events)->firstWhere('event_type', 'medication_started');
                $ceased = collect($events)->firstWhere('event_type', 'medication_ceased');

                return $started && $started['performed_by'] === 'Aroha Ngata'
                    && ! in_array('no_actor', $started['flags'])
                    && $ceased && $ceased['performed_by'] === 'Tom Reka';
            })
        );
    }

    public function test_medication_change_carries_a_before_after_diff(): void
    {
        ['user' => $user, 'client' => $client] = $this->seedAudit();
        $med = ClientMedication::query()->create([
            'client_id' => $client->id, 'name' => 'Sertraline', 'dosage' => '100mg', 'frequency' => 'OD',
            'is_prn' => true, 'active' => true, 'state' => 'active', 'approval_status' => 'verified', 'version' => 2,
        ]);
        MedicationOrderVersion::query()->create([
            'client_medication_id' => $med->id, 'client_id' => $client->id, 'version_number' => 1,
            'name' => 'Sertraline', 'dosage' => '50mg', 'frequency' => 'OD',
            'changed_by' => $user->id, 'changed_at' => now()->subDays(2),
        ]);
        MedicationOrderVersion::query()->create([
            'client_medication_id' => $med->id, 'client_id' => $client->id, 'version_number' => 2,
            'name' => 'Sertraline', 'dosage' => '100mg', 'frequency' => 'OD',
            'change_reason' => 'Dose increased', 'changed_by' => $user->id, 'changed_at' => now(),
        ]);

        $this->actingAs($user)->get('/emar/audit')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('events', function ($events) {
                $changed = collect($events)->firstWhere('event_type', 'medication_changed');
                $changes = $changed['details']['changes'] ?? [];

                return collect($changes)->contains(fn ($c) => $c['field'] === 'Dose' && $c['from'] === '50mg' && $c['to'] === '100mg');
            })
        );
    }

    public function test_coded_refusal_is_not_flagged_as_missing_reason(): void
    {
        ['user' => $user, 'client' => $client] = $this->seedAudit();
        $med = ClientMedication::query()->create([
            'client_id' => $client->id, 'name' => 'Aspirin', 'dosage' => '75mg', 'frequency' => 'PRN',
            'is_prn' => true, 'active' => true, 'state' => 'active', 'approval_status' => 'verified',
        ]);
        $coded = ClientMedicationAdministration::query()->create([
            'client_id' => $client->id, 'client_medication_id' => $med->id, 'administered_by' => $user->id,
            'status' => 'refused', 'reason_code' => 'absent', 'administered_at' => now(), 'scheduled_for' => now(),
        ]);
        $uncoded = ClientMedicationAdministration::query()->create([
            'client_id' => $client->id, 'client_medication_id' => $med->id, 'administered_by' => $user->id,
            'status' => 'refused', 'administered_at' => now(), 'scheduled_for' => now(),
        ]);

        $this->actingAs($user)->get('/emar/audit')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('events', function ($events) use ($coded, $uncoded) {
                $c = collect($events)->firstWhere('id', 'admin_'.$coded->id);
                $u = collect($events)->firstWhere('id', 'admin_'.$uncoded->id);

                return $c && ! in_array('no_reason', $c['flags'])
                    && $u && in_array('no_reason', $u['flags']);
            })
        );
    }

    public function test_unrecorded_scheduled_dose_becomes_an_omission(): void
    {
        ['user' => $user, 'client' => $client] = $this->seedAudit();
        // Scheduled (non-PRN) med, active for 3 days, due daily at 08:00.
        ClientMedication::query()->create([
            'client_id' => $client->id, 'name' => 'Metformin', 'dosage' => '500mg', 'frequency' => 'Morning',
            'dose_times' => ['08:00'], 'is_prn' => false, 'active' => true, 'state' => 'active',
            'approval_status' => 'verified', 'start_date' => now()->subDays(3)->toDateString(),
        ]);
        // A PRN med must NOT generate omissions.
        ClientMedication::query()->create([
            'client_id' => $client->id, 'name' => 'Lorazepam', 'dosage' => '1mg', 'frequency' => 'PRN',
            'is_prn' => true, 'active' => true, 'state' => 'active', 'approval_status' => 'verified',
            'start_date' => now()->subDays(3)->toDateString(),
        ]);

        $this->actingAs($user)->get('/emar/audit')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('events', function ($events) {
                $omissions = collect($events)->where('event_type', 'omission');

                return $omissions->isNotEmpty()
                    && $omissions->every(fn ($e) => str_contains($e['description'], 'Metformin'))
                    && $omissions->every(fn ($e) => in_array('omission', $e['flags']));
            })
        );
    }

    public function test_cd_receipt_and_count_are_not_mislabelled_or_witness_flagged(): void
    {
        ['user' => $user, 'client' => $client] = $this->seedAudit();
        $med = ClientMedication::query()->create([
            'client_id' => $client->id, 'name' => 'Oxycodone', 'dosage' => '5mg', 'frequency' => 'PRN',
            'controlled_drug' => true, 'is_prn' => true, 'active' => true, 'state' => 'active', 'approval_status' => 'verified',
        ]);
        $receipt = ClientControlledDrugEntry::query()->create([
            'client_id' => $client->id, 'client_medication_id' => $med->id, 'entry_type' => 'receipt',
            'quantity' => 20, 'unit' => 'tablets', 'on_hand_before' => 0, 'on_hand_after' => 20,
            'recorded_by' => $user->id, 'witnessed_by' => null, 'recorded_at' => now(),
        ]);
        $count = ClientControlledDrugEntry::query()->create([
            'client_id' => $client->id, 'client_medication_id' => $med->id, 'entry_type' => 'stock_count',
            'quantity' => 20, 'unit' => 'tablets', 'on_hand_before' => 20, 'on_hand_after' => 20,
            'recorded_by' => $user->id, 'witnessed_by' => null, 'recorded_at' => now(),
        ]);

        $this->actingAs($user)->get('/emar/audit')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('stats.open_gaps', 0)
            ->where('events', function ($events) use ($receipt, $count) {
                $r = collect($events)->firstWhere('id', 'cd_'.$receipt->id);
                $c = collect($events)->firstWhere('id', 'cd_'.$count->id);

                return $r && $r['event_type'] === 'cd_received' && ! in_array('missing_witness', $r['flags'])
                    && $c && $c['event_type'] === 'cd_balance_check' && ! in_array('missing_witness', $c['flags']);
            })
        );
    }

    public function test_pharmacy_delivery_appears_as_stock_received(): void
    {
        ['user' => $user, 'client' => $client] = $this->seedAudit();
        $med = ClientMedication::query()->create([
            'client_id' => $client->id, 'name' => 'Insulin', 'dosage' => '10u', 'frequency' => 'PRN',
            'is_prn' => true, 'active' => true, 'state' => 'active', 'approval_status' => 'verified',
        ]);
        MedicationPharmacyOrder::query()->create([
            'client_id' => $client->id, 'client_medication_id' => $med->id, 'pharmacy_name' => 'City Pharmacy',
            'order_type' => 'repeat', 'status' => 'delivered', 'quantity_received' => 30,
            'ordered_by' => $user->id, 'received_by' => $user->id, 'delivered_at' => now(),
        ]);

        $this->actingAs($user)->get('/emar/audit')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('events', fn ($events) => collect($events)->contains(fn ($e) => $e['event_type'] === 'stock_received' && $e['category'] === 'stock'))
        );
    }

    public function test_integrity_endpoint_confirms_backing_record_without_sensitive_metadata_and_conceals_derived_omissions(): void
    {
        ['user' => $user, 'client' => $client] = $this->seedAudit();
        $med = ClientMedication::query()->create([
            'client_id' => $client->id, 'name' => 'Warfarin', 'dosage' => '3mg', 'frequency' => 'PRN',
            'is_prn' => true, 'active' => true, 'state' => 'active', 'approval_status' => 'verified',
        ]);
        $admin = ClientMedicationAdministration::query()->create([
            'client_id' => $client->id, 'client_medication_id' => $med->id, 'administered_by' => $user->id,
            'status' => 'given', 'dose_given' => '3mg', 'administered_at' => now(), 'scheduled_for' => now(),
        ]);

        $this->actingAs($user)->getJson('/emar/audit/event/admin_'.$admin->id.'/integrity')
            ->assertOk()
            ->assertExactJson(['backed' => true]);

        $this->actingAs($user)->getJson('/emar/audit/event/omission_999_202601010800/integrity')
            ->assertNotFound();
    }

    public function test_flag_endpoint_opens_a_medication_error(): void
    {
        ['user' => $user, 'client' => $client] = $this->seedAudit();
        $this->grantPermissions($user, ['medications.administer.record']);
        $med = ClientMedication::query()->create([
            'client_id' => $client->id, 'name' => 'Codeine', 'dosage' => '30mg', 'frequency' => 'PRN',
            'controlled_drug' => true, 'is_prn' => true, 'active' => true, 'state' => 'active', 'approval_status' => 'verified',
        ]);
        $cd = ClientControlledDrugEntry::query()->create([
            'client_id' => $client->id, 'client_medication_id' => $med->id, 'entry_type' => 'administration',
            'quantity' => 1, 'unit' => 'tablet', 'recorded_by' => $user->id, 'witnessed_by' => null, 'recorded_at' => now(),
        ]);

        $this->actingAs($user)
            ->post('/emar/audit/event/cd_'.$cd->id.'/flag', ['flag' => 'missing_witness'])
            ->assertRedirect();

        $this->assertDatabaseHas('medication_errors', [
            'client_id' => $client->id,
            'reported_by' => $user->id,
            'status' => 'reported',
            'error_type' => 'documentation',
        ]);
    }

    public function test_weekly_stats_use_the_worker_timezone_boundary(): void
    {
        $tz = config('app.worker_timezone', 'Pacific/Auckland');
        // Freeze "now" mid-week (Wednesday) so the week boundary is unambiguous.
        Carbon::setTestNow(Carbon::parse('2026-06-17 10:00:00', $tz));

        try {
            ['user' => $user, 'client' => $client] = $this->seedAudit();
            $med = ClientMedication::query()->create([
                'client_id' => $client->id, 'name' => 'Aspirin', 'dosage' => '75mg', 'frequency' => 'PRN',
                'is_prn' => true, 'active' => true, 'state' => 'active', 'approval_status' => 'verified',
            ]);
            // A dose at Monday 00:30 NZT (this week) is Sunday 12:30 UTC (last UTC week);
            // it must still count as "this week" once the boundary is worker-tz.
            $mondayEarly = Carbon::parse('2026-06-15 00:30:00', $tz)->utc();
            ClientMedicationAdministration::query()->create([
                'client_id' => $client->id, 'client_medication_id' => $med->id, 'administered_by' => $user->id,
                'status' => 'given', 'dose_given' => '75mg', 'administered_at' => $mondayEarly, 'scheduled_for' => $mondayEarly,
            ]);

            $this->actingAs($user)->get('/emar/audit')->assertOk()->assertInertia(fn (Assert $page) => $page
                ->where('stats.this_week', fn ($n) => $n >= 1)
                ->where('stats.this_month', fn ($n) => $n >= 1)
            );
        } finally {
            Carbon::setTestNow();
        }
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
