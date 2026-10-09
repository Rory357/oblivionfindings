<?php

namespace Tests\Feature\Emar;

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Models\AppSetting;
use App\Models\Client;
use App\Models\ClientMedication;
use App\Models\ClientMedicationStock;
use App\Models\MedicationCompetencyAssessment;
use App\Models\MedicationEvent;
use App\Models\MedicationStockLot;
use App\Models\MedicationStockPhoto;
use App\Models\Permission;
use App\Models\Role;
use App\Models\ServiceContext;
use App\Models\Shift;
use App\Models\Site;
use App\Models\User;
use App\Services\Medication\MedicineCatalogue\MedicineCatalogueBindingService;
use App\Services\Medication\Settings\MedicationSettingsRegistry;
use App\Services\Medication\Stock\MedicationStockService;
use App\Services\Medication\Stock\PackPhotoReader;
use App\Services\Medication\Stock\PackPhotoReceiptSettings;
use App\Services\Medication\Stock\PackPhotoWriter;
use Carbon\Carbon;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Lane C11: staff photos of the pack actually supplied are the core picture
 * of a medicine (approved P06 Q6, P02 Medicines › Photos, P11 Medicine
 * photos). The third-party catalogue is an optional extra.
 */
class StaffPackPhotosTest extends TestCase
{
    use RefreshDatabase;

    private const STOCK_MIGRATION = 'database/migrations/2026_10_09_230010_add_medication_stock_receive_permission.php';

    private Site $site;

    private ServiceContext $context;

    private Client $client;

    private User $worker;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-10-09 09:30:00', 'Pacific/Auckland')->utc());
        $this->seed(RbacSeeder::class);
        (require base_path(self::STOCK_MIGRATION))->up();
        Cache::flush();
        Storage::fake('private');
        config(['medications.stock_lots_enabled' => true]);
        $this->site = Site::factory()->create(['is_active' => true, 'name' => 'Kōwhai House']);
        $this->context = ServiceContext::factory()->create(['type' => 'residential', 'is_active' => true]);
        $this->client = Client::factory()->create(['site_id' => $this->site->id, 'service_context_id' => $this->context->id,
            'status' => 'active', 'first_name' => 'Aroha', 'last_name' => 'Ngata', 'preferred_name' => 'Aroha']);
        // Stephan, 10 Oct: only house leads hold stock.receive by role. Priya
        // is a support worker an admin has granted it directly, so these
        // tests also show the Medicine photos setting's "leads only" rule.
        $this->worker = $this->staff('support_worker', 'Priya Shah');
        $this->worker->permissionOverrides()->syncWithoutDetaching([Permission::query()->where('key', 'medications.stock.receive')->firstOrFail()->id => ['allowed' => true]]);
        Cache::flush();
        $this->onShift($this->worker);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_a_receipt_saves_its_pack_photo_in_the_same_request_and_the_dose_dialog_gets_it(): void
    {
        [$order] = $this->trackedOrder();

        $response = $this->actingAs($this->worker)->post('/emar/stock/packs/commands', [
            ...$this->receipt($order->id),
            'photo' => UploadedFile::fake()->image('pack.jpg', 800, 600),
            'photo_pack' => 0,
            'pack_changed' => '1',
        ], ['Accept' => 'application/json']);

        $response->assertOk()->assertJsonPath('success', true);
        $photo = MedicationStockPhoto::query()->sole();
        $this->assertSame((int) $response->json('photo_id'), (int) $photo->id);
        $this->assertTrue((bool) $photo->pack_changed);
        $this->assertSame($this->worker->id, (int) $photo->taken_by);
        $this->assertSame((int) $response->json('lot_id'), (int) $photo->medication_stock_lot_id);
        Storage::disk('private')->assertExists($photo->path);
        $this->assertTrue(MedicationEvent::query()->where('kind', 'stock.photo_added')->exists());

        $this->actingAs($this->worker)->getJson('/meds/today/doses/requirements?'.http_build_query([
            'client_medication_id' => $order->id,
            'scheduled_for' => Carbon::parse('2026-10-09 09:00', 'Pacific/Auckland')->toIso8601String(),
        ]))->assertOk()
            ->assertJsonPath('pack_photo.kind', 'staff')
            ->assertJsonPath('pack_photo.taken_by', 'Priya Shah')
            ->assertJsonPath('pack_photo.pack', 'Batch TEST-01 · Expires '.now('Pacific/Auckland')->addMonths(6)->format('m/Y'))
            ->assertJsonPath('pack_photo.pharmacy', 'Synthetic delivery reference')
            ->assertJsonPath('pack_photo.pack_changed', true)
            ->assertJsonPath('pack_photo.url', route('emar.stock.photos.view', $photo->id))
            ->assertJsonPath('pack_photo_policy.receipt_prompt', true);
    }

