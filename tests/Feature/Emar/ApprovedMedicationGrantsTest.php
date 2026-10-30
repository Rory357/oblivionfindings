<?php

namespace Tests\Feature\Emar;

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Models\Client;
use App\Models\ClientMedication;
use App\Models\ClientMedicationStock;
use App\Models\MedicationOrderRevision;
use App\Models\MedicationStockLot;
use App\Models\Permission;
use App\Models\Role;
use App\Models\ServiceContext;
use App\Models\Shift;
use App\Models\Site;
use App\Models\User;
use App\Services\Medication\Stock\MedicationStockService;
use Carbon\Carbon;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * Lane C4 (EA-054, EA-076, EA-145, EA-153, EA-162): the role grants approved
 * on 1 Oct and decided by Stephan on 9 Oct ("apply both"), shipped as grant
 * migrations only. Deploys run migrations and skip seeders, so each test
 * starts from the seeded roles and applies the migration the way a deploy
 * would.
 */
class ApprovedMedicationGrantsTest extends TestCase
{
    use RefreshDatabase;

    private const ORDERS_MIGRATION = 'database/migrations/2026_10_09_230000_grant_team_lead_medication_orders_manage.php';

    private const STOCK_MIGRATION = 'database/migrations/2026_10_09_230010_add_medication_stock_receive_permission.php';

    private const ERRORS_MIGRATION = 'database/migrations/2026_10_09_230020_grant_admin_medication_errors_manage.php';

    private const EMERGENCY_POLICY_MIGRATION = 'database/migrations/2026_10_03_211100_grant_emergency_medication_policy_permission.php';

    private Site $house;

    private Site $otherHouse;

    private Client $person;

