<?php

namespace Tests\Feature\FleetAssets;

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Models\Asset;
use App\Models\AssetStocktake;
use App\Models\Client;
use App\Models\Permission;
use App\Models\Site;
use App\Models\SiteRoom;
use App\Models\User;
use App\Services\Assets\AssetStocktakeService;
use App\Services\Fleet\VehicleTripReportExporter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;
use ZipArchive;

class AssetRegisterWorkflowTest extends TestCase
{
    use RefreshDatabase;

    private User $actor;

    private Site $site;

    private SiteRoom $room;

    private Asset $asset;

    private const BASE = '/fleet-assets/asset-register';

    protected function setUp(): void
    {
        parent::setUp();
        $this->site = Site::factory()->create(['is_active' => true, 'archived' => false, 'archived_at' => null]);
        $this->room = SiteRoom::create(['site_id' => $this->site->id, 'name' => 'Equipment room', 'sort_order' => 1]);
        $this->actor = User::factory()->create(['approved_at' => now()]);
        HrEmployeeProfile::factory()->create(['user_id' => $this->actor->id, 'primary_site_id' => $this->site->id,
            'secondary_site_ids' => [], 'is_active' => true, 'start_date' => today()->subYear(), 'end_date' => null]);
        foreach (['assets.viewAny', 'assets.scan.record', 'assets.create'] as $key) {
            $permission = Permission::firstOrCreate(['key' => $key], ['description' => $key, 'group' => 'assets']);
            $this->actor->permissionOverrides()->syncWithoutDetaching([$permission->id => ['allowed' => true]]);
        }
        $this->asset = Asset::factory()->forSite($this->site)->create(['site_room_id' => $this->room->id, 'name' => 'Transfer hoist', 'status' => 'active', 'asset_tag' => 'AS-101']);
        $this->actingAs($this->actor);
    }

    private function start(array $extra = []): array
    {
        return $this->postJson(self::BASE.'/stocktakes', [...['request_id' => (string) Str::uuid(), 'site_id' => $this->site->id, 'site_room_id' => $this->room->id], ...$extra])
            ->assertCreated()->json();
    }

    private function command(array $count, string $action, array $extra = [])
    {
        return $this->patchJson(self::BASE.'/stocktakes/'.$count['id'], [...['version' => $count['version'], 'command_id' => (string) Str::uuid(),
            'action' => $action, 'key' => 'asset-'.$this->asset->id], ...$extra]);
    }

    public function test_location_directory_and_checklist_use_only_permitted_assets(): void
    {
        $otherSite = Site::factory()->create(['is_active' => true]);
        Asset::factory()->forSite($otherSite)->create(['name' => 'Hidden equipment']);
        $this->getJson(self::BASE.'/stocktake-checklist?site_id='.$this->site->id.'&site_room_id='.$this->room->id)
            ->assertOk()->assertJsonPath('total', 1)->assertJsonPath('assets.0.name', 'Transfer hoist');
        $this->getJson(self::BASE.'/stocktake-checklist?site_id='.$otherSite->id)->assertNotFound();
        $this->get('/fleet-assets/assets?site_id='.$this->site->id)
            ->assertOk()->assertInertia(fn ($page) => $page->component('fleet-assets/assets/index')
            ->has('sites', 1)->where('sites.0.asset_count', 1)
            ->has('rooms', 1)->where('rooms.0.asset_count', 1));
    }

