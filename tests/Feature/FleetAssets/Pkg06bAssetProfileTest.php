<?php

namespace Tests\Feature\FleetAssets;

use App\Domain\Finance\Models\FinAccount;
use App\Domain\Finance\Models\FinCostAllocation;
use App\Domain\Finance\Models\FinJournal;
use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Models\Asset;
use App\Models\AssetCustodyMovement;
use App\Models\AssetDocument;
use App\Models\AssetKitItem;
use App\Models\AssetLabelBatch;
use App\Models\AssetProfileEvent;
use App\Models\AssetScanEvent;
use App\Models\Client;
use App\Models\FleetChecklistRun;
use App\Models\FleetChecklistTemplate;
use App\Models\FleetFinanceReviewRequest;
use App\Models\FleetWorkOrder;
use App\Models\Permission;
use App\Models\Site;
use App\Models\SiteRoom;
use App\Models\User;
use App\Services\Assets\AssetProfileSources;
use App\Services\Files\MalwareScanDisposition;
use App\Services\Files\MalwareScanner;
use App\Services\Files\MalwareScanResult;
use App\Services\Fleet\VehicleFinanceReviewQueue;
use App\Services\Fleet\VehicleFinanceService;
use App\Support\SchemaCache;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

class Pkg06bAssetProfileTest extends TestCase
{
    use RefreshDatabase;

    private Site $origin;

    private Site $destination;

    private User $manager;

    private Asset $asset;

