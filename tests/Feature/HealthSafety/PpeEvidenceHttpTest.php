<?php

namespace Tests\Feature\HealthSafety;

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Models\Permission;
use App\Models\PpeAllocation;
use App\Models\PpeAllocationAttachment;
use App\Models\PpeAttachment;
use App\Models\PpeCommandReceipt;
use App\Models\PpeEvidenceRelocationCleanup;
use App\Models\PpeEvidenceStaging;
use App\Models\PpeInspection;
use App\Models\PpeInspectionAttachment;
use App\Models\PpeInventory;
use App\Models\Site;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\DataProvider;

/** Complete actual HTTP file publication and recovery; physical execution requires future native admission. */
final class PpeEvidenceHttpTest extends PpeCommandTestCase
{
    protected bool $physicalCommits = true;
    private const BYTES = 'Actual retained PPE HTTP evidence';

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
        Storage::fake('private');
    }

    public static function parents(): array
    {
        return [['inventory'], ['allocation'], ['inspection']];
    }

    #[DataProvider('parents')]
    public function test_three_literal_http_transfers_preserve_identity_and_exact_retry_after_bytes_move(string $kind): void
    {
        [$root, $parent, $file] = $this->fixture($kind);
        $before = $file->getAttributes();
        $input = $this->command($root, ['reason' => 'Protect the actual retained file.']);
        $receipt = $this->actingAs($this->manager)->postJson($this->fileUrl($kind, $parent->id, $file->id).'/relocate', $input)
            ->assertOk()->assertJsonPath('ppe_confirmation.accepted', true)->json('ppe_confirmation');
        $this->assertReceipt($receipt, $kind.'_attachment', $file->id, $input['idempotency_key']);
        foreach (['id', 'uploaded_by', 'original_name', 'mime', 'size', 'kind', 'notes', 'created_at'] as $field) {
            $this->assertSame($before[$field], $file->fresh()->getAttributes()[$field]);
        }
        Storage::disk('public')->assertMissing($before['path']);
        Storage::disk('private')->assertExists($file->fresh()->path);
        $download = $this->get($this->fileUrl($kind, $parent->id, $file->id).'/download')->assertOk()
            ->assertHeader('Referrer-Policy', 'no-referrer');
        $this->assertSame(self::BYTES, $download->streamedContent());
        $this->assertStringContainsString('no-store', $download->headers->get('Cache-Control'));
        $this->service()->updateInventory($root, $this->manager, $this->command($root, ['location' => 'Later recorded rack']));
        $this->postJson($this->fileUrl($kind, $parent->id, $file->id).'/relocate', $input)->assertOk()->assertJsonPath('ppe_confirmation', $receipt);
        $this->assertSame(1, PpeEvidenceStaging::where('idempotency_key', $input['idempotency_key'])->count());
        $this->assertSame(1, PpeCommandReceipt::where('idempotency_key', $input['idempotency_key'])->count());
        $this->getJson('/health-safety/ppe/command-confirmation?receipt_key='.$input['idempotency_key'])->assertOk()->assertJsonPath('ppe_confirmation', $receipt);
        $this->postJson($this->fileUrl($kind, $parent->id, $file->id).'/relocate', [...$input, 'reason' => 'Changed intent'])
            ->assertUnprocessable()->assertJsonValidationErrors('idempotency_key');
    }

    #[DataProvider('parents')]
    public function test_foreign_parent_and_foreign_site_attachment_ids_are_filtered_before_private_hydration(string $kind): void
    {
        [$root, $parent] = $this->fixture($kind);
        foreach ([false, true] as $foreignSite) {
            [$otherRoot, $otherParent, $otherFile] = $this->fixture($kind, $foreignSite);
            DB::flushQueryLog(); DB::enableQueryLog();
            try {
                $this->actingAs($this->manager)->postJson($this->fileUrl($kind, $parent->id, $otherFile->id).'/relocate',
                    $this->command($root, ['reason' => 'Exact parent is required.']))->assertNotFound();
                $queries = DB::getQueryLog();
            } finally { DB::disableQueryLog(); }
            $fileReads = array_filter($queries, fn ($entry) => preg_match('/from\s+[`"]?'.preg_quote($otherFile->getTable(), '/').'[`"]?\b/i', $entry['query']));
            $this->assertNotEmpty($fileReads);
            foreach ($fileReads as $entry) {
                $where = strtolower(substr($entry['query'], stripos($entry['query'], ' where ')));
                $this->assertStringContainsString($this->foreignKey($kind), $where);
                $this->assertContains($parent->id, $entry['bindings']);
            }
            $this->assertSame('public', $otherFile->fresh()->disk);
            $this->assertSame(0, PpeEvidenceStaging::where('root_id', $root->id)->count());
            Storage::disk('public')->assertExists($otherFile->path);
        }
    }

    public function test_download_after_parent_site_move_is_denied_then_restored_without_recertifying_bytes(): void
    {
        [$root, $parent, $file] = $this->fixture();
        $this->actingAs($this->manager)->postJson($this->fileUrl('inventory', $parent->id, $file->id).'/relocate',
            $this->command($root, ['reason' => 'Protect retained evidence.']))->assertOk();
        $foreign = Site::factory()->create(['is_active' => true]);
        DB::table('ppe_inventory')->where('id', $root->id)->update(['site_id' => $foreign->id]);
        $this->get($this->fileUrl('inventory', $parent->id, $file->id).'/download')->assertNotFound();
        Storage::disk('private')->assertExists($file->fresh()->path);
        // External tuple damage is not a supported transfer; restore the original immutable tuple.
        DB::table('ppe_inventory')->where('id', $root->id)->update(['site_id' => $this->site->id]);
        $this->assertSame(self::BYTES, $this->get($this->fileUrl('inventory', $parent->id, $file->id).'/download')->assertOk()->streamedContent());
    }

    public function test_relocation_recovery_three_endpoints_are_json_only_and_emit_minimal_delivery_state(): void
    {
        [$root, $parent, $file] = $this->fixture();
        [$manager] = $this->mockDisk('public', fn ($disk) => $disk->shouldReceive('delete')->andReturn(false));
        try {
            $receipt = $this->actingAs($this->manager)->postJson($this->fileUrl('inventory', $parent->id, $file->id).'/relocate',
                $this->command($root, ['reason' => 'Protect retained evidence.']))->assertOk()->json('ppe_confirmation');
        } finally { Storage::swap($manager); }
        $cleanup = PpeEvidenceRelocationCleanup::where('receipt_id', PpeCommandReceipt::where('idempotency_key', $receipt['idempotency_key'])->sole()->id)->sole();
        $base = '/health-safety/ppe/relocation-cleanup';
        $this->get($base)->assertNotFound();
        $this->get($base.'/'.$cleanup->id)->assertNotFound();
        $this->post($base.'/'.$cleanup->id.'/retry')->assertNotFound();
        $list = $this->getJson($base)->assertOk()->assertJsonPath('attempts.0.id', $cleanup->id)
            ->assertJsonPath('attempts.0.state', 'pending')->assertJsonPath('attempts.0.private_reference_recorded', true);
        $this->assertSafeDelivery($list);
        $detail = $this->getJson($base.'/'.$cleanup->id)->assertOk()->assertJsonPath('relocation_cleanup.public_copy_cleanup', 'pending');
        $this->assertSafeDelivery($detail);
        $retry = $this->postJson($base.'/'.$cleanup->id.'/retry')->assertOk()->assertJsonPath('relocation_cleanup.public_copy_cleanup', 'complete');
        $this->assertSafeDelivery($retry);
        $this->assertSame(2, $root->fresh()->source_revision);
        $this->assertSame($receipt, PpeCommandReceipt::where('idempotency_key', $receipt['idempotency_key'])->sole()->accepted_result);
        $this->getJson($base)->assertOk()->assertJsonPath('attempts', []);
    }

    public function test_recovery_rechecks_current_actor_permission_hr_and_site_without_losing_durable_pending_work(): void
    {
        [$root, $parent, $file] = $this->fixture();
        [$manager] = $this->mockDisk('public', fn ($disk) => $disk->shouldReceive('delete')->andReturn(false));
        try {
            $this->actingAs($this->manager)->postJson($this->fileUrl('inventory', $parent->id, $file->id).'/relocate',
                $this->command($root, ['reason' => 'Protect retained evidence.']))->assertOk();
        } finally { Storage::swap($manager); }
        $cleanup = PpeEvidenceRelocationCleanup::sole(); $base = '/health-safety/ppe/relocation-cleanup';
        $other = $this->staff(['hazards.view', 'hazards.manage']);
        $this->actingAs($other)->getJson($base)->assertOk()->assertJsonPath('attempts', []);
        $this->getJson($base.'/'.$cleanup->id)->assertNotFound();
        $this->postJson($base.'/'.$cleanup->id.'/retry')->assertNotFound();
        $this->actingAs($this->manager); $this->revoke($this->manager, 'hazards.manage');
        $this->getJson($base)->assertForbidden(); $this->getJson($base.'/'.$cleanup->id)->assertForbidden();
        $this->postJson($base.'/'.$cleanup->id.'/retry')->assertForbidden();
        $this->manager->permissionOverrides()->syncWithoutDetaching([Permission::where('key', 'hazards.manage')->sole()->id => ['allowed' => true]]);
        HrEmployeeProfile::where('user_id', $this->manager->id)->update(['is_active' => false]);
        $this->getJson($base.'/'.$cleanup->id)->assertForbidden();
        HrEmployeeProfile::where('user_id', $this->manager->id)->update(['is_active' => true]);
        $foreign = Site::factory()->create(['is_active' => true]);
        HrEmployeeProfile::where('user_id', $this->manager->id)->update(['primary_site_id' => $foreign->id, 'secondary_site_ids' => []]);
        $this->getJson($base)->assertOk()->assertJsonPath('attempts', []);
        $this->getJson($base.'/'.$cleanup->id)->assertNotFound(); $this->postJson($base.'/'.$cleanup->id.'/retry')->assertNotFound();
        $this->assertSame('pending', $cleanup->fresh()->state);
        HrEmployeeProfile::where('user_id', $this->manager->id)->update(['primary_site_id' => $this->site->id]);
        $this->postJson($base.'/'.$cleanup->id.'/retry')->assertOk()->assertJsonPath('relocation_cleanup.state', 'complete');
    }

    public function test_other_public_reference_keeps_cleanup_unknown_and_private_download_readable(): void
    {
        [$root, $parent, $file] = $this->fixture(); $origin = $file->path;
        [$manager] = $this->mockDisk('public', fn ($disk) => $disk->shouldReceive('delete')->andReturn(false));
        try {
            $this->actingAs($this->manager)->postJson($this->fileUrl('inventory', $parent->id, $file->id).'/relocate',
                $this->command($root, ['reason' => 'Protect retained evidence.']))->assertOk();
        } finally { Storage::swap($manager); }
        $other = $this->item();
        PpeAttachment::create(['ppe_inventory_id' => $other->id, 'disk' => 'public', 'path' => $origin, 'original_name' => 'Independent retained source.txt']);
        $cleanup = PpeEvidenceRelocationCleanup::sole();
        $response = $this->postJson('/health-safety/ppe/relocation-cleanup/'.$cleanup->id.'/retry')->assertOk()
            ->assertJsonPath('relocation_cleanup.state', 'unknown')->assertJsonPath('relocation_cleanup.private_reference_recorded', true);
        $this->assertSafeDelivery($response); Storage::disk('public')->assertExists($origin);
        $this->getJson('/health-safety/ppe/relocation-cleanup')->assertOk()->assertJsonPath('attempts.0.state', 'unknown');
        $this->assertSame(self::BYTES, $this->get($this->fileUrl('inventory', $parent->id, $file->id).'/download')->assertOk()->streamedContent());
        $this->assertSame(2, $root->fresh()->source_revision);
    }

    public function test_current_read_only_and_own_viewers_cannot_relocate_or_use_delivery_recovery(): void
    {
        [$root, $parent, $file] = $this->fixture('allocation');
        $reader = $this->staff(['hazards.view']);
        $input = $this->command($root, ['reason' => 'No write grant.']);
        foreach ([$reader, $this->worker] as $actor) {
            $this->actingAs($actor)->postJson($this->fileUrl('allocation', $parent->id, $file->id).'/relocate', $input)->assertForbidden();
            $this->getJson('/health-safety/ppe/relocation-cleanup')->assertForbidden();
        }
        $this->assertSame('public', $file->fresh()->disk); $this->assertSame(0, PpeEvidenceStaging::count());
        Storage::disk('public')->assertExists($file->path);
    }

    public function test_actual_html_transfer_redirect_uses_canonical_record_and_receipt_hint_only_once(): void
    {
        [$root, $parent, $file] = $this->fixture('inspection');
        $response = $this->actingAs($this->manager)->post($this->fileUrl('inspection', $parent->id, $file->id).'/relocate',
            [...$this->command($root, ['reason' => 'Protect the retained check file.']), 'return_to' => '/health-safety/ppe?tab=inv_all'])
            ->assertRedirect()->assertSessionHas('ppe_confirmation');
        $receipt = session('ppe_confirmation'); $this->assertReceipt($receipt, 'inspection_attachment', $file->id, $receipt['idempotency_key']);
        $target = $response->headers->get('Location');
        $this->assertSame('/health-safety/ppe/inventory/'.$root->id, parse_url($target, PHP_URL_PATH));
        $this->assertStringNotContainsString($receipt['idempotency_key'], $target);
        $this->get($target)->assertOk()->assertInertia(fn (Assert $page) => $page->where('ppe_confirmation', $receipt));
        $this->get($target)->assertOk()->assertInertia(fn (Assert $page) => $page->where('ppe_confirmation', null));
    }

    #[DataProvider('parents')]
    public function test_download_requires_literal_parent_kind_and_exact_attachment_fk(string $kind): void
    {
        [$root, $parent, $file] = $this->fixture($kind);
        $this->actingAs($this->manager)->postJson($this->fileUrl($kind, $parent->id, $file->id).'/relocate',
            $this->command($root, ['reason' => 'Protect the exact source file.']))->assertOk();
        [$otherRoot, $otherParent] = $this->fixture($kind);
        $this->get($this->fileUrl($kind, $otherParent->id, $file->id).'/download')->assertNotFound();
        Storage::disk('private')->assertExists($file->fresh()->path);
    }

    public function test_changed_public_or_missing_private_copy_keeps_original_delivery_unknown_without_deletion(): void
    {
        [$root, $parent, $file] = $this->fixture(); $origin = $file->path;
        [$manager] = $this->mockDisk('public', fn ($disk) => $disk->shouldReceive('delete')->andReturn(false));
        try {
            $this->actingAs($this->manager)->postJson($this->fileUrl('inventory', $parent->id, $file->id).'/relocate',
                $this->command($root, ['reason' => 'Protect retained evidence.']))->assertOk();
        } finally { Storage::swap($manager); }
        $cleanup = PpeEvidenceRelocationCleanup::sole(); $retry = '/health-safety/ppe/relocation-cleanup/'.$cleanup->id.'/retry';
        Storage::disk('public')->put($origin, 'Unattributed replacement');
        $this->postJson($retry)->assertOk()->assertJsonPath('relocation_cleanup.state', 'unknown');
        $this->assertSame('Unattributed replacement', Storage::disk('public')->get($origin));
        Storage::disk('public')->put($origin, self::BYTES); Storage::disk('private')->delete($file->fresh()->path);
        $this->postJson($retry)->assertOk()->assertJsonPath('relocation_cleanup.state', 'unknown');
        Storage::disk('public')->assertExists($origin);
        $this->get($this->fileUrl('inventory', $parent->id, $file->id).'/download')->assertNotFound();
        $this->assertSame(2, $root->fresh()->source_revision);
    }

    public function test_http_acceptance_refusal_keeps_public_source_and_physically_retained_rejected_staging(): void
    {
        [$root, $parent, $file] = $this->fixture(); $origin = $file->path;
        $input = $this->command($root, ['reason' => 'Acceptance must be durable.']);
        \App\Models\AuditLog::creating(fn ($row) => $row->action === 'ppe.relocate_attachment' ? false : null);
        $this->withoutExceptionHandling();
        try {
            $this->integrity(fn () => $this->actingAs($this->manager)->postJson($this->fileUrl('inventory', $parent->id, $file->id).'/relocate', $input));
        } finally { \App\Models\AuditLog::flushEventListeners(); $this->withExceptionHandling(); }
        $attempt = PpeEvidenceStaging::where('idempotency_key', $input['idempotency_key'])->sole();
        $this->assertNull($attempt->receipt_id); $this->assertSame('cleaned', $attempt->state);
        $this->assertSame('public', $file->fresh()->disk); Storage::disk('public')->assertExists($origin);
        $this->assertSame(1, $root->fresh()->source_revision);
        $this->assertSame(0, PpeCommandReceipt::where('idempotency_key', $input['idempotency_key'])->count());
        $this->assertSame(0, PpeEvidenceRelocationCleanup::count());
        $response = $this->getJson('/health-safety/ppe/evidence-staging/'.$attempt->id)->assertOk()
            ->assertJsonPath('staging.accepted', false)->assertJsonPath('staging.state', 'cleaned');
        $this->assertSafeDelivery($response);
        $this->getJson('/health-safety/ppe/command-confirmation?receipt_key='.$input['idempotency_key'])->assertOk()->assertJsonPath('ppe_confirmation', null);
    }

    private function fixture(string $kind = 'inventory', bool $foreign = false): array
    {
        $root = $foreign ? PpeInventory::factory()->create(['ppe_type_id' => \App\Models\PpeType::factory()->create()->id,
            'site_id' => Site::factory()->create(['is_active' => true])->id]) : $this->item();
        $parent = match ($kind) {
            'inventory' => $root,
            'allocation' => $foreign ? PpeAllocation::factory()->create(['ppe_inventory_id' => $root->id, 'user_id' => $this->worker->id]) : $this->issue($root),
            'inspection' => $foreign ? PpeInspection::factory()->create(['ppe_inventory_id' => $root->id, 'inspected_by' => $this->manager->id])
                : PpeInspection::findOrFail($this->service()->inspect($root, $this->manager, $this->command($root, ['result' => 'pass']))['source_id']),
        };
        $class = match ($kind) { 'inventory' => PpeAttachment::class, 'allocation' => PpeAllocationAttachment::class, 'inspection' => PpeInspectionAttachment::class };
        $folder = match ($kind) { 'inventory' => 'ppe_attachments', 'allocation' => 'ppe_allocation_attachments', 'inspection' => 'ppe_inspection_attachments' };
        $path = $folder.'/'.sha1((string) Str::uuid()).'.txt'; Storage::disk('public')->put($path, self::BYTES);
        $file = $class::create([$this->foreignKey($kind) => $parent->id, 'uploaded_by' => $this->worker->id, 'disk' => 'public', 'path' => $path,
            'original_name' => 'Retained PPE HTTP evidence.txt', 'mime' => 'text/plain', 'size' => strlen(self::BYTES), 'kind' => 'document', 'notes' => 'Historical evidence note']);
        return [$root->fresh(), $parent, $file];
    }

    private function foreignKey(string $kind): string
    {
        return match ($kind) { 'inventory' => 'ppe_inventory_id', 'allocation' => 'ppe_allocation_id', 'inspection' => 'ppe_inspection_id' };
    }

    private function fileUrl(string $kind, int $parent, int $file): string
    {
        return '/health-safety/ppe/'.($kind === 'inventory' ? 'inventory' : $kind.'s').'/'.$parent.'/attachments/'.$file;
    }

    private function assertReceipt(array $receipt, string $sourceType, int $sourceId, string $key): void
    {
        $this->assertEqualsCanonicalizing(['accepted', 'actor_id', 'command', 'idempotency_key', 'root_type', 'root_id', 'source_type',
            'source_id', 'expected_version', 'version', 'source_version_id'], array_keys($receipt));
        $this->assertTrue($receipt['accepted']); $this->assertSame('relocate_attachment', $receipt['command']);
        $this->assertSame($sourceType, $receipt['source_type']); $this->assertSame($sourceId, $receipt['source_id']);
        $this->assertSame($key, $receipt['idempotency_key']);
        foreach ($receipt as $value) { $this->assertTrue($value === null || is_scalar($value)); }
    }

    private function assertSafeDelivery($response): void
    {
        $response->assertHeader('Referrer-Policy', 'no-referrer');
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        $json = json_encode($response->json(), JSON_THROW_ON_ERROR);
        $this->assertDoesNotMatchRegularExpression('/[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}/i', $json);
        $this->assertDoesNotMatchRegularExpression('~ppe_(?:allocation_|inspection_)?attachments[/\\\\]~', $json);
        foreach (['origin_path', 'destination_path', 'idempotency_key', 'file_digest', 'original_name', 'file_size', 'source_version_id', 'intent', 'notes'] as $private) {
            $this->assertStringNotContainsString('"'.$private.'"', $json);
        }
    }

    private function mockDisk(string $which, callable $configure): array
    {
        $manager = Storage::getFacadeRoot(); $real = $manager->disk($which); $other = $manager->disk($which === 'public' ? 'private' : 'public');
        $disk = \Mockery::mock($real)->makePartial(); $configure($disk);
        $facade = \Mockery::mock(); $facade->shouldReceive('disk')->with($which)->andReturn($disk);
        $facade->shouldReceive('disk')->with($which === 'public' ? 'private' : 'public')->andReturn($other);
        Storage::swap($facade); return [$manager, $real];
    }
}
