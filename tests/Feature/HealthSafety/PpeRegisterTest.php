<?php

namespace Tests\Feature\HealthSafety;

use App\Models\PpeAllocation;
use App\Models\PpeAttachment;
use App\Models\PpeInventory;
use App\Models\PpeType;
use App\Models\Role;
use App\Models\User;
use App\Notifications\PpeComplianceDueNotification;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * PPE & Equipment — the old register cases that the PPE workspace suites do
 * not already cover, rewritten against the keyed PPE commands (UUID
 * idempotency_key, expected_version, current staff at the Site): inventory
 * tab counts, catalogue category validation, new stock defaults, return releasing stock, the
 * condemned inspection result, the condemn/dispose guards, the private
 * evidence round trip with its sandbox headers, and compliance reminders.
 * Every other old case maps to a newer test; see
 * docs/health-safety-build/PLAN.md.
 */
class PpeRegisterTest extends PpeCommandTestCase
{
    /** Genuine evidence acceptance needs the guarded physical-commit boundary; every other case stays transactional. */
    private const PHYSICAL_COMMIT_TESTS = ['test_inventory_attachment_upload_download_delete'];

    protected function setUp(): void
    {
        $this->physicalCommits = in_array($this->name(), self::PHYSICAL_COMMIT_TESTS, true);
        parent::setUp();
    }

    private function withRole(string $role): User
    {
        $user = User::factory()->create(['role' => $role, 'approved_at' => now()]);
        if ($r = Role::where('name', $role)->first()) {
            $user->roles()->attach($r);
        }

        return $user;
    }

    private function officer(): User
    {
        return $this->withRole('health_safety_officer');   // hazards.view + hazards.manage
    }

    /* ───────────── Index / read ───────────── */

    public function test_tab_counts_are_correct(): void
    {
        PpeInventory::factory()->count(2)->create(['site_id' => $this->site->id, 'status' => 'available']);
        PpeInventory::factory()->create(['site_id' => $this->site->id, 'status' => 'allocated']);
        PpeInventory::factory()->condemned()->create(['site_id' => $this->site->id]);
        PpeInventory::factory()->inspectionDue()->create(['site_id' => $this->site->id, 'status' => 'available']);
        PpeInventory::factory()->expiring()->create(['site_id' => $this->site->id, 'status' => 'available']);

        $this->actingAs($this->manager)
            ->get('/health-safety/ppe')
            ->assertOk()
            ->assertInertia(fn (Assert $p) => $p
                ->component('health-safety/ppe/index')
                ->where('tabCounts.inv_all', 6)
                ->where('tabCounts.inv_condemned', 1)
                ->where('tabCounts.inv_inspection', 1)
                ->where('tabCounts.inv_expiring', 1)
            );
    }

    /* ───────────── Type CRUD ───────────── */

    public function test_store_type_rejects_unknown_category(): void
    {
        $input = ['idempotency_key' => (string) Str::uuid(), 'name' => 'Bad', 'category' => 'not_a_category'];

        $this->actingAs($this->manager)
            ->postJson('/health-safety/ppe/types', $input)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('category');

        $this->assertDatabaseMissing('ppe_types', ['name' => 'Bad']);
        $this->assertDatabaseMissing('ppe_command_receipts', ['idempotency_key' => $input['idempotency_key']]);
    }

    /* ───────────── Inventory ───────────── */

    public function test_store_inventory_defaults_available(): void
    {
        $type = PpeType::factory()->create();

        $this->actingAs($this->manager)
            ->postJson('/health-safety/ppe/inventory', [
                'idempotency_key' => (string) Str::uuid(),
                'ppe_type_id' => $type->id, 'site_id' => $this->site->id, 'serial_number' => 'SN-1', 'quantity' => 1,
            ])
            ->assertOk()
            ->assertJsonPath('ppe_confirmation.command', 'create_inventory');

        $this->assertDatabaseHas('ppe_inventory', ['serial_number' => 'SN-1', 'status' => 'available', 'created_by' => $this->manager->id]);
    }

    /* ───────────── Allocation ───────────── */

    public function test_return_marks_returned_and_frees_item(): void
    {
        $item = $this->item();
        $alloc = $this->issue($item);
        $this->assertSame('allocated', $item->fresh()->status);

        $this->actingAs($this->manager)
            ->postJson('/health-safety/ppe/allocations/'.$alloc->id.'/return', $this->command($item, ['condition' => 'good']))
            ->assertOk();

        $this->assertNotNull($alloc->fresh()->returned_at);
        $this->assertDatabaseHas('ppe_inventory', ['id' => $item->id, 'status' => 'available']);
    }

    /* ───────────── Inspection ───────────── */

    public function test_inspection_records_and_condemn_result_flips_item(): void
    {
        $item = $this->item();

        $this->actingAs($this->manager)
            ->postJson('/health-safety/ppe/inventory/'.$item->id.'/inspections', $this->command($item, ['result' => 'condemned', 'findings' => 'Cracked shell']))
            ->assertOk();

        $this->assertDatabaseHas('ppe_inspections', ['ppe_inventory_id' => $item->id, 'result' => 'condemned']);
        $this->assertDatabaseHas('ppe_inventory', ['id' => $item->id, 'status' => 'condemned', 'condition' => 'condemned', 'condemned_by' => $this->manager->id]);
    }