    public function test_coverage_excludes_selected_item_counts_and_followups_recheck_access(): void
    {
        $count = $this->start(['asset_ids' => [$this->asset->id]]);
        $found = $this->command($count, 'found')->assertOk()->json();
        $this->command($found, 'finish')->assertOk();
        $this->getJson(self::BASE.'/stocktakes?workspace=coverage')->assertOk()
            ->assertJsonPath('data.0.counts', 1)->assertJsonPath('data.0.checked_rooms', 0);

        $count = $this->start();
        $missing = $this->command($count, 'missing')->assertOk()->json();
        $this->command($missing, 'finish', ['review_note' => 'Check the storage room.', 'follow_up_user_id' => $this->actor->id,
            'confirmed_room_ids' => [$this->room->id]])->assertOk();
        $this->getJson(self::BASE.'/stocktakes?workspace=coverage')->assertOk()->assertJsonPath('data.0.checked_rooms', 1);
        $this->getJson(self::BASE.'/stocktakes?workspace=followups')->assertOk()->assertJsonPath('total', 1)
            ->assertJsonPath('data.0.reason', 'Not found')->assertJsonPath('data.0.stocktake_id', $count['id']);
        $this->getJson(self::BASE.'/stocktakes?status=followups')->assertOk()->assertJsonPath('total', 1);

        $otherSite = Site::factory()->create(['is_active' => true]);
        $this->asset->update(['site_id' => $otherSite->id, 'site_room_id' => null]);
        $this->getJson(self::BASE.'/stocktakes?workspace=followups')->assertOk()->assertJsonPath('total', 0)->assertJsonPath('summary.followups', 0);
        $this->getJson(self::BASE.'/stocktakes?workspace=coverage')->assertOk()->assertJsonPath('data.0.counts', 0)->assertJsonPath('data.0.checked_rooms', 0);
    }

    public function test_count_is_durable_versioned_immutable_and_does_not_change_assignment(): void
    {
        $before = $this->asset->only(['site_id', 'site_room_id', 'status', 'qr_token']);
        $count = $this->start();
        $this->command($count, 'finish')->assertUnprocessable();
        $found = $this->command($count, 'found', ['source' => 'USB scanner'])->assertOk()->json();
        $this->command($count, 'missing')->assertConflict();
        $finished = $this->command($found, 'finish')->assertOk()->assertJsonPath('status', 'completed')->json();
        $this->command($finished, 'missing')->assertConflict();
        $this->getJson(self::BASE.'/stocktakes/'.$count['id'])->assertOk()->assertJsonPath('entries.0.result', 'found');
        $this->assertSame($before, $this->asset->fresh()->only(array_keys($before)));
    }

    public function test_scan_resolves_existing_identity_rejects_foreign_urls_and_requires_extra_confirmation(): void
    {
        $count = $this->start();
        $url = route('assets.qr.redirect', $this->asset->qr_token);
        $endpoint = self::BASE.'/stocktakes/'.$count['id'].'/resolve';
        $this->postJson($endpoint, ['payload' => $url])->assertOk()->assertJsonPath('state', 'expected');
        $this->postJson($endpoint, ['payload' => ' as-101 '])->assertOk()->assertJsonPath('state', 'expected');
        $this->postJson($endpoint, ['payload' => 'https://attacker.invalid/assets/qr/'.$this->asset->qr_token])->assertUnprocessable();
        $this->postJson($endpoint, ['payload' => str_repeat('x', 32)])->assertOk()->assertJsonPath('state', 'unknown');
        $other = Asset::factory()->forSite($this->site)->create(['status' => 'retired']);
        $this->postJson($endpoint, ['payload' => $other->qr_token])->assertOk()->assertJsonPath('state', 'extra');
        $this->command($count, 'found', ['key' => 'asset-'.$other->id, 'asset_id' => $other->id])->assertUnprocessable();
        $this->command($count, 'found', ['key' => 'asset-'.$other->id, 'asset_id' => $other->id, 'confirm_extra' => true])->assertOk();
        $other->update(['asset_tag' => 'AS-101']);
        $this->postJson($endpoint, ['payload' => 'AS-101'])->assertUnprocessable();
    }

    public function test_duplicate_command_is_idempotent_and_undo_keeps_evidence(): void
    {
        $requestId = (string) Str::uuid();
        $count = $this->start(['request_id' => $requestId]);
        $this->assertSame($count['id'], $this->start(['request_id' => $requestId])['id']);
        $commandId = (string) Str::uuid();
        $found = $this->command($count, 'found', ['command_id' => $commandId])->assertOk()->json();
        $this->command($count, 'found', ['command_id' => $commandId])->assertOk()->assertJsonPath('version', $found['version']);
        $duplicate = $this->command($found, 'duplicate')->assertOk()->json();
        $this->assertCount(1, $duplicate['entries']);
        $undone = $this->command($found, 'undo', ['version' => $duplicate['version']])->assertOk()->json();
        $this->assertCount(4, $undone['activity']);
        $this->assertSame('pending', $undone['entries'][0]['result']);
    }