    private Client $otherPerson;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-10-09 10:30', 'Pacific/Auckland')->utc());
        $this->seed(RbacSeeder::class);
        // A deployed database before this lane's migrations: RefreshDatabase
        // already ran them on an empty roles table (creating the key, granting
        // nothing), then the seeder gave admin and team_lead the current keys.
        Permission::query()->where('key', 'medications.stock.receive')->delete();
        $teamLeadId = Role::query()->where('name', 'team_lead')->value('id');
        $preC4Ids = Permission::query()->whereIn('key', ['medications.orders.manage', 'medications.stock.update'])->pluck('id');
        DB::table('role_permission')->where('role_id', $teamLeadId)->whereIn('permission_id', $preC4Ids)->delete();
        Cache::flush();
        Storage::fake('local');
        Storage::fake('private');
        $context = ServiceContext::factory()->create(['type' => 'residential', 'is_active' => true]);
        $this->house = Site::factory()->create(['name' => 'Rimu House', 'is_active' => true, 'archived' => false, 'archived_at' => null]);
        $this->otherHouse = Site::factory()->create(['name' => 'Kōwhai House', 'is_active' => true, 'archived' => false, 'archived_at' => null]);
        $this->person = Client::factory()->create(['site_id' => $this->house->id, 'service_context_id' => $context->id, 'status' => 'active']);
        $this->otherPerson = Client::factory()->create(['site_id' => $this->otherHouse->id, 'service_context_id' => $context->id, 'status' => 'active']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_team_lead_lacks_orders_manage_until_the_grant_migration_adds_only_that_grant(): void
    {
        // EA-054: the approved P04 amendment was never applied.
        $this->assertNotContains('medications.orders.manage', $this->roleKeys('team_lead'));
        $before = $this->rolePermissionRows();

        $this->migrate(self::ORDERS_MIGRATION);
        $this->migrate(self::ORDERS_MIGRATION);

        $this->assertSame(['team_lead:medications.orders.manage'], $this->addedRows($before));
        // The independent check stays: house leads keep verify, and nobody
        // else gained anything.
        $this->assertContains('medications.orders.verify', $this->roleKeys('team_lead'));

        $this->rollback(self::ORDERS_MIGRATION);
        $this->assertSame($before, $this->rolePermissionRows());
    }

    public function test_a_house_lead_enters_an_order_change_and_someone_else_still_checks_it(): void
    {
        $lead = $this->staff('team_lead', $this->house, shift: true);
        $otherLead = $this->staff('team_lead', $this->house, shift: true);

        $this->actingAs($lead)->post('/emar/orders', $this->entryInput())->assertForbidden();

        $this->migrate(self::ORDERS_MIGRATION);
        [$lead, $otherLead] = [$lead->fresh(), $otherLead->fresh()];

        $this->actingAs($lead)->post('/emar/orders', $this->entryInput())->assertRedirect()->assertSessionHasNoErrors();
        $revision = MedicationOrderRevision::query()->latest('id')->firstOrFail();
        $this->assertSame($lead->id, (int) $revision->entered_by);
        $this->assertSame('pending', $revision->status);

        // The lead who entered it cannot give it the independent check.
        $this->actingAs($lead)->post('/emar/order-revisions/'.$revision->id.'/check', $this->checkInput())
            ->assertSessionHasErrors('checker');
        $this->assertSame('pending', $revision->fresh()->status);

        $this->actingAs($otherLead)->post('/emar/order-revisions/'.$revision->id.'/check', $this->checkInput())
            ->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame('checked', $revision->fresh()->status);
    }

    public function test_the_receive_key_is_created_for_house_leads_who_also_get_stock_update(): void
    {
        // EA-076: no receive key, and team_lead has no stock.update.
        $this->assertFalse(Permission::query()->where('key', 'medications.stock.receive')->exists());
        $this->assertNotContains('medications.stock.update', $this->roleKeys('team_lead'));
        $before = $this->rolePermissionRows();

        $this->migrate(self::STOCK_MIGRATION);
        $this->migrate(self::STOCK_MIGRATION);

        // Stephan, 10 Oct: team leads only. Support workers don't receive
        // deliveries; coordinators and finance keep stock.update as today.
        $this->assertEqualsCanonicalizing([
            'admin:medications.stock.receive',
            'team_lead:medications.stock.receive',
            'team_lead:medications.stock.update',
        ], $this->addedRows($before));
        $this->assertNotContains('medications.stock.receive', $this->roleKeys('support_worker'));
        $this->assertContains('medications.stock.update', $this->roleKeys('finance'));
        $this->assertContains('medications.stock.update', $this->roleKeys('coordinator'));

        $this->rollback(self::STOCK_MIGRATION);
        $this->assertSame($before, $this->rolePermissionRows());
        $this->assertFalse(Permission::query()->where('key', 'medications.stock.receive')->exists());
    }

    public function test_house_leads_open_the_stock_hub_and_manage_only_their_own_houses_stock(): void
    {
        config(['medications.stock_lots_enabled' => true]);
        $lead = $this->staff('team_lead', $this->house);
        [$med] = $this->trackedMedicine($this->person, $lead);
        [$foreignMed] = $this->trackedMedicine($this->otherPerson, $lead);

        $this->actingAs($lead)->get('/emar/stock/packs')->assertForbidden();

        $this->migrate(self::STOCK_MIGRATION);
        $lead = $lead->fresh();

        $this->actingAs($lead)->get('/emar/stock/packs')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('emar/stock/StockHub')
            ->where('can.receive', true)->where('can.manage', true)
            ->where('items.total', 1)->where('items.data.0.id', $med->id));

        $this->actingAs($lead)->postJson('/emar/stock/packs/commands', $this->receipt($med->id))->assertOk();
        $this->actingAs($lead)->postJson('/emar/stock/packs/commands', $this->supplyOrder($med->id))->assertOk();
        // Their own houses only: another house's medicine is not found.
        $this->actingAs($lead)->postJson('/emar/stock/packs/commands', $this->receipt($foreignMed->id))->assertNotFound();
        $this->actingAs($lead)->getJson('/emar/stock/packs/medicine/'.$foreignMed->id)->assertNotFound();
    }