    /* ───────────── Condemn / Dispose ───────────── */

    public function test_condemn_requires_reason_and_blocks_when_allocated(): void
    {
        $item = $this->item();
        $this->issue($item);

        // Active allocation → refused, no status change.
        $this->actingAs($this->manager)
            ->postJson('/health-safety/ppe/inventory/'.$item->id.'/condemn', $this->command($item, ['reason' => 'damaged']))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('status');
        $this->assertDatabaseHas('ppe_inventory', ['id' => $item->id, 'status' => 'allocated']);

        // Missing reason → 422.
        $free = $this->item();
        $this->actingAs($this->manager)
            ->postJson('/health-safety/ppe/inventory/'.$free->id.'/condemn', $this->command($free))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('reason');
        $this->assertDatabaseHas('ppe_inventory', ['id' => $free->id, 'status' => 'available']);
    }

    public function test_dispose_blocked_unless_condemned(): void
    {
        $item = $this->item();

        $this->actingAs($this->manager)
            ->postJson('/health-safety/ppe/inventory/'.$item->id.'/dispose', $this->command($item, ['disposal_method' => 'General waste']))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('status');
        $this->assertDatabaseHas('ppe_inventory', ['id' => $item->id, 'status' => 'available', 'disposed_at' => null]);
    }

    public function test_condemn_rejects_already_out_of_service_item(): void
    {
        $item = $this->item();
        $this->service()->condemn($item, $this->manager, $this->command($item, ['reason' => 'expired']));
        $revision = (int) $item->fresh()->source_revision;

        $this->actingAs($this->manager)
            ->postJson('/health-safety/ppe/inventory/'.$item->id.'/condemn', $this->command($item, ['reason' => 'again']))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('status');

        $this->assertSame('expired', $item->fresh()->condemned_reason);
        $this->assertSame($revision, (int) $item->fresh()->source_revision);
    }

    /* ───────────── Evidence (premium upload) ───────────── */

    public function test_inventory_attachment_upload_download_delete(): void
    {
        Storage::fake('private');
        $item = $this->item();

        $upload = $this->actingAs($this->manager)->postJson('/health-safety/ppe/inventory/'.$item->id.'/attachments', [
            ...$this->command($item, ['kind' => 'certificate']),
            'file' => UploadedFile::fake()->createWithContent('cert.pdf', 'Actual certificate bytes'),
        ])->assertOk()->assertJsonPath('ppe_confirmation.command', 'upload_attachment');

        $att = PpeAttachment::findOrFail($upload->json('ppe_confirmation.source_id'));
        $this->assertSame($item->id, (int) $att->ppe_inventory_id);
        // Stored on the PRIVATE disk now — never world-readable under /storage.
        Storage::disk('private')->assertExists($att->path);
        $this->assertSame('private', $att->disk);

        // download — streamed only after the stored digest is re-proved, with the
        // hardened CSP-sandbox header (nosniff + X-Frame-Options come from the edge
        // layer, not the app).
        $download = $this->actingAs($this->manager)->get('/health-safety/ppe/inventory/'.$item->id.'/attachments/'.$att->id.'/download')
            ->assertOk()
            ->assertHeader('Content-Security-Policy', "default-src 'none'; sandbox; frame-ancestors 'none'")
            ->assertHeader('Referrer-Policy', 'no-referrer');
        $this->assertSame('Actual certificate bytes', $download->streamedContent());

        $this->actingAs($this->manager)
            ->deleteJson('/health-safety/ppe/inventory/'.$item->id.'/attachments/'.$att->id, $this->command($item, ['reason' => 'Superseded certificate']))
            ->assertOk()
            ->assertJsonPath('ppe_confirmation.command', 'remove_attachment');
        $this->assertSoftDeleted('ppe_attachments', ['id' => $att->id]);
        Storage::disk('private')->assertMissing($att->path);
    }

    /* ───────────── Compliance reminders (B6) ───────────── */

    public function test_compliance_reminders_notify_worker_and_manager(): void
    {
        Notification::fake();

        $worker = User::factory()->create();
        // Unacknowledged allocation older than the grace window → worker digest.
        PpeAllocation::factory()->create([
            'user_id' => $worker->id, 'acknowledged' => false, 'returned_at' => null, 'allocated_at' => now()->subDays(3),
        ]);
        // Inspection-overdue item → manager digest to hazards.manage holders.
        PpeInventory::factory()->inspectionDue()->create(['status' => 'available']);
        $manager = $this->officer();

        $this->artisan('ppe:compliance-reminders')->assertSuccessful();

        Notification::assertSentTo($worker, PpeComplianceDueNotification::class, fn ($n) => $n->audience === 'worker');
        Notification::assertSentTo($manager, PpeComplianceDueNotification::class, fn ($n) => $n->audience === 'manager');
    }
}