    public function test_missing_and_changed_items_require_review_and_owner(): void
    {
        $count = $this->start();
        $count = $this->command($count, 'missing')->assertOk()->json();
        $this->command($count, 'finish', ['confirmed_room_ids' => [$this->room->id]])->assertUnprocessable();
        $this->command($count, 'finish', ['confirmed_room_ids' => [$this->room->id], 'review_note' => 'Check storage tomorrow', 'follow_up_user_id' => $this->actor->id])->assertOk();
        $count = $this->start();
        $count = $this->command($count, 'found')->assertOk()->json();
        $this->asset->update(['site_room_id' => null]);
        $this->getJson(self::BASE.'/stocktakes/'.$count['id'])->assertJsonPath('entries.0.changed', true);
        $review = ['review_note' => 'Room changed during count', 'follow_up_user_id' => $this->actor->id];
        $this->command($count, 'finish', $review)->assertUnprocessable();
        $this->command($count, 'finish', [...$review, 'acknowledge_changes' => true])->assertOk();
    }

    public function test_hidden_site_and_asset_records_are_denied_and_permission_revocation_blocks_writes(): void
    {
        $otherSite = Site::factory()->create(['is_active' => true, 'archived' => false]);
        $hidden = Asset::factory()->forSite($otherSite)->create();
        $count = $this->start();
        $this->postJson(self::BASE.'/stocktakes', ['request_id' => (string) Str::uuid(), 'site_id' => $otherSite->id])->assertNotFound();
        $this->postJson(self::BASE.'/stocktakes/'.$count['id'].'/resolve', ['payload' => $hidden->qr_token])->assertJsonPath('state', 'unknown');
        $this->postJson(self::BASE.'/labels', $this->labelPayload([$hidden->id]))->assertNotFound();
        $this->asset->update(['site_id' => $otherSite->id, 'site_room_id' => null]);
        $this->getJson(self::BASE.'/stocktakes/'.$count['id'])->assertNotFound();
        $this->getJson(self::BASE.'/stocktakes')->assertOk()->assertJsonPath('total', 0);
    }

    public function test_completed_report_is_real_pdf_and_typed_xlsx(): void
    {
        $count = $this->start();
        $count = $this->command($count, 'found')->assertOk()->json();
        $count = $this->command($count, 'finish')->assertOk()->json();
        $pdf = $this->get(self::BASE.'/stocktakes/'.$count['id'].'/export/pdf')->assertOk();
        $this->assertStringStartsWith('%PDF-', $pdf->getContent());
        $xlsx = $this->get(self::BASE.'/stocktakes/'.$count['id'].'/export/xlsx')->assertOk();
        $path = tempnam(sys_get_temp_dir(), 'test-stocktake-');
        try {
            file_put_contents($path, $xlsx->getContent());
            $zip = new ZipArchive;
            $this->assertTrue($zip->open($path));
            $this->assertStringContainsString('Follow-ups', $zip->getFromName('xl/workbook.xml'));
            $this->assertStringContainsString('Transfer hoist', $zip->getFromName('xl/worksheets/sheet2.xml'));
            $zip->close();
        } finally {
            unlink($path);
        }
    }

    public function test_csv_validation_partial_import_and_retry_do_not_duplicate_assets(): void
    {
        $csv = "name,asset_tag,site_id,site_room_id\nChair,AS-NEW,{$this->site->id},{$this->room->id}\nDuplicate,AS-101,{$this->site->id},{$this->room->id}\n";
        $batch = $this->postJson(self::BASE.'/imports', ['file' => UploadedFile::fake()->createWithContent('inventory.csv', $csv)])->assertCreated()->json();
        $endpoint = self::BASE.'/imports/'.$batch['id'];
        $batch = $this->patchJson($endpoint, ['version' => $batch['version'], 'action' => 'validate', 'mapping' => $batch['mapping']])->assertOk()->json();
        $this->assertSame('ready', $batch['rows'][0]['status']);
        $this->assertSame('invalid', $batch['rows'][1]['status']);
        $batch = $this->patchJson($endpoint, ['version' => $batch['version'], 'action' => 'import', 'row_numbers' => [2]])->assertOk()->json();
        $this->patchJson($endpoint, ['version' => $batch['version'], 'action' => 'import', 'row_numbers' => [2]])->assertOk();
        $this->assertSame(1, Asset::where('asset_tag', 'AS-NEW')->count());
        $this->assertSame($this->room->id, Asset::where('asset_tag', 'AS-NEW')->first()->site_room_id);
    }