    /** Stephan, 10 Oct: support workers do not receive or photograph deliveries. */
    public function test_a_support_worker_cannot_receive_or_photograph_a_delivery(): void
    {
        config(['medications.stock_lots_enabled' => true]);
        $worker = $this->staff('support_worker', $this->house);
        $this->person->supportWorkers()->attach($worker->id);
        [$med, $stock] = $this->trackedMedicine($this->person, $worker);
        $lot = MedicationStockLot::query()->where('client_medication_stock_id', $stock->id)->firstOrFail();
        $this->migrate(self::STOCK_MIGRATION);
        $worker = $worker->fresh();
        $this->assertFalse($worker->canDo('medications.stock.receive'));

        $this->actingAs($worker)->get('/emar/stock/packs')->assertForbidden();
        $this->actingAs($worker)->postJson('/emar/stock/packs/commands', $this->receipt($med->id))->assertForbidden();
        $this->actingAs($worker)->post('/emar/stock/packs/'.$lot->id.'/photos', [
            'request_uuid' => (string) Str::uuid(),
            'photo' => UploadedFile::fake()->image('pack.jpg', 640, 480),
        ], ['Accept' => 'application/json'])->assertForbidden();
        $this->actingAs($worker)->post('/emar/stock/packs/commands', [
            ...$this->receipt($med->id), 'photo' => UploadedFile::fake()->image('pack.jpg'),
        ], ['Accept' => 'application/json'])->assertForbidden();

        $this->assertSame(1, MedicationStockLot::query()->where('client_medication_stock_id', $stock->id)->count());
        $this->assertSame(0, \App\Models\MedicationStockPhoto::query()->count());
    }

    /**
     * The receive-level / update-level split still holds for anyone an admin
     * grants only stock.receive in Settings › Roles.
     */
    public function test_someone_granted_only_stock_receive_receives_and_photographs_but_leads_keep_orders_and_removals(): void
    {
        config(['medications.stock_lots_enabled' => true]);
        $worker = $this->staff('support_worker', $this->house);
        $this->person->supportWorkers()->attach($worker->id);
        [$med, $stock] = $this->trackedMedicine($this->person, $worker);
        [$foreignMed] = $this->trackedMedicine($this->otherPerson, $worker);

        $this->migrate(self::STOCK_MIGRATION);
        $worker = $this->grantReceive($worker);

        $this->actingAs($worker)->get('/emar/stock/packs')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('can.receive', true)->where('can.manage', false)->where('can.receive_controlled', false)
            ->where('items.total', 1)->where('items.data.0.id', $med->id));
        $this->actingAs($worker)->getJson('/emar/stock/packs/medicine/'.$med->id)->assertOk();

        // Receive, then a pack photo of what arrived.
        $this->actingAs($worker)->postJson('/emar/stock/packs/commands', $this->receipt($med->id))->assertOk();
        $lot = MedicationStockLot::query()->where('client_medication_stock_id', $stock->id)->latest('id')->firstOrFail();
        $this->actingAs($worker)->post('/emar/stock/packs/'.$lot->id.'/photos', [
            'request_uuid' => (string) Str::uuid(),
            'photo' => UploadedFile::fake()->image('pack.jpg', 640, 480),
        ])->assertOk()->assertJsonPath('success', true);

        // Going out with the person is a receive-level action.
        $this->actingAs($worker)->postJson('/emar/stock/packs/commands', [
            'action' => 'going_out', 'client_medication_id' => $med->id, 'request_uuid' => (string) Str::uuid(),
            'lot_id' => $lot->id, 'quantity' => '1', 'reason' => 'Day trip with family',
        ])->assertOk();

        // Orders, removals and count sign-off stay with house leads.
        $this->actingAs($worker)->postJson('/emar/stock/packs/commands', $this->supplyOrder($med->id))->assertForbidden();
        $this->actingAs($worker)->postJson('/emar/stock/packs/commands', [
            'action' => 'move', 'client_medication_id' => $med->id, 'request_uuid' => (string) Str::uuid(),
            'lot_id' => $lot->id, 'kind' => 'damaged', 'quantity' => '1', 'reason' => 'Dropped',
        ])->assertForbidden();
        $this->actingAs($worker)->postJson('/emar/stock/packs/commands', [
            'action' => 'count_review', 'client_medication_id' => $med->id, 'request_uuid' => (string) Str::uuid(),
            'count_id' => 1, 'reason' => 'Reviewed',
        ])->assertForbidden();

