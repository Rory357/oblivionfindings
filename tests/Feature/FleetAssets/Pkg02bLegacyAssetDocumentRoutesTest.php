<?php

namespace Tests\Feature\FleetAssets;

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Models\Asset;
use App\Models\AssetDocument;
use App\Models\AssetDocumentSet;
use App\Models\FleetVehicleComplianceRecord;
use App\Models\FleetVehicleComplianceVersion;
use App\Models\Permission;
use App\Models\Site;
use App\Models\User;
use App\Services\Files\MalwareScanDisposition;
use App\Services\Files\MalwareScanner;
use App\Services\Files\MalwareScanResult;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * PKG-02B security follow-up: the older asset-register routes
 * (/assets/{asset}/documents/...) serve the same asset_documents rows as the
 * vehicle profile, so they must not bypass its private-file rules — clean
 * scans only, vehicle access rechecked, Finance evidence withheld, files
 * archived rather than deleted and new vehicle files virus-checked.
 */
class Pkg02bLegacyAssetDocumentRoutesTest extends TestCase
{
    use RefreshDatabase;

    private Site $site;

    private Site $foreignSite;

    private object $scanner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RbacSeeder::class);
        Storage::fake('private');
        Storage::fake('local');
        $this->travelTo(Carbon::parse('2026-09-22 09:30:00', 'Pacific/Auckland')->utc());
        $this->site = Site::factory()->create(['name' => 'Kōwhai House']);
        $this->foreignSite = Site::factory()->create(['name' => 'Rimu House']);
        // A controllable scanner: no real scanning binary is configured in tests.
        $this->scanner = new class extends MalwareScanner
        {
            public MalwareScanDisposition $next = MalwareScanDisposition::Clean;

            public function scanPath(string $path, array $settings): MalwareScanResult
            {
                return new MalwareScanResult($this->next, 'test-scanner', $this->next === MalwareScanDisposition::Unavailable ? 'scanner_unavailable' : null);
            }
        };
        $this->app->instance(MalwareScanner::class, $this->scanner);
    }

    public function test_the_register_download_refuses_vehicle_files_that_have_not_passed_their_virus_check(): void
    {
        $manager = $this->manager();
        $vehicle = $this->vehicle($this->site);

        $this->scanner->next = MalwareScanDisposition::Infected;
        $infected = $this->upload($manager, $vehicle, 'infected-upload', 'invoice.pdf');
        $this->scanner->next = MalwareScanDisposition::Unavailable;
        $waiting = $this->upload($manager, $vehicle, 'waiting-upload', 'warranty.pdf');
        $this->assertSame(['quarantined', 'scan_unavailable'], [$infected->state, $waiting->state]);

        $withheld = [$infected, $waiting];
        foreach (['reserved', 'stored', 'publication_failed', 'storage_failed'] as $state) {
            $withheld[] = $this->managedFile($vehicle, ['state' => $state]);
        }
        foreach ($withheld as $file) {
            // The bytes are in storage; only the state keeps them closed.
            Storage::disk('private')->assertExists($file->storage_path);
            $this->actingAs($manager)->get($this->registerUrl($vehicle, $file))->assertStatus(409);
        }

        // A pre-PKG-02B file that fails its first check is withheld as well.
        $legacy = $this->legacyVehicleFile($vehicle);
        $this->actingAs($manager)->get($this->registerUrl($vehicle, $legacy))->assertOk();
        $this->scanner->next = MalwareScanDisposition::Infected;
        $this->actingAs($manager)->postJson("/fleet-assets/vehicles/{$vehicle->id}/document-files/{$legacy->id}/verify")
            ->assertOk()->assertJsonPath('file.state', 'quarantined');
        $this->actingAs($manager)->get($this->registerUrl($vehicle, $legacy))->assertStatus(409);

        $this->assertDatabaseMissing('audit_logs', ['action' => 'assets.documents.download']);
    }

    public function test_clean_vehicle_files_open_from_the_register_through_the_vehicle_profile_download(): void
    {
        $manager = $this->manager();
        $vehicle = $this->vehicle($this->site);
        $clean = $this->upload($manager, $vehicle, 'clean-upload', 'policy.pdf');
        $legacy = $this->legacyVehicleFile($vehicle);
        $photo = $this->uploadPhoto($manager, $vehicle);

        foreach ([$clean, $legacy, $photo] as $file) {
            $this->assertVehicleDownload($this->actingAs($manager)->get($this->registerUrl($vehicle, $file)));
        }
        $this->assertDatabaseHas('audit_logs', ['action' => 'fleet.vehicle.document.download', 'auditable_id' => $clean->id]);
        $this->assertDatabaseMissing('audit_logs', ['action' => 'assets.documents.download']);

        // An auditor reads the register without the Fleet module. The vehicle
        // route is closed to them, so the register keeps serving the file,
        // with the same checks and headers.
        $auditor = $this->siteUser([$this->site], ['assets.viewAny']);
        $this->actingAs($auditor)->get("/fleet-assets/vehicles/{$vehicle->id}/documents/{$clean->id}/file")->assertForbidden();
        $this->assertVehicleDownload($this->actingAs($auditor)->get($this->registerUrl($vehicle, $clean)));

        // Another vehicle's file, and vehicles at other sites, stay concealed.
        $other = $this->vehicle($this->site);
        $this->actingAs($manager)->get("/assets/{$other->id}/documents/{$clean->id}/download")->assertNotFound();
        $foreign = $this->vehicle($this->foreignSite);
        $foreignFile = $this->managedFile($foreign);
        $this->actingAs($manager)->get($this->registerUrl($foreign, $foreignFile))->assertNotFound();
    }

    public function test_finance_review_evidence_opens_from_the_register_only_for_finance_viewers(): void
    {
        $viewer = $this->siteUser([$this->site], ['fleet.viewAny', 'assets.viewAny']);
        $finance = $this->siteUser([$this->site], ['fleet.viewAny', 'assets.viewAny', 'finance.assets.view']);
        $vehicle = $this->vehicle($this->site);
        $quote = $this->managedFile($vehicle, ['source_type' => 'finance_review_request', 'source_id' => 41]);
        $infectedQuote = $this->managedFile($vehicle, ['source_type' => 'finance_review_request', 'source_id' => 41, 'state' => 'quarantined']);

        $this->actingAs($viewer)->get($this->registerUrl($vehicle, $quote))->assertNotFound();
        // Concealed before the virus check is even considered.
        $this->actingAs($viewer)->get($this->registerUrl($vehicle, $infectedQuote))->assertNotFound();

        $this->assertVehicleDownload($this->actingAs($finance)->get($this->registerUrl($vehicle, $quote)));
        $this->actingAs($finance)->get($this->registerUrl($vehicle, $infectedQuote))->assertStatus(409);
    }

    public function test_the_register_cannot_delete_files_the_vehicle_profile_keeps(): void
    {
        $manager = $this->manager();
        $vehicle = $this->vehicle($this->site);
        $clean = $this->upload($manager, $vehicle, 'keep-clean', 'policy.pdf');
        $this->scanner->next = MalwareScanDisposition::Infected;
        $infected = $this->upload($manager, $vehicle, 'keep-infected', 'invoice.pdf');
        $this->scanner->next = MalwareScanDisposition::Clean;
        $photo = $this->uploadPhoto($manager, $vehicle);
        $legacy = $this->legacyVehicleFile($vehicle);
        $odometerPhoto = $this->managedFile($vehicle, ['source_type' => 'odometer_observation', 'source_id' => 7]);
        // A plain register upload that a compliance version holds as its evidence.
        $wofSheet = $this->plainFile($vehicle);
        $this->holdAsComplianceEvidence($vehicle, $wofSheet);

        foreach ([$clean, $infected, $photo, $legacy, $odometerPhoto, $wofSheet] as $file) {
            $this->actingAs($manager)->delete($this->registerDeleteUrl($vehicle, $file))->assertStatus(409);
            $this->assertModelExists($file);
            Storage::disk($file->storage_disk)->assertExists($file->storage_path);
        }
        $this->assertSame($photo->id, $vehicle->fresh()->profile_photo_document_id);

        // Archiving in the vehicle profile is the way to retire a file; it stays kept.
        $this->actingAs($manager)->postJson("/fleet-assets/vehicles/{$vehicle->id}/document-files/{$clean->id}/archive", [
            'reason' => 'Superseded by the renewed policy', 'request_key' => 'archive-clean',
        ])->assertOk();
        $this->actingAs($manager)->delete($this->registerDeleteUrl($vehicle, $clean))->assertStatus(409);
        Storage::disk('private')->assertExists($clean->storage_path);

        // A loose register upload that nothing holds is still the register's to delete.
        $loose = $this->plainFile($vehicle);
        $this->actingAs($manager)->delete($this->registerDeleteUrl($vehicle, $loose))->assertRedirect();
        $this->assertModelMissing($loose);
        Storage::disk('local')->assertMissing($loose->storage_path);
    }

    public function test_the_register_refuses_new_vehicle_uploads(): void
    {
        $manager = $this->manager();
        $vehicle = $this->vehicle($this->site);

        $this->actingAs($manager)->post("/assets/{$vehicle->id}/documents", [
            'file' => $this->pdf('wof-sheet.pdf'), 'title' => 'WoF sheet',
        ])->assertSessionHasErrors([
            'file' => 'Add vehicle documents in the vehicle profile. Files are virus-checked there before anyone can open them.',
        ]);

        $this->assertSame(0, AssetDocument::query()->where('asset_id', $vehicle->id)->count());
        $this->assertSame([], Storage::disk('local')->allFiles());
    }

    public function test_other_assets_keep_register_upload_download_and_delete(): void
    {
        $manager = $this->manager();
        $hoist = $this->equipment($this->site);

        $this->actingAs($manager)->post("/assets/{$hoist->id}/documents", [
            'file' => $this->pdf('hoist-manual.pdf'), 'title' => 'Hoist manual',
        ])->assertSessionHasNoErrors();
        $file = AssetDocument::query()->where('asset_id', $hoist->id)->sole();
        $this->assertFalse($file->isVehicleManaged());
        Storage::disk('local')->assertExists($file->storage_path);

        $this->actingAs($manager)->get($this->registerUrl($hoist, $file))->assertOk()->assertDownload('hoist-manual.pdf');
        $this->assertDatabaseHas('audit_logs', ['action' => 'assets.documents.download', 'auditable_id' => $file->id]);

        $this->actingAs($manager)->delete($this->registerDeleteUrl($hoist, $file))->assertRedirect();
        $this->assertModelMissing($file);
        Storage::disk('local')->assertMissing($file->storage_path);
    }

    public function test_the_register_lists_vehicle_documents_without_source_owned_evidence(): void
    {
        $manager = $this->manager();
        $vehicle = $this->vehicle($this->site);
        $clean = $this->upload($manager, $vehicle, 'list-clean', 'policy.pdf');
        $this->scanner->next = MalwareScanDisposition::Infected;
        $blocked = $this->upload($manager, $vehicle, 'list-blocked', 'invoice.pdf');
        $this->scanner->next = MalwareScanDisposition::Unavailable;
        $waiting = $this->upload($manager, $vehicle, 'list-waiting', 'warranty.pdf');
        $this->scanner->next = MalwareScanDisposition::Clean;
        $archived = $this->managedFile($vehicle, ['archived_at' => now(), 'archive_reason' => 'Superseded']);
        $legacy = $this->legacyVehicleFile($vehicle);
        $loose = $this->plainFile($vehicle);
        // Source-owned evidence: kept with its record in the vehicle profile.
        $this->uploadPhoto($manager, $vehicle);
        $this->managedFile($vehicle, ['source_type' => 'odometer_observation', 'source_id' => 7]);
        $this->managedFile($vehicle, ['source_type' => 'compliance_version', 'source_id' => 3]);
        $this->managedFile($vehicle, ['source_type' => 'finance_review_request', 'source_id' => 41]);

        $props = $this->registerProps($manager, $vehicle);
        $documents = collect($props['asset']['documents'])->keyBy('id');

        $this->assertEqualsCanonicalizing(
            [$clean->id, $blocked->id, $waiting->id, $archived->id, $legacy->id, $loose->id],
            $documents->keys()->all(),
        );
        foreach ([$clean, $legacy, $loose] as $file) {
            $this->assertSame($this->registerUrl($vehicle, $file), $documents[$file->id]['url']);
            $this->assertNull($documents[$file->id]['status']);
        }
        $this->assertNull($documents[$blocked->id]['url']);
        $this->assertSame(['label' => 'Blocked: failed virus check', 'tone' => 'critical'], $documents[$blocked->id]['status']);
        $this->assertNull($documents[$waiting->id]['url']);
        $this->assertSame(['label' => 'Waiting for virus check', 'tone' => 'warning'], $documents[$waiting->id]['status']);
        $this->assertSame($this->registerUrl($vehicle, $archived), $documents[$archived->id]['url']);
        $this->assertSame(['label' => 'Archived', 'tone' => 'neutral'], $documents[$archived->id]['status']);
        $this->assertSame(['url' => "/fleet-assets/vehicles/{$vehicle->id}?view=documents"], $props['asset']['vehicle_documents']);

        // Register-only viewers are told where vehicle documents live, without a link they can't open.
        $auditor = $this->siteUser([$this->site], ['assets.viewAny']);
        $this->assertSame(['url' => null], $this->registerProps($auditor, $vehicle)['asset']['vehicle_documents']);

        // Other assets keep the register's own upload.
        $this->assertNull($this->registerProps($manager, $this->equipment($this->site))['asset']['vehicle_documents']);
    }

    private function assertVehicleDownload(TestResponse $response): void
    {
        // Only VehicleDocumentService::download() sends these private-file headers.
        $response->assertOk();
        $this->assertSame('nosniff', $response->headers->get('X-Content-Type-Options'));
        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
        $this->assertStringContainsString('sandbox', (string) $response->headers->get('Content-Security-Policy'));
    }

    /** @return array<string,mixed> */
    private function registerProps(User $viewer, Asset $asset): array
    {
        $props = [];
        $this->actingAs($viewer)->get("/fleet-assets/assets/{$asset->id}")->assertOk()
            ->assertInertia(function (Assert $page) use (&$props): void {
                $props = $page->component('fleet-assets/assets/show')->toArray()['props'];
            });

        return $props;
    }

    private function registerUrl(Asset $asset, AssetDocument $file): string
    {
        return "/assets/{$asset->id}/documents/{$file->id}/download";
    }

    private function registerDeleteUrl(Asset $asset, AssetDocument $file): string
    {
        return "/assets/{$asset->id}/documents/{$file->id}";
    }

    private function upload(User $actor, Asset $vehicle, string $requestKey, string $name): AssetDocument
    {
        $id = $this->actingAs($actor)->post("/fleet-assets/vehicles/{$vehicle->id}/documents", [
            'category' => 'Insurance policy', 'document_date' => '2026-09-01', 'reason' => 'Policy on file',
            'request_key' => $requestKey, 'files' => [$this->pdf($name)],
        ], ['Accept' => 'application/json'])->assertOk()->json('files.0.id');

        return AssetDocument::query()->findOrFail($id);
    }

    private function uploadPhoto(User $actor, Asset $vehicle): AssetDocument
    {
        $this->actingAs($actor)->post("/fleet-assets/vehicles/{$vehicle->id}/photo", [
            'photo' => UploadedFile::fake()->image('van.jpg'), 'request_key' => 'vehicle-photo-'.$vehicle->id,
        ], ['Accept' => 'application/json'])->assertOk();

        return AssetDocument::query()->findOrFail($vehicle->fresh()->profile_photo_document_id);
    }

    /**
     * A private file in its own document set, as VehicleDocumentService records it.
     *
     * @param  array<string,mixed>  $overrides
     */
    private function managedFile(Asset $vehicle, array $overrides = []): AssetDocument
    {
        $set = AssetDocumentSet::query()->create([
            'asset_id' => $vehicle->id, 'category' => 'Insurance policy', 'document_date' => '2026-09-01',
            'source_type' => $overrides['source_type'] ?? null, 'source_id' => $overrides['source_id'] ?? null,
        ]);
        $path = "vehicle-documents/{$vehicle->id}/".Str::uuid()->toString().'.pdf';
        Storage::disk('private')->put($path, "%PDF-1.4\n% private vehicle file\n");

        return AssetDocument::query()->create(array_merge([
            'asset_id' => $vehicle->id, 'document_set_id' => $set->id, 'revision' => 1,
            'title' => 'Insurance policy', 'category' => 'Insurance policy',
            'storage_disk' => 'private', 'storage_path' => $path, 'original_name' => 'policy.pdf',
            'mime_type' => 'application/pdf', 'detected_mime' => 'application/pdf', 'size_bytes' => 32,
            'state' => AssetDocument::STATE_AVAILABLE,
        ], $overrides));
    }

    /** A register upload from before PKG-02B, wrapped in a document set by the backfill migration. */
    private function legacyVehicleFile(Asset $vehicle): AssetDocument
    {
        $set = AssetDocumentSet::query()->create([
            'asset_id' => $vehicle->id, 'category' => 'Registration', 'document_date' => '2025-07-01', 'legacy_backfill' => true,
        ]);
        $path = "assets/{$vehicle->id}/1751328000_registration.pdf";
        Storage::disk('local')->put($path, "%PDF-1.4\n% registration\n");

        return AssetDocument::query()->create([
            'asset_id' => $vehicle->id, 'document_set_id' => $set->id, 'revision' => 1,
            'title' => 'Registration', 'category' => 'Registration',
            'storage_disk' => 'local', 'storage_path' => $path, 'original_name' => 'registration.pdf',
            'mime_type' => 'application/pdf', 'size_bytes' => 24, 'state' => AssetDocument::STATE_LEGACY,
        ]);
    }

    /** A plain register upload: no document set, no scan state, on the local disk. */
    private function plainFile(Asset $asset): AssetDocument
    {
        $path = "assets/{$asset->id}/".Str::random(10).'_wof-sheet.pdf';
        Storage::disk('local')->put($path, "%PDF-1.4\n% register file\n");

        return AssetDocument::query()->create([
            'asset_id' => $asset->id, 'title' => 'WoF sheet', 'category' => 'certificate',
            'storage_disk' => 'local', 'storage_path' => $path, 'original_name' => 'wof-sheet.pdf',
            'mime_type' => 'application/pdf', 'size_bytes' => 25,
        ]);
    }

    private function holdAsComplianceEvidence(Asset $vehicle, AssetDocument $file): void
    {
        $record = FleetVehicleComplianceRecord::query()->firstOrCreate(['asset_id' => $vehicle->id, 'kind' => 'wof']);
        $version = FleetVehicleComplianceVersion::query()->create([
            'record_id' => $record->id, 'version' => 1, 'applicability' => 'applicable', 'outcome' => 'pass',
            'asset_document_id' => $file->id, 'document_trust' => AssetDocument::STATE_LEGACY,
            'request_key' => 'wof-evidence', 'request_fingerprint' => str_repeat('a', 64),
            'content_sha256' => str_repeat('b', 64), 'created_at' => now(),
        ]);
        $record->update(['current_version_id' => $version->id]);
    }

    private function pdf(string $name): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($name, "%PDF-1.4\n1 0 obj << /Type /Catalog >> endobj\ntrailer << /Root 1 0 R >>\n%%EOF\n");
    }

    private function manager(): User
    {
        return $this->siteUser([$this->site], ['fleet.viewAny', 'fleet.manage', 'assets.viewAny', 'assets.documents.manage']);
    }

    /**
     * @param  list<Site>  $sites
     * @param  list<string>  $permissions
     */
    private function siteUser(array $sites, array $permissions): User
    {
        $user = User::factory()->create(['approved_at' => now(), 'role' => 'manager']);
        HrEmployeeProfile::factory()->create([
            'user_id' => $user->id, 'primary_site_id' => $sites[0]->id,
            'secondary_site_ids' => collect($sites)->skip(1)->pluck('id')->values()->all(),
            'start_date' => today()->subYear(), 'end_date' => null, 'is_active' => true,
            'created_by' => $user->id, 'updated_by' => $user->id,
        ]);
        $user->permissionOverrides()->syncWithoutDetaching(collect($permissions)->mapWithKeys(fn (string $key): array => [
            Permission::query()->firstOrCreate(['key' => $key], [
                'description' => $key, 'group' => str($key)->before('.')->value(), 'module' => str($key)->before('.')->value(),
            ])->id => ['allowed' => true],
        ])->all());
        $user->unsetRelation('permissionOverrides');

        return $user;
    }

    private function vehicle(Site $site): Asset
    {
        return Asset::factory()->vehicle()->create([
            'site_id' => $site->id, 'home_site_id' => $site->id, 'name' => 'Kōwhai van', 'status' => 'active',
        ]);
    }

    private function equipment(Site $site): Asset
    {
        return Asset::factory()->create([
            'site_id' => $site->id, 'home_site_id' => $site->id, 'name' => 'Ceiling hoist',
            'category' => 'Medical Device', 'status' => 'active',
        ]);
    }
}