    private function labelPayload(array $ids): array
    {
        return ['request_id' => (string) Str::uuid(), 'asset_ids' => $ids, 'layout' => ['width' => 60, 'height' => 45, 'margin' => 10, 'gap' => 3, 'copies' => 1, 'start' => 1]];
    }

    public function test_newly_conflicting_import_row_can_be_retried_without_repeating_successes(): void
    {
        $csv = "name,asset_tag,site_id\nChair,AS-FIRST,{$this->site->id}\nTable,AS-SECOND,{$this->site->id}\n";
        $batch = $this->postJson(self::BASE.'/imports', ['file' => UploadedFile::fake()->createWithContent('retry.csv', $csv)])->assertCreated()->json();
        $endpoint = self::BASE.'/imports/'.$batch['id'];
        $batch = $this->patchJson($endpoint, ['version' => $batch['version'], 'action' => 'validate', 'mapping' => $batch['mapping']])->assertOk()->json();
        $conflict = Asset::factory()->forSite($this->site)->create(['asset_tag' => 'AS-SECOND']);
        $batch = $this->patchJson($endpoint, ['version' => $batch['version'], 'action' => 'import', 'row_numbers' => [2, 3]])->assertOk()->json();
        $this->assertSame('imported', $batch['rows'][0]['status']);
        $this->assertSame('failed', $batch['rows'][1]['status']);
        $conflict->update(['asset_tag' => 'AS-CORRECTED']);
        $this->patchJson($endpoint, ['version' => $batch['version'], 'action' => 'import', 'row_numbers' => [2, 3]])->assertOk()->assertJsonPath('status', 'completed');
        $this->assertSame(1, Asset::where('asset_tag', 'AS-FIRST')->count());
        $this->assertSame(1, Asset::where('asset_tag', 'AS-SECOND')->count());
    }

    public function test_empty_count_requires_attestation_and_records_it(): void
    {
        $empty = SiteRoom::create(['site_id' => $this->site->id, 'name' => 'Empty room']);
        $count = $this->start(['site_room_id' => $empty->id]);
        $this->command($count, 'finish')->assertUnprocessable();
        $saved = $this->command($count, 'finish', ['confirmed_room_ids' => [$empty->id], 'confirm_empty' => true])->assertOk()->json();
        $this->assertTrue($saved['activity'][1]['confirmed_empty']);
        $this->assertSame(['Empty room'], $saved['activity'][1]['confirmed_rooms']);
    }

    public function test_read_access_does_not_grant_counting_or_import_permission(): void
    {
        $count = $this->start();
        $viewer = User::factory()->create(['approved_at' => now()]);
        HrEmployeeProfile::factory()->create(['user_id' => $viewer->id, 'primary_site_id' => $this->site->id,
            'secondary_site_ids' => [], 'is_active' => true, 'start_date' => today()->subYear(), 'end_date' => null]);
        $viewer->permissionOverrides()->attach(Permission::where('key', 'assets.viewAny')->firstOrFail(), ['allowed' => true]);
        $this->actingAs($viewer);
        $this->getJson(self::BASE.'/stocktakes/'.$count['id'])->assertOk();
        $this->command($count, 'found')->assertForbidden();
        $this->postJson(self::BASE.'/stocktakes', ['request_id' => (string) Str::uuid(), 'site_id' => $this->site->id])->assertForbidden();
        $this->getJson(self::BASE.'/imports')->assertForbidden();
    }

    public function test_canonical_room_filter_and_registration_cannot_cross_sites(): void
    {
        $otherSite = Site::factory()->create(['is_active' => true, 'archived' => false]);
        $hiddenRoom = SiteRoom::create(['site_id' => $otherSite->id, 'name' => 'Restricted room']);
        $this->getJson(self::BASE.'/rooms?site_id='.$otherSite->id)->assertNotFound();
        $this->getJson('/fleet-assets/assets?site_id='.$this->site->id.'&site_room_id='.$hiddenRoom->id)->assertNotFound();
        $this->post('/fleet-assets/assets', ['name' => 'Test asset', 'site_id' => $this->site->id, 'site_room_id' => $hiddenRoom->id, 'status' => 'active', 'risk_level' => 'medium'])->assertNotFound();
    }