    public function test_a_failed_receipt_keeps_no_photo_bytes(): void
    {
        [$order] = $this->trackedOrder();
        $this->actingAs($this->worker)->post('/emar/stock/packs/commands', [
            ...$this->receipt($order->id),
            'expiry_month' => now('Pacific/Auckland')->subMonth()->format('m/Y'),
            'photo' => UploadedFile::fake()->image('pack.jpg'),
        ], ['Accept' => 'application/json'])->assertUnprocessable();

        $this->assertSame(0, MedicationStockPhoto::query()->count());
        $this->assertSame([], Storage::disk('private')->allFiles());
    }

    public function test_controlled_photos_are_concealed_from_readers_without_controlled_view(): void
    {
        $order = $this->orderRow(['name' => 'Oxycodone liquid', 'controlled_drug' => true]);
        $lot = $this->lotFor($order);
        $this->photograph($order, $lot);
        $reader = app(PackPhotoReader::class);

        $this->assertSame('staff', $reader->forOrder($this->worker, $order)['kind'] ?? null);
        $this->deny($this->worker, ['medications.controlled.view']);
        $this->assertNull($reader->forOrder($this->worker->fresh(), $order));

        $this->actingAs($this->worker->fresh())->getJson('/emar/clients/'.$this->client->id.'/record/photos')
            ->assertOk()->assertJsonPath('photos', [])->assertJsonPath('concealed', 1);
        $this->get('/emar/stock/packs/photos/'.MedicationStockPhoto::query()->sole()->id)->assertNotFound();
    }

    public function test_the_record_photo_history_is_newest_first_and_marks_current_changed_and_replaced(): void
    {
        $metformin = $this->orderRow(['name' => 'Metformin', 'dosage' => '500 mg']);
        $levetiracetam = $this->orderRow(['name' => 'Levetiracetam', 'dosage' => '250 mg']);
        Carbon::setTestNow(now()->subDays(30));
        $old = $this->photograph($metformin, $this->lotFor($metformin, 'MF-OLD'));
        Carbon::setTestNow(now()->addDays(10));
        $lev = $this->photograph($levetiracetam, $this->lotFor($levetiracetam, 'LV-1'));
        Carbon::setTestNow(now()->addDays(10));
        $new = $this->photograph($metformin, $this->lotFor($metformin, 'MF-NEW'), packChanged: true);
        Carbon::setTestNow(Carbon::parse('2026-10-09 09:30:00', 'Pacific/Auckland')->utc());

        $this->actingAs($this->worker)->getJson('/emar/clients/'.$this->client->id.'/record/photos')->assertOk()
            ->assertJsonPath('photos.0.id', $new->id)->assertJsonPath('photos.0.state', 'changed')
            ->assertJsonPath('photos.0.medication_strength', '500 mg')
            ->assertJsonPath('photos.1.id', $lev->id)->assertJsonPath('photos.1.state', 'current')
            ->assertJsonPath('photos.2.id', $old->id)->assertJsonPath('photos.2.state', 'replaced')
            ->assertJsonPath('photos.2.pack', fn ($pack) => str_starts_with($pack, 'Batch MF-OLD'))
            ->assertJsonPath('concealed', 0);
        $this->getJson('/emar/clients/'.$this->client->id.'/record/photos?medication_id='.$levetiracetam->id)->assertOk()
            ->assertJsonCount(1, 'photos')->assertJsonPath('photos.0.id', $lev->id);
        // The medicines list carries the approved "Pack photo" column.
        $this->getJson('/emar/clients/'.$this->client->id.'/record/medicines')->assertOk()
            ->assertJsonPath('rows', fn ($rows) => collect($rows)->firstWhere('id', $metformin->id)['pack_photo']['pack_changed'] === true);

        $foreign = Client::factory()->create(['site_id' => Site::factory()->create(['is_active' => true])->id, 'status' => 'active']);
        $this->getJson('/emar/clients/'.$foreign->id.'/record/photos')->assertNotFound();
        $this->getJson('/emar/clients/'.$this->client->id.'/record/photos?medication_id=999999')->assertNotFound();
    }

