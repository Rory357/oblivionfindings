<?php

namespace Tests\Feature\Emar;

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Models\Client;
use App\Models\ClientControlledDrugEntry;
use App\Models\ClientMedication;
use App\Models\ClientMedicationStock;
use App\Models\MedicationCompetencyAssessment;
use App\Models\Permission;
use App\Models\Role;
use App\Models\ServiceContext;
use App\Models\Shift;
use App\Models\Site;
use App\Models\User;
use App\Services\Medication\Controlled\ControlledCountStatus;
use App\Services\Medication\Controlled\ControlledRegisterService;
use App\Services\Medication\Stock\MedicationStockService;
use Carbon\Carbon;
use Database\Factories\UserFactory;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Tests\TestCase;

/**
 * Lane C2: controlled-register correctness (EA-012, EA-029, EA-073).
 */
class ControlledRegisterCloseOutTest extends TestCase
{
    use RefreshDatabase;

    private Site $site;

    private ServiceContext $context;

    private Client $client;

    private User $recorder;

    private User $witness;

    private User $lead;

    private ClientMedication $medication;

    private ClientMedicationStock $stock;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-10-09 09:30', 'Pacific/Auckland')->utc());
        $this->seed(RbacSeeder::class);
        Cache::flush();
        $this->site = Site::factory()->create(['is_active' => true, 'archived' => false, 'archived_at' => null, 'name' => 'Rimu House']);
        $this->context = ServiceContext::factory()->create(['type' => 'residential', 'is_active' => true]);
        $this->client = Client::factory()->create(['site_id' => $this->site->id, 'service_context_id' => $this->context->id, 'status' => 'active',
            'first_name' => 'Aroha', 'last_name' => 'Ngata']);
        $this->recorder = $this->staff('support_worker', 'Synthetic counter');
        $this->witness = $this->staff('support_worker', 'Synthetic witness');
        $this->lead = $this->staff('team_lead', 'Synthetic house lead');
        $this->medication = ClientMedication::query()->create([
            'client_id' => $this->client->id, 'name' => 'Oxycodone liquid', 'dosage' => '1 mL', 'frequency' => 'Daily',
            'dose_times' => ['09:30'], 'active' => true, 'state' => 'active', 'approval_status' => 'verified',
            'controlled_drug' => true, 'nz_controlled_class' => 'B', 'controlled_class_source' => 'Synthetic reviewed test configuration',
        ]);
        $this->stock = ClientMedicationStock::query()->create(['client_medication_id' => $this->medication->id, 'on_hand' => 14, 'unit' => 'mL']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_a_person_holding_controlled_stock_cannot_be_archived_from_any_screen(): void
    {
        try {
            $this->client->delete();
            $this->fail('Archiving a person who still holds controlled stock must be refused.');
        } catch (ValidationException $exception) {
            $this->assertStringContainsString('Oxycodone liquid — 14.00 mL on hand', $exception->errors()['client'][0]);
        }
        $this->assertFalse($this->client->fresh()->trashed());

        // Once the stock is cleared the person can be archived.
        $this->stock->forceFill(['on_hand' => '0.00'])->saveQuietly();
        $this->client->delete();
        $this->assertTrue($this->client->fresh()->trashed());
    }

    public function test_a_house_holding_controlled_stock_cannot_be_archived_or_made_inactive(): void
    {
        foreach ([['archived' => true, 'archived_at' => now(), 'is_active' => false], ['is_active' => false]] as $closing) {
            try {
                $this->site->fresh()->update($closing);
                $this->fail('Closing a house that still holds controlled stock must be refused.');
            } catch (ValidationException $exception) {
                $this->assertStringContainsString('Rimu House still holds controlled-drug stock', $exception->errors()['site'][0]);
            }
            $this->assertTrue((bool) $this->site->fresh()->is_active);
        }
        // Renaming an open house is unaffected.
        $this->site->fresh()->update(['name' => 'Rimu House (renamed)']);
        $this->assertSame('Rimu House (renamed)', $this->site->fresh()->name);
    }

    public function test_an_already_archived_person_keeps_their_controlled_stock_visible_and_countable_until_cleared(): void
    {
        // Archived before this guard existed (EA-012): bypass the model event.
        DB::table('clients')->where('id', $this->client->id)->update(['deleted_at' => now()->subDay()]);

        $payload = $this->actingAs($this->lead->fresh())->getJson('/emar/controlled/product')->assertOk()->json();
        $row = collect($payload['medicines'])->firstWhere('id', $this->medication->id);
        $this->assertNotNull($row, 'The archived person\'s controlled stock must stay in the register.');
        $this->assertSame('Aroha Ngata (archived)', $row['client_name']);
        $this->assertTrue($row['client_archived']);
        $this->assertContains('Aroha Ngata (archived)', collect($payload['people'])->pluck('name')->all());
        $this->assertTrue(app(ControlledCountStatus::class)->relevantMedicines()->contains('id', $this->medication->id));

        // Shifts for an archived person no longer place anyone at the house:
        // the count is done while on shift with another resident there.
        $housemate = Client::factory()->create(['site_id' => $this->site->id, 'service_context_id' => $this->context->id, 'status' => 'active']);
        foreach ([$this->lead, $this->witness] as $user) {
            Shift::factory()->create(['user_id' => $user->id, 'client_id' => $housemate->id, 'site_id' => $this->site->id,
                'service_context_id' => $this->context->id, 'starts_at' => now()->subHour()->utc(), 'ends_at' => now()->addHours(3)->utc(),
                'actual_starts_at' => now()->subMinutes(30)->utc(), 'actual_ends_at' => null, 'status' => 'in_progress']);
        }
        $result = $this->perform('count', ['actual_balance' => 14], $this->lead);
        $this->assertSame('Count saved — matches the register.', $result['message']);

        // Only close-out work is recorded for an archived person.
        $this->denied(404, fn () => $this->perform('class_review', ['nz_class' => 'B', 'source' => 'Synthetic'], $this->lead));
    }

    public function test_an_archived_person_with_nothing_left_is_not_reopened(): void
    {
        $this->stock->forceFill(['on_hand' => '0.00'])->saveQuietly();
        $this->client->delete();

        $payload = $this->actingAs($this->lead->fresh())->getJson('/emar/controlled/product')->assertOk()->json();
        $this->assertNull(collect($payload['medicines'])->firstWhere('id', $this->medication->id));
        $this->denied(404, fn () => $this->perform('count', ['actual_balance' => 0], $this->lead));
        $this->assertFalse(app(ControlledCountStatus::class)->relevantMedicines()->contains('id', $this->medication->id));
    }

    public function test_the_overview_cd_register_modal_payload_was_always_refused(): void
    {
        // EA-029/067: the Overview's "Start count" modal posted these generic
        // fields. The register refuses them, so the Overview now links to the
        // register's own witnessed count instead (the modal is removed).
        $this->actingAs($this->recorder->fresh())->postJson('/emar/controlled/entries', [
            'client_id' => $this->client->id, 'client_medication_id' => $this->medication->id, 'medication_name' => 'Oxycodone liquid',
            'entry_type' => 'adjustment', 'quantity' => '1', 'unit' => 'mL', 'on_hand_before' => '14', 'on_hand_after' => '13',
            'witnessed_by' => $this->witness->id, 'witness_credential' => UserFactory::TEST_WITNESS_PIN, 'client_request_uuid' => (string) Str::uuid(),
        ])->assertStatus(422);
        $this->assertSame(0, ClientControlledDrugEntry::query()->count());
        $source = (string) file_get_contents(resource_path('js/pages/emar/Index.tsx'));
        $this->assertStringNotContainsString('CdRegisterModal', $source);
        $this->assertStringContainsString('/emar/controlled?client_id=', $source);
    }

    public function test_a_held_medicine_can_still_be_received_and_reordered_in_the_stock_hub(): void
    {
        // EA-073: Orders › Hold pauses giving, not supply.
        config(['medications.stock_lots_enabled' => true]);
        (require base_path('database/migrations/2026_10_09_230010_add_medication_stock_receive_permission.php'))->up();
        Cache::flush();
        $held = ClientMedication::query()->create([
            'client_id' => $this->client->id, 'name' => 'Warfarin', 'dosage' => '1 tablet', 'frequency' => 'Daily',
            'dose_times' => ['17:00'], 'active' => false, 'state' => 'paused', 'paused_at' => now(), 'approval_status' => 'verified',
            'controlled_drug' => false,
        ]);
        $stock = ClientMedicationStock::query()->create(['client_medication_id' => $held->id, 'on_hand' => '5.00', 'unit' => 'tablets']);
        DB::transaction(fn () => app(MedicationStockService::class)->startLots($stock, $this->lead, (string) Str::uuid()));
        $lead = $this->lead->fresh();

        $this->actingAs($lead)->postJson('/emar/stock/packs/commands', [
            'action' => 'receive', 'client_medication_id' => $held->id, 'request_uuid' => (string) Str::uuid(),
            'quantity' => '28', 'source' => 'pharmacy', 'source_reference' => 'Weekly delivery', 'unit' => 'tablets', 'label_checked' => true,
            'batch_number' => 'WF-1', 'batch_not_printed' => false, 'expiry_month' => now('Pacific/Auckland')->addMonths(6)->format('m/Y'), 'expiry_not_printed' => false,
        ])->assertOk();
        $this->actingAs($lead)->postJson('/emar/stock/packs/commands', [
            'action' => 'order', 'client_medication_id' => $held->id, 'request_uuid' => (string) Str::uuid(),
            'pharmacy_name' => 'Synthetic Pharmacy', 'quantity_ordered' => 28, 'needed_by' => now('Pacific/Auckland')->addDays(3)->toDateString(),
        ])->assertOk();
        $this->actingAs($lead)->getJson('/emar/stock/packs/medicine/'.$held->id)->assertOk()
            ->assertJsonPath('receivable', true)->assertJsonPath('held', true)->assertJsonPath('active', false);

        // A stopped order still can't receive.
        $held->forceFill(['state' => 'ceased', 'active' => false])->saveQuietly();
        $this->actingAs($lead)->postJson('/emar/stock/packs/commands', [
            'action' => 'receive', 'client_medication_id' => $held->id, 'request_uuid' => (string) Str::uuid(),
            'quantity' => '28', 'source' => 'pharmacy', 'source_reference' => 'Late delivery', 'unit' => 'tablets', 'label_checked' => true,
            'batch_number' => 'WF-2', 'batch_not_printed' => false, 'expiry_month' => now('Pacific/Auckland')->addMonths(6)->format('m/Y'), 'expiry_not_printed' => false,
        ])->assertUnprocessable()->assertJsonValidationErrors('client_medication_id');
    }

    private function perform(string $action, array $fields = [], ?User $actor = null): array
    {
        $actor = ($actor ?? $this->recorder)->fresh();
        $this->actingAs($actor);

        return app(ControlledRegisterService::class)->perform($actor, $action, [
            'client_medication_id' => $this->medication->id, 'client_request_uuid' => (string) Str::uuid(),
            'expected_balance' => $this->stock->refresh()->on_hand,
            'expected_entry_id' => ClientControlledDrugEntry::query()->where('client_medication_id', $this->medication->id)->latest('id')->value('id'),
            'witnessed_by' => $this->witness->id, 'witness_credential' => UserFactory::TEST_WITNESS_PIN, ...$fields,
        ]);
    }

    private function denied(int $status, callable $action): void
    {
        try {
            $action();
            $this->fail('Expected the register to refuse this.');
        } catch (HttpExceptionInterface $exception) {
            $this->assertSame($status, $exception->getStatusCode());
        }
    }

    private function staff(string $roleName, string $name): User
    {
        $user = User::factory()->create(['role' => $roleName, 'approved_at' => now(), 'name' => $name]);
        $user->roles()->syncWithoutDetaching([Role::query()->where('name', $roleName)->sole()->id]);
        $user->permissionOverrides()->syncWithoutDetaching(Permission::query()->whereIn('key', [
            'medications.view', 'medications.administer.record', 'medications.controlled.view', 'medications.controlled.record',
            'medications.controlled.witness', 'medications.stock.update',
        ])->get()->mapWithKeys(fn (Permission $permission): array => [$permission->id => ['allowed' => $permission->key !== 'medications.stock.update' || $roleName === 'team_lead']])->all());
        HrEmployeeProfile::factory()->create(['user_id' => $user->id, 'primary_site_id' => $this->site->id, 'secondary_site_ids' => [],
            'start_date' => now()->subYear(), 'end_date' => null, 'is_active' => true]);
        MedicationCompetencyAssessment::query()->create([
            'user_id' => $user->id, 'assessor_id' => User::factory()->create()->id, 'assessment_type' => 'annual',
            'status' => 'passed', 'assessment_date' => now()->subMonth()->toDateString(), 'expiry_date' => now()->addYear()->toDateString(),
            'assessor_declared_at' => now()->subMonth(), 'staff_acknowledged_at' => now()->subMonth()->addMinute(),
            'can_administer_unsupervised' => true, 'can_witness_controlled' => true, 'controlled_drugs' => true,
            'restricted' => false, 'not_seen_areas' => [],
        ]);
        Shift::factory()->create([
            'user_id' => $user->id, 'client_id' => $this->client->id, 'site_id' => $this->site->id,
            'service_context_id' => $this->context->id, 'starts_at' => now()->subHour()->utc(), 'ends_at' => now()->addHours(3)->utc(),
            'actual_starts_at' => now()->subMinutes(30)->utc(), 'actual_ends_at' => null, 'status' => 'in_progress',
        ]);
        Cache::flush();

        return $user;
    }
}