    public function test_label_exports_preserve_tokens_and_revalidate_download_scope(): void
    {
        $token = $this->asset->qr_token;
        $batch = $this->postJson(self::BASE.'/labels', $this->labelPayload([$this->asset->id]))->assertCreated()->json();
        $this->assertSame($token, $this->asset->fresh()->qr_token);
        $this->get(self::BASE.'/labels/'.$batch['id'].'/pdf')->assertOk();
        $zip = $this->get(self::BASE.'/labels/'.$batch['id'].'/zip')->assertOk();
        $this->assertStringStartsWith('PK', $zip->getContent());
        $otherSite = Site::factory()->create(['is_active' => true, 'archived' => false]);
        $this->asset->update(['site_id' => $otherSite->id, 'site_room_id' => null]);
        $this->get(self::BASE.'/labels/'.$batch['id'].'/zip')->assertNotFound();
    }

    public function test_label_replay_requires_the_original_selection_and_layout(): void
    {
        $second = Asset::factory()->forSite($this->site)->create();
        $payload = $this->labelPayload([$second->id, $this->asset->id]);
        $batch = $this->postJson(self::BASE.'/labels', $payload)->assertCreated()->json();
        $payload['asset_ids'] = [(string) $this->asset->id, (string) $second->id];
        $payload['layout']['width'] = '60.0';
        $this->postJson(self::BASE.'/labels', $payload)->assertOk()->assertJsonPath('id', $batch['id']);
        $changed = $payload;
        $changed['layout']['copies'] = 2;
        $this->postJson(self::BASE.'/labels', $changed)->assertConflict();
        $changed = $payload;
        $changed['asset_ids'] = [$this->asset->id];
        $this->postJson(self::BASE.'/labels', $changed)->assertConflict();
        $this->assertDatabaseCount('asset_label_batches', 1);
    }

    public function test_print_permission_does_not_grant_permission_to_create_a_missing_qr_identity(): void
    {
        $this->asset->update(['qr_token' => null]);
        $this->postJson(self::BASE.'/labels', $this->labelPayload([$this->asset->id]))->assertForbidden();
        $this->assertNull($this->asset->fresh()->qr_token);
        $this->assertDatabaseCount('asset_label_batches', 0);

        $permission = Permission::firstOrCreate(['key' => 'assets.update'], ['description' => 'Update assets', 'group' => 'assets']);
        $this->actor->permissionOverrides()->syncWithoutDetaching([$permission->id => ['allowed' => true]]);
        $this->actingAs($this->actor->fresh());
        $this->postJson(self::BASE.'/labels', $this->labelPayload([$this->asset->id]))->assertCreated();
        $this->assertNotEmpty($this->asset->fresh()->qr_token);
    }

    private function countWithUndoneExtra(Asset $extra, bool $finish): array
    {
        $count = $this->start(['asset_ids' => [$this->asset->id]]);
        $count = $this->command($count, 'found', ['key' => 'asset-'.$extra->id, 'asset_id' => $extra->id, 'confirm_extra' => true])->assertOk()->json();
        $count = $this->command($count, 'undo', ['key' => 'asset-'.$extra->id])->assertOk()->json();
        $this->assertCount(1, $count['entries']);
        if ($finish) {
            $count = $this->command($count, 'found')->assertOk()->json();
            $count = $this->command($count, 'finish')->assertOk()->json();
        }

        return $count;
    }