    public function test_the_receipt_prompt_follows_the_medicine_photos_setting(): void
    {
        [$order] = $this->trackedOrder();
        $policy = fn () => $this->actingAs($this->worker)->getJson('/emar/stock/packs/medicine/'.$order->id)->assertOk()->json('photo_policy');

        $this->assertSame(['can_photograph' => true, 'mode' => 'needed', 'prompt' => true, 'required' => false, 'has_photo' => false], $policy());
        $this->photograph($order, MedicationStockLot::query()->firstOrFail());
        $this->assertSame(['can_photograph' => true, 'mode' => 'needed', 'prompt' => false, 'required' => false, 'has_photo' => true], $policy());

        $this->setting(PackPhotoReceiptSettings::PROMPT, 'always');
        $this->assertTrue($policy()['prompt']);
        $this->setting(PackPhotoReceiptSettings::PROMPT, 'off');
        $this->assertSame('off', $policy()['mode']);
        $this->assertFalse($policy()['prompt']);
        $this->assertFalse(app(PackPhotoReceiptSettings::class)->receiptPromptOn());
    }

    public function test_leads_only_photos_refuse_a_support_worker_but_not_the_house_lead(): void
    {
        [$order] = $this->trackedOrder();
        $lot = MedicationStockLot::query()->firstOrFail();
        $this->setting(PackPhotoReceiptSettings::WHO, 'leads');

        $this->actingAs($this->worker)->post('/emar/stock/packs/'.$lot->id.'/photos', [
            'request_uuid' => (string) Str::uuid(), 'photo' => UploadedFile::fake()->image('pack.jpg'),
        ], ['Accept' => 'application/json'])->assertForbidden();
        $this->actingAs($this->worker)->post('/emar/stock/packs/commands', [
            ...$this->receipt($order->id), 'photo' => UploadedFile::fake()->image('pack.jpg'),
        ], ['Accept' => 'application/json'])->assertForbidden();
        $this->assertFalse($this->actingAs($this->worker)->getJson('/emar/stock/packs/medicine/'.$order->id)->json('photo_policy.can_photograph'));

        $lead = $this->staff('team_lead', 'Jordan Tipene');
        $this->actingAs($lead)->post('/emar/stock/packs/'.$lot->id.'/photos', [
            'request_uuid' => (string) Str::uuid(), 'photo' => UploadedFile::fake()->image('pack.jpg'), 'pack_changed' => '1',
        ], ['Accept' => 'application/json'])->assertOk();
        $this->assertTrue((bool) MedicationStockPhoto::query()->sole()->pack_changed);
    }

    public function test_a_controlled_delivery_can_require_a_photo_before_it_is_received(): void
    {
        $lead = $this->staff('team_lead', 'Jordan Tipene');
        $order = $this->orderRow(['name' => 'Oxycodone liquid', 'controlled_drug' => true]);
        $this->setting(PackPhotoReceiptSettings::CONTROLLED_REQUIRED, 'yes');

        $this->actingAs($lead)->postJson('/emar/stock/packs/commands', [...$this->receipt($order->id), 'unit' => 'mL'])
            ->assertUnprocessable()->assertJsonValidationErrors('photo');
        $this->assertSame(0, MedicationStockLot::query()->count());
        $this->assertTrue($this->actingAs($lead)->getJson('/emar/stock/packs/medicine/'.$order->id)->json('photo_policy.required'));
    }