        // A house the worker can't open stays not found.
        $this->actingAs($worker)->postJson('/emar/stock/packs/commands', $this->receipt($foreignMed->id))->assertNotFound();
    }

    public function test_a_controlled_delivery_needs_the_house_lead_even_when_the_worker_records_controlled_doses(): void
    {
        config(['medications.stock_lots_enabled' => true]);
        $worker = $this->staff('support_worker', $this->house);
        $this->person->supportWorkers()->attach($worker->id);
        [$med] = $this->trackedMedicine($this->person, $worker, controlled: true);
        $this->migrate(self::STOCK_MIGRATION);
        // Even with stock.receive granted to them directly.
        $worker = $this->grantReceive($worker);
        $this->assertTrue($worker->fresh()->canDo('medications.controlled.record'));

        $this->actingAs($worker)->postJson('/emar/stock/packs/commands', $this->receipt($med->id))->assertForbidden();
        $this->assertSame(0, MedicationStockLot::query()->count());
    }

    public function test_admin_lacks_errors_manage_on_a_migrated_environment_until_the_grant_migration_runs(): void
    {
        // EA-145 / EA-153. The P08b migration grants only these roles, so a
        // migrated (not seeded) environment's admin never got the key.
        $source = (string) file_get_contents(base_path('database/migrations/2026_10_03_160000_add_medication_error_workflow.php'));
        $this->assertStringContainsString("['team_lead', 'clinical_lead', 'coordinator', 'provider_manager']", $source);
        $permissionId = Permission::query()->where('key', 'medications.errors.manage')->value('id');
        $adminId = Role::query()->where('name', 'admin')->value('id');
        DB::table('role_permission')->where('role_id', $adminId)->where('permission_id', $permissionId)->delete();
        $before = $this->rolePermissionRows();

        $this->migrate(self::ERRORS_MIGRATION);
        $this->migrate(self::ERRORS_MIGRATION);

        $this->assertSame(['admin:medications.errors.manage'], $this->addedRows($before));
    }

    public function test_the_emergency_policy_grant_survives_an_rbac_reseed(): void
    {
        // EA-162: RbacSeeder now includes emergency_policy.manage and the C4/C1 grants.
        $this->migrate(self::EMERGENCY_POLICY_MIGRATION);
        $this->assertContains('medications.emergency_policy.manage', $this->roleKeys('provider_manager'));

        $this->seed(RbacSeeder::class);

        $this->assertContains('medications.emergency_policy.manage', $this->roleKeys('provider_manager'));
        $this->assertContains('medications.orders.manage', $this->roleKeys('team_lead'));
        $this->assertContains('medications.stock.receive', $this->roleKeys('team_lead'));
        $this->assertContains('medications.stock.update', $this->roleKeys('team_lead'));
        $this->assertContains('medications.downtime.manage', $this->roleKeys('team_lead'));
    }

    /** @return array{0: ClientMedication, 1: ?ClientMedicationStock} */
    private function trackedMedicine(Client $person, User $actor, bool $controlled = false): array
    {
        $med = ClientMedication::query()->forceCreate([
            'client_id' => $person->id, 'name' => $controlled ? 'Synthetic controlled medicine' : 'Synthetic test medicine',
            'dosage' => '1 tablet', 'frequency' => 'PRN', 'is_prn' => true, 'controlled_drug' => $controlled,
            'active' => true, 'state' => 'active', 'approval_status' => 'verified',
        ]);
        if ($controlled) {
            return [$med, null];
        }
        $stock = ClientMedicationStock::create(['client_medication_id' => $med->id, 'on_hand' => '10.00', 'unit' => 'tablets', 'last_counted_at' => now()->subDays(3)]);
        DB::transaction(fn () => app(MedicationStockService::class)->startLots($stock, $actor, (string) Str::uuid()));

        return [$med, $stock];
    }

    private function receipt(int $medicationId): array
    {
        return ['action' => 'receive', 'client_medication_id' => $medicationId, 'request_uuid' => (string) Str::uuid(),
            'quantity' => '4', 'source' => 'pharmacy', 'source_reference' => 'Synthetic delivery reference', 'unit' => 'tablets',
            'label_checked' => true, 'batch_number' => 'TEST-01', 'batch_not_printed' => false,
            'expiry_month' => now('Pacific/Auckland')->addMonths(6)->format('m/Y'), 'expiry_not_printed' => false];
    }

    private function supplyOrder(int $medicationId): array
    {
        return ['action' => 'order', 'client_medication_id' => $medicationId, 'request_uuid' => (string) Str::uuid(),
            'pharmacy_name' => 'Synthetic Pharmacy', 'quantity_ordered' => 28,
            'needed_by' => now('Pacific/Auckland')->addDays(3)->toDateString()];
    }

    private function entryInput(): array
    {
        return ['client_id' => $this->person->id, 'medication_id' => null, 'expected_version' => null,
            'request_key' => 'entry-'.bin2hex(random_bytes(8)), 'change_reason' => 'Prescriber source instruction',
            'source' => ['type' => 'written', 'prescriber' => 'Dr Test', 'received_at' => now()->subMinute()->toIso8601String(), 'description' => 'Signed source'],
            'source_file' => UploadedFile::fake()->create('prescription.pdf', 1, 'application/pdf'),
            'prescription' => ['name' => 'Example medicine', 'dosage' => '10 mg', 'dose_amount' => 10, 'dose_unit' => 'mg',
                'frequency' => 'Once daily', 'dose_times' => ['11:00'], 'is_prn' => false, 'route' => 'oral',
                'indication' => 'Indication from source', 'start_date' => now('Pacific/Auckland')->toDateString(),
                'controlled_drug' => false, 'high_risk' => false, 'witness_required' => false]];
    }

    private function checkInput(): array
    {
        return ['source_matches' => true, 'dose_route_times_checked' => true, 'allergies_interactions_checked' => true];
    }

    private function staff(string $role, Site $site, bool $shift = false): User
    {
        $user = User::factory()->create(['role' => $role, 'approved_at' => now()]);
        $user->roles()->syncWithoutDetaching([Role::query()->where('name', $role)->firstOrFail()->id]);
        HrEmployeeProfile::factory()->create(['user_id' => $user->id, 'primary_site_id' => $site->id, 'secondary_site_ids' => [],
            'start_date' => today()->subYear(), 'end_date' => null, 'is_active' => true]);
        if ($shift) {
            Shift::factory()->create(['client_id' => $this->person->id, 'site_id' => $site->id, 'user_id' => $user->id,
                'starts_at' => now()->subHours(2), 'ends_at' => now()->addHours(2), 'actual_starts_at' => now()->subHour(),
                'actual_ends_at' => null, 'started_by' => $user->id, 'status' => 'in_progress']);
        }

        return $user;
    }

    private function grantReceive(User $user): User
    {
        $user->permissionOverrides()->syncWithoutDetaching([Permission::query()->where('key', 'medications.stock.receive')->firstOrFail()->id => ['allowed' => true]]);
        Cache::flush();

        return $user->fresh();
    }

    private function migrate(string $path): void
    {
        (require base_path($path))->up();
        Cache::flush();
    }

    private function rollback(string $path): void
    {
        (require base_path($path))->down();
        Cache::flush();
    }

    /** @return list<string> */
    private function roleKeys(string $role): array
    {
        return DB::table('role_permission')
            ->join('roles', 'roles.id', '=', 'role_permission.role_id')
            ->join('permissions', 'permissions.id', '=', 'role_permission.permission_id')
            ->where('roles.name', $role)->orderBy('permissions.key')->pluck('permissions.key')->all();
    }

    /** @return list<string> */
    private function rolePermissionRows(): array
    {
        return DB::table('role_permission')
            ->join('roles', 'roles.id', '=', 'role_permission.role_id')
            ->join('permissions', 'permissions.id', '=', 'role_permission.permission_id')
            ->orderBy('roles.name')->orderBy('permissions.key')
            ->get(['roles.name', 'permissions.key'])
            ->map(fn ($row): string => $row->name.':'.$row->key)->all();
    }

    /** @return list<string> */
    private function addedRows(array $before): array
    {
        return array_values(array_diff($this->rolePermissionRows(), $before));
    }
}