    public function test_undone_extra_history_remains_access_scoped_in_lists_resume_and_exports(): void
    {
        $extra = Asset::factory()->forSite($this->site)->create(['name' => 'Historical extra', 'status' => 'active']);
        $counts = [$this->countWithUndoneExtra($extra, false), $this->countWithUndoneExtra($extra, true)];
        $evidence = [];
        foreach ($counts as $count) {
            $this->assertDatabaseHas('asset_stocktake_asset_refs', ['asset_stocktake_id' => $count['id'], 'asset_id' => $extra->id]);
            $this->getJson(self::BASE.'/stocktakes/'.$count['id'])->assertOk()->assertSee('Historical extra');
            $evidence[$count['id']] = AssetStocktake::findOrFail($count['id'])->only(['entries', 'activity', 'version', 'status']);
        }
        $hidden = Site::factory()->create(['is_active' => true]);
        $extra->update(['site_id' => $hidden->id, 'site_room_id' => null]);
        $this->get('/fleet-assets/assets/'.$extra->id)->assertNotFound();
        foreach ($counts as $count) {
            $this->getJson(self::BASE.'/stocktakes/'.$count['id'])->assertNotFound();
            foreach (['pdf', 'xlsx'] as $format) {
                $this->get(self::BASE.'/stocktakes/'.$count['id'].'/export/'.$format)->assertNotFound();
            }
            $this->command($count, 'found')->assertNotFound();
            $this->assertSame($evidence[$count['id']], AssetStocktake::findOrFail($count['id'])->only(['entries', 'activity', 'version', 'status']));
        }
        $this->getJson(self::BASE.'/stocktakes')->assertOk()->assertJsonPath('total', 0)->assertJsonPath('resume', null)->assertJsonPath('summary.counts', 0);
        $this->getJson(self::BASE.'/stocktakes?workspace=followups')->assertOk()->assertJsonPath('total', 0);
        $this->getJson(self::BASE.'/stocktakes?workspace=coverage')->assertOk()->assertJsonPath('data.0.counts', 0);
    }

    public function test_legacy_history_references_are_backfilled_without_rewriting_evidence(): void
    {
        $extra = Asset::factory()->forSite($this->site)->create(['status' => 'active']);
        $count = $this->countWithUndoneExtra($extra, true);
        $saved = AssetStocktake::findOrFail($count['id']);
        // Reproduce the earlier event shape and missing reference after Undo.
        $saved->activity = array_map(fn ($event) => array_diff_key($event, ['asset_id' => true]), $saved->activity);
        $saved->save();
        DB::table('asset_stocktake_asset_refs')->where('asset_stocktake_id', $saved->id)->where('asset_id', $extra->id)->delete();
        $before = $saved->fresh()->getRawOriginal();
        $extra->update(['site_id' => Site::factory()->create(['is_active' => true])->id, 'site_room_id' => null]);
        $this->getJson(self::BASE.'/stocktakes/'.$saved->id)->assertNotFound();

        $migration = require database_path('migrations/2026_09_27_140000_retain_stocktake_history_asset_references.php');
        $migration->up();
        $migration->up();
        $this->assertDatabaseHas('asset_stocktake_asset_refs', ['asset_stocktake_id' => $saved->id, 'asset_id' => $extra->id]);
        $this->assertSame($before, $saved->fresh()->getRawOriginal());
        $this->getJson(self::BASE.'/stocktakes')->assertOk()->assertJsonPath('total', 0);
        foreach (['pdf', 'xlsx'] as $format) {
            $this->get(self::BASE.'/stocktakes/'.$saved->id.'/export/'.$format)->assertNotFound();
        }
    }

    private function allowPlacementEdits(Site $destination): void
    {
        HrEmployeeProfile::where('user_id', $this->actor->id)->firstOrFail()->update(['secondary_site_ids' => [$destination->id]]);
        foreach (['assets.update', 'clients.viewAny'] as $key) {
            $permission = Permission::firstOrCreate(['key' => $key], ['description' => $key, 'group' => 'assets']);
            $this->actor->permissionOverrides()->syncWithoutDetaching([$permission->id => ['allowed' => true]]);
        }
        $this->actingAs($this->actor->fresh());
    }

    public function test_revoked_extra_site_access_also_hides_retained_count_history(): void
    {
        $extraSite = Site::factory()->create(['is_active' => true, 'archived' => false]);
        $profile = HrEmployeeProfile::where('user_id', $this->actor->id)->firstOrFail();
        $profile->update(['secondary_site_ids' => [$extraSite->id]]);
        $this->actingAs($this->actor->fresh());
        $extra = Asset::factory()->forSite($extraSite)->create(['status' => 'active']);
        $count = $this->countWithUndoneExtra($extra, true);
        $profile->update(['secondary_site_ids' => []]);
        $this->actingAs($this->actor->fresh());
        $this->getJson(self::BASE.'/stocktakes/'.$count['id'])->assertNotFound();
        $this->getJson(self::BASE.'/stocktakes')->assertOk()->assertJsonPath('total', 0);
        foreach (['pdf', 'xlsx'] as $format) {
            $this->get(self::BASE.'/stocktakes/'.$count['id'].'/export/'.$format)->assertNotFound();
        }
    }