    public function test_the_settings_are_registered_with_safe_defaults_and_loosening_ranks(): void
    {
        $registry = app(MedicationSettingsRegistry::class);
        $prompt = $registry->definition('packPhotoReceipt', 'prompt');
        $required = $registry->definition('packPhotoReceipt', 'controlled_required');
        $who = $registry->definition('packPhotoReceipt', 'who');

        $this->assertSame('needed', $prompt->default);
        $this->assertSame('no', $required->default);
        $this->assertSame('stock', $who->default);
        $this->assertSame('photos', $prompt->section);
        $this->assertTrue($prompt->loosens('needed', 'off'));
        $this->assertFalse($prompt->loosens('needed', 'always'));
        $this->assertTrue($required->loosens('yes', 'no'));
        $this->assertTrue($who->loosens('leads', 'stock'));
        $this->assertFalse($prompt->isSiteScoped());
    }

    public function test_the_catalogue_shows_nothing_until_a_reviewed_source_is_configured_and_bound(): void
    {
        $order = $this->orderRow(['name' => 'Metformin', 'form' => 'tablet']);
        $lead = $this->staff('team_lead', 'Jordan Tipene');
        $reader = app(PackPhotoReader::class);

        $this->assertNull($reader->forOrder($lead, $order));
        $this->actingAs($lead)->getJson('/emar/catalogue/medicines/'.$order->id.'/binding')->assertOk()
            ->assertJsonPath('catalogue_configured', false);

        $source = DB::table('medicine_catalogue_sources')->insertGetId([
            'supplier' => 'Synthetic', 'source_name' => 'Synthetic catalogue', 'source_version' => '2026.10', 'attribution' => 'Synthetic data',
            'licence_reference' => 'TEST', 'licence_attested_at' => now(), 'created_by' => $lead->id, 'status' => 'reviewed',
            'reviewed_by' => $lead->id, 'reviewed_at' => now()->subDay(), 'expires_at' => now()->addMonth(), 'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->assertTrue(PackPhotoReader::catalogueConfigured());
        $this->assertNull($reader->forOrder($lead, $order), 'Configured but not bound: still no reference image.');
        $product = DB::table('medicine_catalogue_products')->insertGetId([
            'source_id' => $source, 'code_system' => 'nzulm', 'code' => '1', 'name' => 'Metformin', 'strength' => '500 mg', 'form' => 'tablet',
            'identity_sha256' => str_repeat('a', 64), 'photo_path' => 'catalogue/metformin.png', 'photo_mime' => 'image/png', 'photo_sha256' => str_repeat('b', 64),
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('medicine_catalogue_bindings')->insert([
            'client_medication_id' => $order->id, 'medication_version' => (int) $order->version, 'product_id' => $product,
            'medicine_identity_sha256' => app(MedicineCatalogueBindingService::class)->identity($order), 'reference' => 'Label checked',
            'verified_by' => $lead->id, 'verified_at' => now(),
        ]);

        $reference = $reader->forOrder($lead, $order);
        $this->assertSame('reference', $reference['kind']);
        $this->assertSame('/emar/catalogue/products/'.$product.'/photo', $reference['url']);
        $this->assertSame('Synthetic catalogue 2026.10', $reference['source']);

        // A staff photo always wins over the reference image.
        $this->photograph($order, $this->lotFor($order));
        $this->assertSame('staff', $reader->forOrder($lead, $order)['kind']);
    }

    /** @return array{0: ClientMedication, 1: ClientMedicationStock} */
    private function trackedOrder(): array
    {
        $order = $this->orderRow();
        $stock = ClientMedicationStock::create(['client_medication_id' => $order->id, 'on_hand' => '10.00', 'unit' => 'tablets', 'last_counted_at' => now()->subDays(3)]);
        DB::transaction(fn () => app(MedicationStockService::class)->startLots($stock, $this->worker, (string) Str::uuid()));

        return [$order, $stock];
    }

    private function orderRow(array $overrides = []): ClientMedication
    {
        $now = Carbon::getTestNow();
        Carbon::setTestNow(Carbon::now('Pacific/Auckland')->startOfDay()->utc());
        $order = ClientMedication::query()->create(array_merge([
            'client_id' => $this->client->id, 'name' => 'Morning tablets', 'dosage' => '1 tablet', 'frequency' => 'Daily',
            'dose_times' => ['09:00'], 'is_prn' => false, 'controlled_drug' => false, 'high_risk' => false, 'witness_required' => false,
            'approval_status' => 'verified', 'active' => true, 'state' => 'active',
        ], $overrides))->fresh();
        Carbon::setTestNow($now);

        return $order;
    }

    private function lotFor(ClientMedication $order, string $batch = 'LOT-1'): MedicationStockLot
    {
        $stock = ClientMedicationStock::query()->firstOrCreate(['client_medication_id' => $order->id], ['on_hand' => '0.00', 'unit' => 'tablets']);

        return MedicationStockLot::query()->create([
            'client_medication_stock_id' => $stock->id, 'batch_number' => $batch, 'expiry_date' => now()->addYear()->toDateString(),
            'quantity_received' => '10.00', 'quantity_remaining' => '10.00', 'state' => 'open', 'source' => 'pharmacy',
            'source_reference' => 'Synthetic delivery', 'received_by' => $this->worker->id, 'received_at' => now(),
        ]);
    }

    private function photograph(ClientMedication $order, MedicationStockLot $lot, bool $packChanged = false): MedicationStockPhoto
    {
        return DB::transaction(function () use ($order, $lot, $packChanged) {
            $path = null;

            return app(PackPhotoWriter::class)->create($this->worker, $this->client, $order, $lot,
                UploadedFile::fake()->image('pack.jpg'), (string) Str::uuid(), null, $packChanged, $path);
        });
    }

    private function receipt(int $medicationId): array
    {
        return ['action' => 'receive', 'client_medication_id' => $medicationId, 'request_uuid' => (string) Str::uuid(),
            'quantity' => '4', 'source' => 'pharmacy', 'source_reference' => 'Synthetic delivery reference', 'unit' => 'tablets',
            'label_checked' => '1', 'batch_number' => 'TEST-01', 'batch_not_printed' => '0',
            'expiry_month' => now('Pacific/Auckland')->addMonths(6)->format('m/Y'), 'expiry_not_printed' => '0'];
    }

    private function setting(string $key, string $value): void
    {
        AppSetting::query()->updateOrCreate(['key' => $key], ['value' => $value]);
    }

    private function staff(string $role, string $name): User
    {
        $user = User::factory()->create(['role' => $role, 'approved_at' => now(), 'name' => $name]);
        $user->roles()->syncWithoutDetaching([Role::query()->where('name', $role)->firstOrFail()->id]);
        HrEmployeeProfile::factory()->create(['user_id' => $user->id, 'primary_site_id' => $this->site->id, 'secondary_site_ids' => [],
            'start_date' => now()->subMonth(), 'end_date' => null, 'is_active' => true]);
        $assessor = User::factory()->create(['role' => 'manager', 'approved_at' => now()]);
        MedicationCompetencyAssessment::query()->create([
            'user_id' => $user->id, 'assessor_id' => $assessor->id, 'assessment_type' => 'annual', 'status' => 'passed',
            'assessment_date' => now()->subMonth()->toDateString(), 'expiry_date' => now()->addYear()->toDateString(),
            'assessor_declared_at' => now()->subMonth(), 'staff_acknowledged_at' => now()->subMonth()->addMinute(),
            'can_administer_unsupervised' => true, 'controlled_drugs' => true, 'can_witness_controlled' => true,
        ]);
        Cache::flush();

        return $user;
    }

    private function onShift(User $user): void
    {
        Shift::factory()->create(['client_id' => $this->client->id, 'site_id' => $this->site->id, 'service_context_id' => $this->context->id,
            'user_id' => $user->id, 'starts_at' => now()->subHour(), 'ends_at' => now()->addHours(3),
            'actual_starts_at' => now()->subMinutes(30), 'actual_ends_at' => null, 'status' => 'in_progress']);
    }

    /** @param list<string> $keys */
    private function deny(User $user, array $keys): void
    {
        $user->permissionOverrides()->syncWithoutDetaching(Permission::query()->whereIn('key', $keys)->pluck('id')
            ->mapWithKeys(fn (int $id) => [$id => ['allowed' => false]])->all());
        Cache::flush();
    }
}