    private object $scanner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RbacSeeder::class);
        Storage::fake('private');
        $this->origin = Site::factory()->create(['is_active' => true, 'archived' => false, 'archived_at' => null]);
        $this->destination = Site::factory()->create(['is_active' => true, 'archived' => false, 'archived_at' => null]);
        $this->manager = $this->user([$this->origin, $this->destination], ['assets.viewAny', 'assets.update', 'assets.assignments.manage', 'assets.documents.manage', 'assets.inspections.record', 'assets.scan.record', 'assets.delete', 'staff.viewAny', 'hazards.view']);
        $this->asset = Asset::factory()->forSite($this->origin)->create(['category' => 'equipment', 'status' => 'active', 'home_site_id' => $this->origin->id]);
        $this->scanner = new class extends MalwareScanner
        {
            public MalwareScanDisposition $next = MalwareScanDisposition::Clean;

            public function scanPath(string $path, array $settings): MalwareScanResult
            {
                return new MalwareScanResult($this->next, 'fixture-scanner', $this->next === MalwareScanDisposition::Unavailable ? 'unavailable' : null);
            }
        };
        $this->app->instance(MalwareScanner::class, $this->scanner);
    }

    public function test_dispatch_does_not_move_asset_and_receipt_requires_every_kit_item(): void
    {
        $recipient = $this->user([$this->destination]);
        $item = AssetKitItem::create(['asset_id' => $this->asset->id, 'name' => 'Sling', 'added_by_user_id' => $this->manager->id]);
        $dispatch = $this->command('dispatch', ['kind' => 'loan', 'destination_site_id' => $this->destination->id, 'recipient_user_id' => $recipient->id, 'return_due_on' => today()->addWeek()->toDateString()]);
        $dispatch->assertOk();
        $this->assertSame($this->origin->id, $this->asset->fresh()->site_id);
        $move = $dispatch->json('movement_id');
        $this->command('receive', ['movement_id' => $move, 'outcome' => 'acknowledged', 'received_kit' => []])->assertUnprocessable();
        $this->command('receive', ['movement_id' => $move, 'outcome' => 'incomplete', 'received_kit' => []])->assertOk();
        $this->assertSame($this->origin->id, $this->asset->fresh()->site_id);
        $this->command('receive', ['movement_id' => $move, 'outcome' => 'acknowledged', 'received_kit' => [$item->id]])->assertOk();
        $this->assertSame($this->destination->id, $this->asset->fresh()->site_id);
        $this->assertNull(AssetCustodyMovement::findOrFail($move)->returned_at);
        $return = $this->command('return', ['movement_id' => $move, 'recipient_user_id' => $this->manager->id])->assertOk();
        $this->command('receive', ['movement_id' => $return->json('movement_id'), 'received_kit' => [$item->id], 'outcome' => 'acknowledged'])->assertOk();
        $this->assertSame($this->origin->id, $this->asset->fresh()->site_id);
        $this->assertNotNull(AssetCustodyMovement::findOrFail($move)->returned_at);
    }

    public function test_same_command_replays_once_but_changed_and_stale_submissions_are_rejected(): void
    {
        $data = ['action' => 'kit_add', 'name' => 'Charging cable', 'reason' => 'Received with asset', 'request_key' => (string) Str::uuid(), 'expected_version' => 1];
        $url = '/assets/'.$this->asset->id.'/profile-actions';
        $first = $this->actingAs($this->manager)->postJson($url, $data)->assertOk();
        $this->postJson($url, $data)->assertOk()->assertExactJson($first->json());
        $this->postJson($url, array_replace($data, ['name' => 'Different cable']))->assertConflict();
        $this->postJson($url, array_replace($data, ['request_key' => (string) Str::uuid()]))->assertConflict();
        $this->assertSame(1, AssetKitItem::where('asset_id', $this->asset->id)->count());
    }

    public function test_profile_uses_canonical_assignment_receipts_and_requires_explicit_attestation(): void
    {
        $recipient = $this->user([$this->origin]);
        $assignment = $this->command('assign', ['assignee_type' => 'staff', 'assignee_id' => $recipient->id])->assertOk()->json('assignment_id');
        $options = $this->actingAs($this->manager)->getJson('/assets/'.$this->asset->id.'/profile-options?kind=assignees')->assertOk()->json('options');
        $this->assertContains('staff:'.$recipient->id, array_column($options, 'id'));
        $this->command('confirm_assignment_receipt', ['assignment_id' => $assignment])->assertUnprocessable();
        $this->command('confirm_assignment_receipt', ['assignment_id' => $assignment, 'verified_received' => false])->assertUnprocessable();
        $version = $this->asset->fresh()->asset_profile_version;
        $payload = ['action' => 'confirm_assignment_receipt', 'assignment_id' => $assignment, 'verified_received' => true, 'reason' => 'Checked actual handover', 'request_key' => (string) Str::uuid(), 'expected_version' => $version];
        $url = '/assets/'.$this->asset->id.'/profile-actions';
        $first = $this->postJson($url, $payload)->assertOk();
        $this->postJson($url, $payload)->assertOk()->assertExactJson($first->json());
        $record = $this->asset->assignments()->findOrFail($assignment);
        $this->assertNotNull($record->receipt_confirmed_at);
        $this->assertSame($this->manager->id, $record->receipt_confirmed_by_user_id);
        $this->assertSame('Checked actual handover', $record->receipt_note);
        $this->assertGreaterThan($version, $this->asset->fresh()->asset_profile_version);
        $this->assertSame($this->origin->id, $this->asset->fresh()->site_id);
        $this->assertSame(0, AssetCustodyMovement::where('asset_id', $this->asset->id)->count());
        $this->assertSame(1, AssetProfileEvent::where('asset_id', $this->asset->id)->where('action', 'confirm_assignment_receipt')->count());
        $this->postJson($url, [...$payload, 'reason' => 'Changed retry'])->assertConflict();
    }

    public function test_linked_components_move_only_with_an_acknowledged_kit_receipt(): void
    {
        $component = Asset::factory()->forSite($this->origin)->create(['category' => 'equipment', 'status' => 'active', 'home_site_id' => $this->origin->id, 'client_id' => null]);
        $recipient = $this->user([$this->destination]);
        $item = $this->command('kit_add', ['name' => 'Linked sling', 'component_asset_id' => $component->id])->assertOk()->json('kit_item_id');
        $move = $this->command('dispatch', ['destination_site_id' => $this->destination->id, 'recipient_user_id' => $recipient->id])->assertOk()->json('movement_id');
        $this->assertSame($this->origin->id, $component->fresh()->site_id);
        $this->actingAs($this->manager)->postJson('/assets/'.$component->id.'/profile-actions', ['action' => 'dispatch', 'destination_site_id' => $this->destination->id, 'recipient_user_id' => $recipient->id, 'reason' => 'Independent movement', 'request_key' => (string) Str::uuid(), 'expected_version' => $component->fresh()->asset_profile_version])->assertConflict();
        $this->command('receive', ['movement_id' => $move, 'outcome' => 'acknowledged', 'received_kit' => [$item]])->assertOk();
        $this->assertSame($this->destination->id, $component->fresh()->site_id);
        $this->assertSame(1, AssetProfileEvent::where('asset_id', $component->id)->where('action', 'kit_receipt')->count());
        $this->command('retire')->assertUnprocessable();
        $this->assertSame('active', $this->asset->fresh()->status);
    }

    public function test_permissions_and_foreign_asset_file_ids_are_enforced(): void
    {
        $viewer = $this->user([$this->origin], ['assets.viewAny']);
        $this->actingAs($viewer)->postJson('/assets/'.$this->asset->id.'/profile-actions', ['action' => 'kit_add', 'name' => 'Denied', 'reason' => 'Not allowed', 'request_key' => (string) Str::uuid(), 'expected_version' => 1])->assertForbidden();
        $file = $this->upload()->assertCreated();
        $other = Asset::factory()->forSite($this->origin)->create(['category' => 'equipment']);
        $this->actingAs($this->manager)->get('/assets/'.$other->id.'/documents/'.$file->json('document.id').'/download')->assertNotFound();
        $foreign = $this->user([$this->destination], ['assets.viewAny']);
        $this->actingAs($foreign)->get('/assets/'.$this->asset->id.'/documents/'.$file->json('document.id').'/download')->assertNotFound();
    }

    public function test_files_are_scanned_versioned_and_archived_without_losing_bytes(): void
    {
        $initial = $this->upload()->assertCreated();
        $document = AssetDocument::findOrFail($initial->json('document.id'));
        $url = '/assets/'.$this->asset->id.'/documents/'.$document->id;
        $this->get($url.'/download?inline=1')->assertOk()->assertHeader('X-Content-Type-Options', 'nosniff')->assertHeader('Content-Type', 'application/pdf');
        $this->scanner->next = MalwareScanDisposition::Unavailable;
        $replace = $this->postJson($url.'/replace', ['file' => UploadedFile::fake()->createWithContent('replacement.pdf', "%PDF-1.4\nnew fixture"), 'title' => 'Replacement', 'reason' => 'New edition', 'request_key' => (string) Str::uuid(), 'expected_version' => $document->documentSet->lock_version])->assertCreated();
        $new = AssetDocument::findOrFail($replace->json('document.id'));
        $this->assertSame('scan_unavailable', $new->state);
        $this->assertSame(1, $document->documentSet->fresh()->current_revision);
        $this->get('/assets/'.$this->asset->id.'/documents/'.$new->id.'/download')->assertConflict();
        $this->scanner->next = MalwareScanDisposition::Clean;
        $this->postJson('/assets/'.$this->asset->id.'/documents/'.$new->id.'/retry')->assertOk();
        $this->assertSame(2, $document->documentSet->fresh()->current_revision);
        $this->postJson($url.'/archive', ['reason' => 'Superseded'])->assertOk();
        $this->get($url.'/download')->assertOk();
        Storage::disk('private')->assertExists($document->storage_path);
    }

    public function test_profile_and_printable_qr_return_real_authorised_content(): void
    {
        $this->actingAs($this->manager)->get('/fleet-assets/assets/'.$this->asset->id)->assertOk()->assertInertia(fn ($page) => $page->where('workspace.ready', true)->where('workspace.permissions.update', true));
        $response = $this->get('/assets/'.$this->asset->id.'/qr/labels?format=custom&copies=1&width=60&height=50&logo=1')->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $this->assertStringStartsWith('%PDF-', $response->getContent());
        $this->assertGreaterThan(1000, strlen($response->getContent()));
        $this->get('/assets/'.$this->asset->id.'/qr/labels?format=custom&copies=1&width=10&height=10')->assertSessionHasErrors(['width', 'height']);
    }

    public function test_asset_finance_requests_reuse_the_finance_queue_and_duplicate_boundary(): void
    {
        $permission = Permission::firstOrCreate(['key' => 'finance.assets.view'], ['description' => 'Finance assets', 'group' => 'finance']);
        $this->manager->permissionOverrides()->syncWithoutDetaching([$permission->id => ['allowed' => true]]);
        $data = ['request_type' => 'fixed_asset_update', 'source' => 'vehicle', 'note' => 'Correct the equipment ownership reference', 'amount' => '125.50', 'request_key' => (string) Str::uuid()];
        $url = '/assets/'.$this->asset->id.'/finance-review';
        $first = $this->actingAs($this->manager->fresh())->postJson($url, $data)->assertOk();
        $this->postJson($url, $data)->assertOk()->assertJsonPath('id', $first->json('id'));
        $this->postJson($url, array_replace($data, ['request_key' => (string) Str::uuid()]))->assertUnprocessable();
        $queue = app(VehicleFinanceReviewQueue::class)->present($this->manager->fresh(), []);
        $this->assertSame($first->json('id'), $queue['requests'][0]['id']);
        $this->assertSame('/fleet-assets/assets/'.$this->asset->id.'#view=overview&section=finance', $queue['requests'][0]['vehicle']['url']);
        $this->assertSame(1, FleetFinanceReviewRequest::where('asset_id', $this->asset->id)->count());
        $this->assertSame('active', $this->asset->fresh()->status);
    }

    public function test_schema_probe_stays_in_the_configured_application_schema(): void
    {
        SchemaCache::flush();
        $queries = [];
        DB::listen(function ($query) use (&$queries) {
            if (str_contains($query->sql, 'information_schema.tables')) {
                $queries[] = $query->sql;
            }
        });
        $this->assertTrue(SchemaCache::hasTable('assets'));
        $this->assertNotEmpty($queries);
        $this->assertStringContainsString(DB::connection()->getDatabaseName(), $queries[0]);
        $this->assertStringNotContainsString('table_schema not in', $queries[0]);
    }

    private function upload()
    {
        return $this->actingAs($this->manager)->postJson('/assets/'.$this->asset->id.'/documents', ['file' => UploadedFile::fake()->createWithContent('manual.pdf', "%PDF-1.4\nfixture"), 'title' => 'Manual', 'request_key' => (string) Str::uuid()]);
    }

    public function test_source_costs_exclude_estimates_drafts_reversals_old_and_foreign_allocations(): void
    {
        $actor = $this->user([$this->origin], ['assets.viewAny', 'finance.assets.view', 'finance.ledger.view', 'finance.ap.view']);
        $account = FinAccount::factory()->create(['type' => 'expense']);
        foreach ([['posted', $this->origin->id, today(), '125.40'], ['draft', $this->origin->id, today(), '999.00'], ['reversed', $this->origin->id, today(), '800.00'], ['posted', $this->destination->id, today(), '777.00'], ['posted', $this->origin->id, today()->subYears(2), '600.00']] as [$status, $site, $date, $amount]) {
            $journal = FinJournal::factory()->create(['status' => $status]);
            $line = $journal->lines()->create(['account_id' => $account->id, 'debit' => $amount, 'credit' => 0]);
            FinCostAllocation::create(['journal_id' => $journal->id, 'journal_line_id' => $line->id, 'asset_id' => $this->asset->id, 'site_id' => $site, 'event_type' => 'asset_maintenance_expense', 'event_date' => $date, 'amount' => $amount]);
        }
        FleetWorkOrder::factory()->create(['asset_id' => $this->asset->id, 'estimated_cost' => '9999.00', 'actual_cost' => '9999.00']);
        $sources = app(AssetProfileSources::class)->present($actor, $this->asset);
        $this->assertSame('125.40', $sources['costs']['totals'][0]['amount']);
        $this->assertCount(1, $sources['costs']['entries']);
        $restricted = app(AssetProfileSources::class)->present($this->manager, $this->asset);
        $this->assertFalse($restricted['costs']['allowed']);
        $this->assertCount(0, $restricted['costs']['entries']);
        $this->assertCount(0, $restricted['costs']['totals']);
    }

    public function test_original_check_evidence_uses_source_permissions_and_submitted_template(): void
    {
        $template = FleetChecklistTemplate::create(['name' => 'Current edited template', 'type' => 'inspection', 'items' => [], 'is_active' => true]);
        Storage::disk('private')->put('checks/original.pdf', '%PDF-1.4 original source');
        $run = FleetChecklistRun::create(['template_id' => $template->id, 'asset_id' => $this->asset->id, 'user_id' => $this->manager->id, 'completed_at' => now(), 'passed' => false, 'outcome' => 'needs_assessment', 'presented_template_json' => ['name' => 'Template as submitted'], 'responses' => ['brake' => ['evidence_file' => ['path' => 'checks/original.pdf', 'original_name' => 'original.pdf', 'mime_type' => 'application/pdf']]]]);
        $sources = app(AssetProfileSources::class)->present($this->manager, $this->asset);
        $this->assertSame('Template as submitted', $sources['original_checks'][0]['name']);
        $this->assertCount(0, $sources['source_files']);
        $url = '/fleet-assets/maintenance/checklists/runs/'.$run->id.'/evidence/brake';
        $this->actingAs($this->manager)->get($url)->assertForbidden();
        $reviewer = $this->user([$this->origin], ['assets.viewAny', 'fleet.maintenance.manage']);
        $sources = app(AssetProfileSources::class)->present($reviewer, $this->asset);
        $this->assertSame($url, $sources['source_files'][0]['downloadUrl']);
        $this->assertTrue($sources['source_files'][0]['sourceOwned']);
        $this->actingAs($reviewer)->get($url)->assertOk();
        Storage::disk('private')->delete('checks/original.pdf');
        $sources = app(AssetProfileSources::class)->present($reviewer, $this->asset);
        $this->assertNull($sources['source_files'][0]['downloadUrl']);
        $this->assertSame('unavailable', $sources['source_files'][0]['state']);
        $foreign = $this->user([$this->destination], ['assets.viewAny', 'fleet.maintenance.manage']);
        $this->actingAs($foreign)->get($url)->assertNotFound();
    }

    public function test_scan_identity_is_server_owned_and_foreign_observations_are_withheld(): void
    {
        $url = '/assets/'.$this->asset->id.'/scan-events';
        $data = ['qr_token' => $this->asset->qr_token, 'site_id' => $this->origin->id, 'scanned_by_type' => 'user', 'scanned_by_id' => 999999];
        $id = $this->actingAs($this->manager)->postJson($url, $data)->assertOk()->json('id');
        $this->assertSame($this->manager->id, AssetScanEvent::findOrFail($id)->scanned_by_id);
        $this->postJson($url, [...$data, 'qr_token' => 'another-assets-code'])->assertUnprocessable();
        $this->postJson($url, [...$data, 'scanned_at' => now()->addDay()->toISOString()])->assertUnprocessable();
        $viewer = $this->user([$this->origin], ['assets.viewAny', 'assets.scan.record']);
        $this->actingAs($viewer)->postJson($url, [...$data, 'site_id' => $this->destination->id])->assertNotFound();
        AssetScanEvent::create([...$data, 'asset_id' => $this->asset->id, 'site_id' => $this->destination->id, 'scanned_at' => now()]);
        $sources = app(AssetProfileSources::class)->present($viewer, $this->asset);
        $this->assertCount(1, $sources['observations']);
        $this->assertSame($id, $sources['observations'][0]['id']);
        $this->assertArrayNotHasKey('qr_token', $sources['observations'][0]);
    }

    public function test_bulk_labels_support_both_media_replay_protection_and_access_revocation(): void
    {
        $second = Asset::factory()->forSite($this->origin)->create(['category' => 'equipment']);
        $token = $this->asset->qr_token;
        $url = '/fleet-assets/asset-register/labels';
        $payload = ['request_id' => (string) Str::uuid(), 'asset_ids' => [$this->asset->id, $second->id], 'layout' => ['paper' => 'label', 'width' => 60, 'height' => 50, 'margin' => 0, 'gap' => 0, 'copies' => 1, 'start' => 1, 'logo' => true]];
        $id = $this->actingAs($this->manager)->postJson($url, $payload)->assertCreated()->json('id');
        $this->postJson($url, $payload)->assertOk()->assertJsonPath('id', $id);
        $this->postJson($url, [...$payload, 'asset_ids' => [$second->id]])->assertConflict();
        $pdf = $this->get($url.'/'.$id.'/pdf')->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $this->assertStringStartsWith('%PDF-', $pdf->getContent());
        $zip = $this->get($url.'/'.$id.'/zip')->assertOk()->assertHeader('Content-Type', 'application/zip');
        $this->assertStringStartsWith('PK', $zip->getContent());
        $this->assertSame($token, $this->asset->fresh()->qr_token);
        $payload['request_id'] = (string) Str::uuid();
        $payload['layout'] = ['paper' => 'a4', 'width' => 63.5, 'height' => 46.6, 'margin' => 8, 'gap' => 0, 'copies' => 1, 'start' => 18, 'logo' => false];
        $a4 = $this->postJson($url, $payload)->assertCreated()->json('id');
        $this->get($url.'/'.$a4.'/pdf')->assertOk();
        $other = $this->user([$this->origin], ['assets.viewAny']);
        $this->actingAs($other)->get($url.'/'.$id.'/pdf')->assertNotFound();
        $foreign = Site::factory()->create(['is_active' => true, 'archived' => false, 'archived_at' => null]);
        $second->forceFill(['site_id' => $foreign->id])->save();
        $this->actingAs($this->manager)->get($url.'/'.$id.'/pdf')->assertNotFound();
        $this->postJson($url, $payload)->assertNotFound();
    }

    public function test_viewers_cannot_create_missing_qr_identity_and_expired_batches_cannot_download(): void
    {
        $this->asset->forceFill(['qr_token' => null])->save();
        $viewer = $this->user([$this->origin], ['assets.viewAny']);
        $payload = ['request_id' => (string) Str::uuid(), 'asset_ids' => [$this->asset->id], 'layout' => ['paper' => 'a4', 'width' => 63.5, 'height' => 46.6, 'margin' => 8, 'gap' => 0, 'copies' => 1, 'start' => 1]];
        $url = '/fleet-assets/asset-register/labels';
        $this->actingAs($viewer)->postJson($url, $payload)->assertForbidden();
        $this->assertNull($this->asset->fresh()->qr_token);
        $id = $this->actingAs($this->manager)->postJson($url, $payload)->assertCreated()->json('id');
        $this->assertNotNull($this->asset->fresh()->qr_token);
        AssetLabelBatch::whereKey($id)->update(['expires_at' => now()->subMinute()]);
        $this->get($url.'/'.$id.'/pdf')->assertGone();
        $this->postJson($url, $payload)->assertGone();
    }

    public function test_label_workspace_filters_canonical_lifecycle_without_leaking_other_sites(): void
    {
        $visible = Asset::factory()->forSite($this->origin)->create(['category' => 'equipment', 'status' => 'out_of_service']);
        $foreign = Site::factory()->create(['is_active' => true, 'archived' => false, 'archived_at' => null]);
        Asset::factory()->forSite($foreign)->create(['category' => 'equipment', 'status' => 'out_of_service']);
        $this->actingAs($this->manager)->get('/fleet-assets/asset-register/labels/workspace?status=out_of_service')
            ->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
            ->component('fleet-assets/assets/labels')->where('assets.total', 1)
            ->where('assets.data.0.id', $visible->id)->has('matching', 1));
    }

    public function test_published_label_history_replays_its_original_layout_but_new_batches_use_the_shared_minimum(): void
    {
        $url = '/fleet-assets/asset-register/labels';
        $layout = ['width' => 60, 'height' => 45, 'margin' => 10, 'gap' => 3, 'copies' => 1, 'start' => 1];
        $batch = AssetLabelBatch::create(['request_id' => (string) Str::uuid(), 'created_by_user_id' => $this->manager->id,
            'asset_ids' => [$this->asset->id], 'layout' => [...$layout, 'columns' => 3, 'rows' => 5],
            'downloads' => [['at' => now()->toISOString(), 'format' => 'pdf', 'status' => 'generated']], 'expires_at' => now()->addDay()]);
        $payload = ['request_id' => $batch->request_id, 'asset_ids' => [$this->asset->id], 'layout' => $layout];
        $this->actingAs($this->manager)->postJson($url, $payload)->assertOk()->assertJsonPath('id', $batch->id);
        $this->postJson($url, [...$payload, 'request_id' => (string) Str::uuid()])->assertUnprocessable();
        $this->postJson($url, [...$payload, 'layout' => [...$layout, 'height' => 50]])->assertConflict();
        $this->get($url.'/'.$batch->id.'/pdf')->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $this->assertCount(2, $batch->fresh()->downloads);
        $this->assertSame(45, $batch->fresh()->layout['height']);
        $this->assertDatabaseCount('asset_label_batches', 1);
    }

    public function test_pending_receipt_blocks_register_room_edits_and_rechecks_the_destination_room(): void
    {
        $originRoom = SiteRoom::create(['site_id' => $this->origin->id, 'name' => 'Origin equipment room']);
        $destinationRoom = SiteRoom::create(['site_id' => $this->destination->id, 'name' => 'Destination equipment room']);
        $recipient = $this->user([$this->destination]);
        $move = $this->command('dispatch', ['destination_site_id' => $this->destination->id, 'destination_room_id' => $destinationRoom->id, 'recipient_user_id' => $recipient->id])->assertOk()->json('movement_id');
        $this->actingAs($this->manager)->putJson('/fleet-assets/assets/'.$this->asset->id, ['name' => $this->asset->name, 'site_id' => $this->origin->id, 'site_room_id' => $originRoom->id, 'status' => 'active', 'risk_level' => 'low'])->assertUnprocessable()->assertJsonValidationErrors('site_room_id');
        $this->assertNull($this->asset->fresh()->site_room_id);
        $destinationRoom->update(['site_id' => $this->origin->id]);
        $this->command('receive', ['movement_id' => $move, 'outcome' => 'acknowledged', 'received_kit' => []])->assertNotFound();
        $this->assertSame($this->origin->id, $this->asset->fresh()->site_id);
        $this->assertSame('pending_receipt', AssetCustodyMovement::findOrFail($move)->state);
        $destinationRoom->update(['site_id' => $this->destination->id]);
        $this->command('receive', ['movement_id' => $move, 'outcome' => 'acknowledged', 'received_kit' => []])->assertOk();
        $this->assertSame($destinationRoom->id, $this->asset->fresh()->site_room_id);
    }

    public function test_ownership_requires_its_permission_and_preserves_canonical_placement(): void
    {
        $data = ['owner_type' => 'site', 'owner_id' => $this->origin->id];
        $this->command('ownership', $data)->assertForbidden();
        $permission = Permission::firstOrCreate(['key' => 'assets.ownership.manage'], ['description' => 'Manage ownership', 'group' => 'assets']);
        $this->manager->permissionOverrides()->syncWithoutDetaching([$permission->id => ['allowed' => true]]);
        $this->manager = $this->manager->fresh();
        $this->command('ownership', [...$data, 'owner_id' => $this->destination->id])->assertNotFound();
        $id = $this->command('ownership', $data)->assertOk()->json('ownership_id');
        $this->assertDatabaseHas('asset_ownerships', ['id' => $id, 'asset_id' => $this->asset->id, 'owner_id' => $this->origin->id, 'effective_to' => null]);
        $this->command('ownership', $data)->assertUnprocessable();
        $this->assertSame(1, AssetProfileEvent::where('asset_id', $this->asset->id)->where('action', 'ownership')->count());
        $this->assertSame($this->origin->id, $this->asset->fresh()->site_id);
        $this->assertSame(0, AssetCustodyMovement::where('asset_id', $this->asset->id)->count());
    }

    public function test_replacement_review_records_a_request_without_retiring_or_posting(): void
    {
        $permission = Permission::firstOrCreate(['key' => 'finance.assets.view'], ['description' => 'Finance assets', 'group' => 'finance']);
        $this->manager->permissionOverrides()->syncWithoutDetaching([$permission->id => ['allowed' => true]]);
        $before = DB::table('fin_journals')->count();
        $id = $this->actingAs($this->manager->fresh())->postJson('/assets/'.$this->asset->id.'/finance-review', ['request_type' => 'replacement_review', 'source' => 'vehicle', 'note' => 'Review replacement after the brake assessment', 'amount' => '5000.00', 'request_key' => (string) Str::uuid()])->assertOk()->json('id');
        $request = FleetFinanceReviewRequest::findOrFail($id);
        $this->assertSame('replacement_review', $request->request_type);
        $this->assertSame('submitted', $request->status);
        $this->assertSame('active', $this->asset->fresh()->status);
        $this->assertSame($before, DB::table('fin_journals')->count());
    }

    public function test_asset_visibility_does_not_expand_finance_review_scope_or_replacement_details(): void
    {
        $requester = $this->user([$this->origin], ['assets.viewAny', 'assets.update', 'finance.assets.view']);
        $id = $this->actingAs($requester)->postJson('/assets/'.$this->asset->id.'/finance-review', [
            'request_type' => 'replacement_review', 'source' => 'vehicle', 'note' => 'Restricted replacement proposal',
            'amount' => '8123.45', 'request_key' => (string) Str::uuid(),
        ])->assertOk()->json('id');
        $review = FleetFinanceReviewRequest::findOrFail($id);
        $review->update(['status' => 'resolved', 'decision_note' => 'Restricted source decision']);
        $viewer = $this->user([$this->destination], ['assets.viewAny', 'assets.update', 'finance.assets.view', 'securityDevices.devices.viewAllSites']);
        $this->assertTrue(Gate::forUser($viewer)->allows('view', $this->asset));
        $this->assertNotContains($this->origin->id, app(VehicleFinanceService::class)->financeSiteIds($viewer));
        $this->assertNull(app(VehicleFinanceReviewQueue::class)->present($viewer, [], $id)['focus']);
        $this->actingAs($viewer)->get('/fleet-assets/assets/'.$this->asset->id)->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
            ->has('workspace.finance_reviews', 0)->where('workspace.permissions.finance_review', true)
            ->where('workspace.sources.costs.allowed', false)->has('workspace.sources.costs.totals', 0));

        $local = $this->user([$this->origin], ['assets.viewAny', 'finance.assets.view']);
        $central = $this->user([$this->destination], ['assets.viewAny', 'finance.assets.view', 'securityDevices.devices.viewAllSites', 'finance.insights.viewAllSites']);
        foreach ([$local, $central] as $allowed) {
            $this->assertContains($this->origin->id, app(VehicleFinanceService::class)->financeSiteIds($allowed));
            $this->actingAs($allowed)->get('/fleet-assets/assets/'.$this->asset->id)->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
                ->has('workspace.finance_reviews', 1)->where('workspace.finance_reviews.0.id', $id)
                ->where('workspace.finance_reviews.0.amount', '8123.45')->where('workspace.finance_reviews.0.decision', 'Restricted source decision')
                ->where('workspace.finance_reviews.0.url', '/finance/vehicle-reviews?request='.$id)
                ->where('workspace.permissions.finance_review', false));
            $this->assertFalse(app(VehicleFinanceReviewQueue::class)->present($allowed, [], $id)['focus']['can_decide']);
        }
        $review->update(['source_type' => 'bill', 'source_id' => 987]);
        $this->actingAs($local)->get('/fleet-assets/assets/'.$this->asset->id)->assertOk()->assertInertia(fn (AssertableInertia $page) => $page->has('workspace.finance_reviews', 0));
        $payables = $this->user([$this->origin], ['assets.viewAny', 'finance.assets.view', 'finance.ap.view']);
        $this->actingAs($payables)->get('/fleet-assets/assets/'.$this->asset->id)->assertOk()->assertInertia(fn (AssertableInertia $page) => $page->has('workspace.finance_reviews', 1));
    }

    public function test_complete_ordinary_edits_cannot_split_parent_or_component_but_unrelated_edits_and_unlinked_moves_work(): void
    {
        $component = Asset::factory()->forSite($this->origin)->create(['category' => 'equipment', 'status' => 'active', 'home_site_id' => $this->origin->id]);
        $this->ordinaryEdit($component, ['site_id' => $this->destination->id, 'home_site_id' => $this->destination->id])->assertRedirect();
        $this->ordinaryEdit($component, ['site_id' => $this->origin->id, 'home_site_id' => $this->origin->id])->assertRedirect();
        $item = $this->command('kit_add', ['name' => 'Linked sling', 'component_asset_id' => $component->id])->assertOk()->json('kit_item_id');
        foreach ([$this->asset, $component] as $member) {
            $this->ordinaryEdit($member, ['description' => 'Ordinary detail correction'])->assertRedirect();
            $this->ordinaryEdit($member, ['site_id' => $this->destination->id, 'home_site_id' => $this->destination->id])->assertUnprocessable()->assertJsonValidationErrors('site_id');
            $this->assertSame($this->origin->id, $member->fresh()->site_id);
        }
        $this->assertNull(AssetKitItem::findOrFail($item)->removed_at);
        $this->command('kit_remove', ['kit_item_id' => $item])->assertOk();
        $this->ordinaryEdit($component, ['site_id' => $this->destination->id, 'home_site_id' => $this->destination->id])->assertRedirect();
        $this->assertSame($this->destination->id, $component->fresh()->site_id);
        $this->assertNotNull(AssetKitItem::findOrFail($item)->removed_at);
        $this->assertSame(0, AssetCustodyMovement::whereIn('asset_id', [$this->asset->id, $component->id])->count());
    }

    public function test_linked_kit_room_home_site_and_client_placement_cannot_be_changed_by_ordinary_edit(): void
    {
        $component = Asset::factory()->forSite($this->origin)->create(['category' => 'equipment', 'status' => 'active', 'home_site_id' => $this->origin->id]);
        $room = SiteRoom::create(['site_id' => $this->origin->id, 'name' => 'Other room']);
        $client = Client::factory()->create(['site_id' => $this->origin->id]);
        $permission = Permission::firstOrCreate(['key' => 'clients.viewAny'], ['description' => 'View clients', 'group' => 'clients']);
        $this->manager->permissionOverrides()->syncWithoutDetaching([$permission->id => ['allowed' => true]]);
        $this->manager = $this->manager->fresh();
        $this->command('kit_add', ['name' => 'Linked sling', 'component_asset_id' => $component->id])->assertOk();
        foreach ([$this->asset, $component] as $member) {
            foreach (['site_room_id' => $room->id, 'home_site_id' => $this->destination->id, 'client_id' => $client->id, 'location' => 'Different cupboard'] as $field => $value) {
                $this->ordinaryEdit($member, [$field => $value])->assertUnprocessable()->assertJsonValidationErrors($field);
            }
            $this->assertNull($member->fresh()->site_room_id);
            $this->assertNull($member->fresh()->client_id);
            $this->assertSame($this->origin->id, $member->fresh()->home_site_id);
        }
    }

    public function test_component_with_an_unresolved_movement_cannot_be_linked_to_a_kit(): void
    {
        foreach (['pending_receipt', 'incomplete', 'disputed'] as $state) {
            $component = Asset::factory()->forSite($this->origin)->create(['category' => 'equipment', 'status' => 'active']);
            $movement = $this->componentCommand($component, 'dispatch', ['destination_site_id' => $this->destination->id, 'recipient_user_id' => $this->manager->id])->assertOk()->json('movement_id');
            if ($state !== 'pending_receipt') {
                $this->componentCommand($component, 'receive', ['movement_id' => $movement, 'outcome' => $state, 'received_kit' => []])->assertOk();
            }
            $this->command('kit_add', ['name' => 'Unresolved component', 'component_asset_id' => $component->id])->assertConflict();
            $this->assertSame(0, AssetKitItem::where('component_asset_id', $component->id)->count());
            $this->assertSame($state, AssetCustodyMovement::findOrFail($movement)->state);
            $this->componentCommand($component, 'receive', ['movement_id' => $movement, 'outcome' => 'acknowledged', 'received_kit' => []])->assertOk();
            $this->assertSame($this->destination->id, $component->fresh()->site_id);
        }
    }

    public function test_outstanding_component_loan_must_return_before_kit_linking(): void
    {
        $component = Asset::factory()->forSite($this->origin)->create(['category' => 'equipment', 'status' => 'active']);
        $movement = $this->componentCommand($component, 'dispatch', ['kind' => 'loan', 'destination_site_id' => $this->origin->id, 'recipient_user_id' => $this->manager->id, 'return_due_on' => today()->addWeek()->toDateString()])->assertOk()->json('movement_id');
        $this->componentCommand($component, 'receive', ['movement_id' => $movement, 'outcome' => 'acknowledged', 'received_kit' => []])->assertOk();
        $this->command('kit_add', ['name' => 'Loan component', 'component_asset_id' => $component->id])->assertConflict();
        $return = $this->componentCommand($component, 'return', ['movement_id' => $movement, 'recipient_user_id' => $this->manager->id])->assertOk()->json('movement_id');
        $this->componentCommand($component, 'receive', ['movement_id' => $return, 'outcome' => 'acknowledged', 'received_kit' => []])->assertOk();
        $this->command('kit_add', ['name' => 'Returned component', 'component_asset_id' => $component->id])->assertOk();
        $this->assertSame('returned', AssetCustodyMovement::findOrFail($movement)->state);
    }

    public function test_receipt_refuses_a_legacy_independently_linked_component_until_explicitly_removed(): void
    {
        $component = Asset::factory()->forSite($this->origin)->create(['category' => 'equipment', 'status' => 'active']);
        $movement = $this->componentCommand($component, 'dispatch', ['destination_site_id' => $this->destination->id, 'recipient_user_id' => $this->manager->id])->assertOk()->json('movement_id');
        // Existing inconsistent data from the earlier implementation still fails closed at receipt.
        $link = AssetKitItem::create(['asset_id' => $this->asset->id, 'component_asset_id' => $component->id, 'name' => 'Legacy link', 'added_by_user_id' => $this->manager->id]);
        $this->componentCommand($component, 'receive', ['movement_id' => $movement, 'outcome' => 'acknowledged', 'received_kit' => []])->assertUnprocessable();
        $this->assertSame($this->origin->id, $component->fresh()->site_id);
        $this->assertSame('pending_receipt', AssetCustodyMovement::findOrFail($movement)->state);
        $this->assertNull($link->fresh()->removed_at);
        $this->command('kit_remove', ['kit_item_id' => $link->id])->assertOk();
        $this->componentCommand($component, 'receive', ['movement_id' => $movement, 'outcome' => 'acknowledged', 'received_kit' => []])->assertOk();
        $this->assertNotNull($link->fresh()->removed_at);
    }

    private function ordinaryEdit(Asset $asset, array $values = [])
    {
        $asset = $asset->fresh();

        return $this->actingAs($this->manager)->putJson('/fleet-assets/assets/'.$asset->id, array_replace([
            'name' => $asset->name, 'category' => 'equipment', 'status' => 'active', 'risk_level' => 'low',
            'site_id' => $asset->site_id, 'home_site_id' => $asset->home_site_id, 'expected_version' => $asset->asset_profile_version,
        ], $values));
    }

    private function componentCommand(Asset $asset, string $action, array $values)
    {
        return $this->actingAs($this->manager)->postJson('/assets/'.$asset->id.'/profile-actions', $values + [
            'action' => $action, 'request_key' => (string) Str::uuid(), 'expected_version' => $asset->fresh()->asset_profile_version, 'reason' => 'Synthetic component custody',
        ]);
    }

    private function command(string $action, array $values = [])
    {
        return $this->actingAs($this->manager)->postJson('/assets/'.$this->asset->id.'/profile-actions', $values + ['action' => $action, 'request_key' => (string) Str::uuid(), 'expected_version' => $this->asset->fresh()->asset_profile_version, 'reason' => 'Recorded fixture observation']);
    }

    private function user(array $sites, array $permissions = []): User
    {
        $user = User::factory()->create(['approved_at' => now()]);
        HrEmployeeProfile::factory()->create(['user_id' => $user->id, 'primary_site_id' => $sites[0]->id, 'secondary_site_ids' => array_map(fn ($site) => $site->id, array_slice($sites, 1)), 'is_active' => true, 'start_date' => today()->subYear(), 'end_date' => null]);
        foreach ($permissions as $key) {
            $permission = Permission::firstOrCreate(['key' => $key], ['description' => $key, 'group' => 'assets']);
            $user->permissionOverrides()->syncWithoutDetaching([$permission->id => ['allowed' => true]]);
        }

        return $user;
    }
}
