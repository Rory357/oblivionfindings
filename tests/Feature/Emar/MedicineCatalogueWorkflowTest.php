<?php

namespace Tests\Feature\Emar;

use App\Models\Client;
use App\Models\ClientMedication;
use App\Models\MedicineCatalogueProduct;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use App\Services\Medication\MedicineCatalogue\MedicineCatalogueService;
use Carbon\Carbon;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class MedicineCatalogueWorkflowTest extends TestCase
{
    use RefreshDatabase;

    private User $manager;

    private Site $site;

    private ClientMedication $medicine;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-10-07 00:00', 'Pacific/Auckland')->utc());
        $this->seed(RbacSeeder::class);
        foreach (['medications.catalogue.manage', 'medications.backups.manage'] as $key) {
            Permission::query()->firstOrCreate(['key' => $key], ['description' => 'Synthetic connected care permission', 'group' => 'medications', 'module' => 'Clinical']);
        }
        if (! Route::has('emar.catalogue.index')) {
            Route::middleware('web')->group(base_path('routes/emar-catalogue-backups.php'));
        }
        Storage::fake('private');
        $this->site = Site::factory()->create(['is_active' => true, 'archived' => false]);
        $client = Client::factory()->create(['site_id' => $this->site->id, 'status' => 'active']);
        $this->manager = User::factory()->create(['role' => 'team_lead', 'approved_at' => now()]);
        $this->manager->roles()->attach(Role::query()->where('name', 'team_lead')->firstOrFail());
        ensureCanonicalHrStaffProfile($this->manager, $this->site, ['start_date' => '2025-01-01']);
        $ids = Permission::query()->whereIn('key', ['medications.view', 'medications.catalogue.manage', 'medications.stock.update', 'clients.viewAny'])->pluck('id');
        $this->manager->permissionOverrides()->sync($ids->mapWithKeys(fn ($id) => [$id => ['allowed' => true]])->all());
        $this->manager = $this->manager->fresh();
        $this->medicine = ClientMedication::factory()->create(['client_id' => $client->id, 'name' => 'Fictional medicine', 'form' => 'tablet', 'nzulm_code' => 'FICT-001', 'dosage' => '5 mg', 'dose_amount' => 5, 'dose_unit' => 'mg', 'version' => 1, 'active' => true, 'state' => 'active', 'approval_status' => 'verified', 'verified_at' => now(), 'start_date' => '2026-10-06', 'end_date' => null, 'controlled_drug' => false, 'dose_times' => ['09:00']]);
        Carbon::setTestNow(Carbon::parse('2026-10-07 12:00', 'Pacific/Auckland')->utc());
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_empty_installation_does_not_claim_a_supplier_catalogue(): void
    {
        $this->actingAs($this->manager)->getJson('/emar/catalogue/match?'.http_build_query($this->product()))->assertOk()->assertJsonPath('status', 'unavailable')->assertJsonMissingPath('product');
        $this->assertDatabaseCount('medicine_catalogue_sources', 0);
    }

    public function test_licensed_reviewed_exact_product_has_private_validated_photo_and_attribution(): void
    {
        [$source, $product] = $this->reviewed();
        $this->actingAs($this->manager)->getJson('/emar/catalogue/match?'.http_build_query($this->product()))->assertOk()->assertJsonPath('status', 'matched')->assertJsonPath('product.strength', '10 mg')->assertJsonPath('source.source_version', 'fiction-v1')->assertJsonPath('source.attribution', 'Fictional licensed test dataset');
        $this->actingAs($this->manager)->get('/emar/catalogue/products/'.$product->id.'/photo')->assertOk()->assertHeader('Content-Type', 'image/png')->assertHeader('Cache-Control', 'no-store, private');
        $this->assertSame($this->manager->id, $source->reviewed_by);
        $this->assertStringNotContainsString('photo_path', json_encode(app(MedicineCatalogueService::class)->page($this->manager)));
    }

    public function test_different_strength_form_or_code_never_returns_a_near_match(): void
    {
        $this->reviewed();
        foreach (['strength' => '5 mg', 'form' => 'capsule', 'code' => 'OTHER'] as $field => $value) {
            $this->actingAs($this->manager)->getJson('/emar/catalogue/match?'.http_build_query(array_replace($this->product(), [$field => $value])))->assertOk()->assertJsonPath('status', 'no_exact_match')->assertJsonMissingPath('product');
        }
    }

    public function test_draft_expired_and_revoked_sources_do_not_supply_a_photo_match(): void
    {
        [$source, $product] = $this->draft();
        $this->actingAs($this->manager)->getJson('/emar/catalogue/match?'.http_build_query($this->product()))->assertJsonPath('status', 'review_required');
        $source = app(MedicineCatalogueService::class)->review($this->manager, $source->id, $source->version, now()->addDay()->toIso8601String());
        Carbon::setTestNow(now()->addDays(2));
        $this->actingAs($this->manager)->getJson('/emar/catalogue/match?'.http_build_query($this->product()))->assertJsonPath('status', 'expired');
        $this->actingAs($this->manager)->get('/emar/catalogue/products/'.$product->id.'/photo')->assertNotFound();
        app(MedicineCatalogueService::class)->revoke($this->manager, $source->id, $source->version);
        $this->actingAs($this->manager)->getJson('/emar/catalogue/match?'.http_build_query($this->product()))->assertJsonPath('status', 'review_required');
    }

    public function test_published_source_is_immutable_and_stale_version_has_no_effects(): void
    {
        [$source, $product] = $this->reviewed();
        $before = $source->getRawOriginal();
        $this->actingAs($this->manager)->postJson('/emar/catalogue/sources/'.$source->id.'/revoke', ['version' => $source->version - 1])->assertConflict();
        $this->actingAs($this->manager)->post('/emar/catalogue/sources/'.$source->id.'/products/'.$product->id.'/photo', ['version' => $source->version, 'request_uuid' => (string) Str::uuid(), 'photo' => $this->image()])->assertConflict();
        $this->assertSame($before, $source->fresh()->getRawOriginal());
        $this->assertCount(1, Storage::disk('private')->allFiles());
    }

    public function test_repeated_photo_request_uses_one_owned_file_and_conflicting_replay_is_denied(): void
    {
        $service = app(MedicineCatalogueService::class);
        $source = $service->create($this->manager, $this->source());
        $source = $service->import($this->manager, $source->id, $source->version, json_encode([$this->product()]));
        $product = $source->products()->sole();
        $uuid = (string) Str::uuid();
        $first = $service->photo($this->manager, $source->id, $product->id, $source->version, $uuid, $this->image());
        $second = $service->photo($this->manager, $source->id, $product->id, $source->version, $uuid, $this->image());
        $this->assertSame($first->photo_path, $second->photo_path);
        $this->assertCount(1, Storage::disk('private')->allFiles());
        $this->assertSame(3, $source->fresh()->version);
    }

    public function test_unlicensed_and_nonmanager_writes_are_denied_without_rows(): void
    {
        $this->actingAs($this->manager)->postJson('/emar/catalogue/sources', array_replace($this->source(), ['licence_attested' => false]))->assertUnprocessable();
        $permission = Permission::query()->where('key', 'medications.catalogue.manage')->sole();
        $this->manager->permissionOverrides()->syncWithoutDetaching([$permission->id => ['allowed' => false]]);
        $this->actingAs($this->manager)->postJson('/emar/catalogue/sources', $this->source())->assertForbidden();
        $this->assertDatabaseCount('medicine_catalogue_sources', 0);
    }

    public function test_package_binding_uses_product_strength_not_prescribed_dose_and_becomes_stale(): void
    {
        [$source, $product] = $this->reviewed();
        $this->actingAs($this->manager)->postJson('/emar/catalogue/medicines/'.$this->medicine->id.'/binding', $this->binding($product))->assertOk()->assertJsonPath('product.strength', '10 mg')->assertJsonPath('medicine_version', 1);
        $this->assertSame('5 mg', $this->medicine->fresh()->dosage);
        $this->medicine->forceFill(['version' => 2])->saveQuietly();
        $this->actingAs($this->manager)->getJson('/emar/catalogue/medicines/'.$this->medicine->id.'/binding')->assertOk()->assertJsonPath('status', 'review_required')->assertJsonMissingPath('product');
        $this->assertDatabaseCount('medicine_catalogue_bindings', 1);
    }

    public function test_missing_confirmation_wrong_identity_and_stale_order_do_not_create_binding(): void
    {
        [, $product] = $this->reviewed();
        $url = '/emar/catalogue/medicines/'.$this->medicine->id.'/binding';
        $this->actingAs($this->manager)->postJson($url, array_replace($this->binding($product), ['product_label_confirmed' => false]))->assertUnprocessable();
        $this->actingAs($this->manager)->postJson($url, array_replace($this->binding($product), ['expected_medication_version' => 8]))->assertConflict();
        $this->medicine->forceFill(['form' => 'capsule'])->saveQuietly();
        $this->actingAs($this->manager)->postJson($url, $this->binding($product))->assertUnprocessable();
        $this->assertDatabaseCount('medicine_catalogue_bindings', 0);
    }

    public function test_controlled_and_foreign_medicine_binding_is_concealed_with_missing_id_parity(): void
    {
        [, $product] = $this->reviewed();
        $this->medicine->forceFill(['controlled_drug' => true])->saveQuietly();
        $permission = Permission::query()->where('key', 'medications.controlled.view')->firstOrFail();
        $this->manager->permissionOverrides()->syncWithoutDetaching([$permission->id => ['allowed' => false]]);
        foreach ([$this->medicine->id, 99999999] as $id) {
            $this->actingAs($this->manager)->getJson('/emar/catalogue/medicines/'.$id.'/binding')->assertNotFound();
            $this->actingAs($this->manager)->postJson('/emar/catalogue/medicines/'.$id.'/binding', $this->binding($product))->assertNotFound();
        }
        $this->assertDatabaseCount('medicine_catalogue_bindings', 0);
    }

    public function test_source_and_product_picker_pagination_keep_later_records_reachable(): void
    {
        [$source, $product] = $this->reviewed();
        for ($index = 2; $index <= 12; $index++) {
            app(MedicineCatalogueService::class)->create($this->manager, array_replace($this->source(), ['source_version' => 'fiction-v'.$index]));
        }
        $page = app(MedicineCatalogueService::class)->page($this->manager, 2);
        $this->assertSame(12, $page['source_meta']['total']);
        $this->assertCount(2, $page['sources']);
        $this->actingAs($this->manager)->getJson('/emar/catalogue/medicines/'.$this->medicine->id.'/products?q=10&page=1')->assertOk()->assertJsonPath('pagination.total', 1)->assertJsonPath('products.0.id', $product->id)->assertJsonPath('products.0.strength', '10 mg');
    }

    public function test_corrupted_bound_photo_becomes_review_required_without_serving_wrong_bytes(): void
    {
        [, $product] = $this->reviewed();
        $this->actingAs($this->manager)->postJson('/emar/catalogue/medicines/'.$this->medicine->id.'/binding', $this->binding($product))->assertOk();
        Storage::disk('private')->put($product->photo_path, 'Invalid changed image bytes');
        $this->actingAs($this->manager)->getJson('/emar/catalogue/medicines/'.$this->medicine->id.'/binding')->assertOk()->assertJsonPath('status', 'review_required')->assertJsonMissingPath('product');
        $this->actingAs($this->manager)->get('/emar/catalogue/products/'.$product->id.'/photo')->assertNotFound();
    }

    private function reviewed(): array
    {
        [$source, $product] = $this->draft();
        $source = app(MedicineCatalogueService::class)->review($this->manager, $source->id, $source->version, now()->addMonth()->toIso8601String());

        return [$source, $product];
    }

    private function draft(): array
    {
        $service = app(MedicineCatalogueService::class);
        $source = $service->create($this->manager, $this->source());
        $source = $service->import($this->manager, $source->id, $source->version, json_encode([$this->product()]));
        $product = $source->products()->sole();
        $product = $service->photo($this->manager, $source->id, $product->id, $source->version, (string) Str::uuid(), $this->image());

        return [$source->fresh(), $product];
    }

    private function source(): array
    {
        return ['supplier' => 'Fictional owner', 'source_name' => 'Synthetic licensed catalogue', 'source_version' => 'fiction-v1', 'attribution' => 'Fictional licensed test dataset', 'licence_reference' => 'Synthetic owner-approved licence evidence', 'licence_attested' => true];
    }

    private function product(): array
    {
        return ['code_system' => 'nzulm', 'code' => 'FICT-001', 'name' => 'Fictional medicine', 'strength' => '10 mg', 'form' => 'tablet'];
    }

    private function binding(MedicineCatalogueProduct $product): array
    {
        return ['product_id' => $product->id, 'expected_medication_version' => 1, 'product_label_confirmed' => true, 'reference' => 'Compared fictional package label'];
    }

    private function image(): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'catalogue-fixture-');
        file_put_contents($path, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+a9e0AAAAASUVORK5CYII='));
        $this->beforeApplicationDestroyed(fn () => is_file($path) ? unlink($path) : null);

        return new UploadedFile($path, 'fictional.png', 'image/png', null, true);
    }
}