    public function test_report_time_matches_the_worker_zone_regression_in_the_register(): void
    {
        $this->travelTo(Carbon::parse('2026-09-27T01:00:00Z'));
        try {
            $count = $this->start();
            $count = $this->command($count, 'found')->assertOk()->json();
            $count = $this->command($count, 'finish')->assertOk()->json();
            $report = app(AssetStocktakeService::class)->present(AssetStocktake::findOrFail($count['id']));
            $html = view('pdf.asset-stocktake', ['report' => $report, 'brand' => app(VehicleTripReportExporter::class)->branding()])->render();
            $this->assertStringContainsString('27 Sep 2026, 2:00 pm NZDT', $html);
        } finally {
            $this->travelBack();
        }
    }

    public function test_ordinary_edit_preserves_same_site_room_and_reconciles_destination_room(): void
    {
        $destination = Site::factory()->create(['is_active' => true, 'archived' => false]);
        $this->allowPlacementEdits($destination);
        $destinationRoom = SiteRoom::create(['site_id' => $destination->id, 'name' => 'Destination room']);
        $payload = ['name' => 'Room assigned asset', 'site_id' => $this->site->id, 'status' => 'active', 'risk_level' => 'medium'];
        $this->post('/fleet-assets/assets', [...$payload, 'site_room_id' => $this->room->id])->assertRedirect();
        $asset = Asset::where('name', $payload['name'])->firstOrFail();
        $this->put('/fleet-assets/assets/'.$asset->id, $payload)->assertRedirect();
        $this->assertSame($this->room->id, $asset->fresh()->site_room_id);
        $payload['site_id'] = $destination->id;
        $this->put('/fleet-assets/assets/'.$asset->id, $payload)->assertRedirect();
        $this->assertSame($destination->id, $asset->fresh()->site_id);
        $this->assertNull($asset->fresh()->site_room_id);
        $this->put('/fleet-assets/assets/'.$asset->id, [...$payload, 'site_room_id' => $destinationRoom->id])->assertRedirect();
        $this->assertSame($destinationRoom->id, $asset->fresh()->site_room_id);
        $this->put('/fleet-assets/assets/'.$asset->id, [...$payload, 'site_room_id' => $this->room->id])->assertNotFound();
        $this->assertSame($destinationRoom->id, $asset->fresh()->site_room_id);
    }

    public function test_imported_room_is_cleared_on_a_client_derived_site_move(): void
    {
        $destination = Site::factory()->create(['is_active' => true, 'archived' => false]);
        $this->allowPlacementEdits($destination);
        $client = Client::factory()->create(['site_id' => $destination->id]);
        $csv = "name,asset_tag,site_id,site_room_id\nImported chair,IMPORTED-ROOM,{$this->site->id},{$this->room->id}\n";
        $batch = $this->postJson(self::BASE.'/imports', ['file' => UploadedFile::fake()->createWithContent('rooms.csv', $csv)])->assertCreated()->json();
        $endpoint = self::BASE.'/imports/'.$batch['id'];
        $batch = $this->patchJson($endpoint, ['version' => $batch['version'], 'action' => 'validate', 'mapping' => $batch['mapping']])->assertOk()->json();
        $this->patchJson($endpoint, ['version' => $batch['version'], 'action' => 'import', 'row_numbers' => [2]])->assertOk();
        $asset = Asset::where('asset_tag', 'IMPORTED-ROOM')->firstOrFail();
        $this->assertSame($this->room->id, $asset->site_room_id);
        $this->put('/fleet-assets/assets/'.$asset->id, ['name' => $asset->name, 'site_id' => $this->site->id, 'client_id' => $client->id,
            'status' => 'active', 'risk_level' => 'medium'])->assertRedirect();
        $this->assertSame($destination->id, $asset->fresh()->site_id);
        $this->assertSame($client->id, $asset->fresh()->client_id);
        $this->assertNull($asset->fresh()->site_room_id);
    }
}
