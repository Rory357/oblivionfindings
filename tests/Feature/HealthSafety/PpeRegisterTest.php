<?php

namespace Tests\Feature\HealthSafety;

use App\Models\PpeAllocation;
use App\Models\PpeAttachment;
use App\Models\PpeInspection;
use App\Models\PpeInventory;
use App\Models\PpeType;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use App\Notifications\PpeComplianceDueNotification;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * PPE & Equipment register — gold-standard index payload (tabCounts / hero / detail),
 * every lifecycle endpoint (type CRUD + activate/deactivate, allocate w/ ack-at-issue,
 * acknowledge, return, inspect, condemn/dispose with guards), premium evidence upload,
 * and the hazards.view / hazards.manage authorization matrix.
 */
class PpeRegisterTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RbacSeeder::class);
    }

    private function withRole(string $role): User
    {
        $user = User::factory()->create(['role' => $role, 'approved_at' => now()]);
        if ($r = Role::where('name', $role)->first()) {
            $user->roles()->attach($r);
        }

        return $user;
    }

    private function manager(): User
    {
        return $this->withRole('health_safety_officer');   // hazards.view + hazards.manage
    }

    private function viewer(): User
    {
        return $this->withRole('maintenance_coordinator');  // hazards.view, NOT manage
    }

    private function noPerms(): User
    {
        return $this->withRole('support_worker');           // no hazards.*
    }

    /* ───────────── Index / read ───────────── */

    public function test_index_renders_gold_standard_payload(): void
    {
        $site = Site::factory()->create(['is_active' => true]);
        PpeInventory::factory()->create(['site_id' => $site->id]);

        $this->actingAs($this->manager())
            ->get('/health-safety/ppe')
            ->assertOk()
            ->assertInertia(fn (Assert $p) => $p
                ->component('health-safety/ppe/index')
                ->has('tab')
                ->has('filters')
                ->has('inventory.data')
                ->has('allocations')
                ->has('types')
                ->has('tabCounts')
                ->has('hero.clusters')
                ->has('hero.compliance')
                ->has('sites')
                ->has('staff')
                ->has('allocatable')
                ->where('can.manage', true)
            );
    }

    public function test_tab_counts_are_correct(): void
    {
        $site = Site::factory()->create(['is_active' => true]);
        PpeInventory::factory()->count(2)->create(['site_id' => $site->id, 'status' => 'available']);
        PpeInventory::factory()->create(['site_id' => $site->id, 'status' => 'allocated']);
        PpeInventory::factory()->condemned()->create(['site_id' => $site->id]);
        PpeInventory::factory()->inspectionDue()->create(['site_id' => $site->id, 'status' => 'available']);
        PpeInventory::factory()->expiring()->create(['site_id' => $site->id, 'status' => 'available']);

        $this->actingAs($this->manager())
            ->get('/health-safety/ppe')
            ->assertInertia(fn (Assert $p) => $p
                ->where('tabCounts.inv_all', 6)
                ->where('tabCounts.inv_condemned', 1)
                ->where('tabCounts.inv_inspection', 1)
                ->where('tabCounts.inv_expiring', 1)
            );
    }

    public function test_hero_compliance_flags_rpe_fit_test_due(): void
    {
        $site = Site::factory()->create(['is_active' => true]);
        $type = PpeType::factory()->respiratory()->create();
        $item = PpeInventory::factory()->create(['site_id' => $site->id, 'ppe_type_id' => $type->id, 'status' => 'allocated']);
        PpeAllocation::factory()->create(['ppe_inventory_id' => $item->id, 'fit_test_completed' => false, 'returned_at' => null]);

        $this->actingAs($this->manager())
            ->get('/health-safety/ppe')
            ->assertInertia(fn (Assert $p) => $p->where('hero.compliance.rpe_fit_test_due', 1));
    }

    public function test_detail_is_null_without_params_and_loaded_with_item(): void
    {
        $site = Site::factory()->create(['is_active' => true]);
        $item = PpeInventory::factory()->create(['site_id' => $site->id]);

        $this->actingAs($this->manager())
            ->get('/health-safety/ppe')
            ->assertInertia(fn (Assert $p) => $p->where('detail', null));

        $this->actingAs($this->manager())
            ->get('/health-safety/ppe?item='.$item->id)
            ->assertInertia(fn (Assert $p) => $p
                ->where('detail.kind', 'item')
                ->where('detail.item.id', $item->id)
                ->has('detail.item.history')
            );
    }

    public function test_catalogue_lists_inactive_types(): void
    {
        PpeType::factory()->create(['is_active' => true]);
        PpeType::factory()->inactive()->create();

        $this->actingAs($this->manager())
            ->get('/health-safety/ppe?tab=types')
            ->assertInertia(fn (Assert $p) => $p->has('types', 2));
    }

    /* ───────────── Type CRUD ───────────── */

    public function test_store_type_creates_and_validates(): void
    {
        $this->actingAs($this->manager())
            ->post('/health-safety/ppe/types', ['name' => 'Hard hat', 'category' => 'head'])
            ->assertRedirect();
        $this->assertDatabaseHas('ppe_types', ['name' => 'Hard hat', 'category' => 'head']);

        $this->actingAs($this->manager())
            ->post('/health-safety/ppe/types', ['name' => 'Bad', 'category' => 'not_a_category'])
            ->assertSessionHasErrors('category');
    }

    public function test_update_and_toggle_type(): void
    {
        $type = PpeType::factory()->create(['name' => 'Old', 'is_active' => true]);

        $this->actingAs($this->manager())
            ->put('/health-safety/ppe/types/'.$type->id, ['name' => 'New', 'category' => $type->category]);
        $this->assertDatabaseHas('ppe_types', ['id' => $type->id, 'name' => 'New']);

        $this->actingAs($this->manager())->patch('/health-safety/ppe/types/'.$type->id.'/deactivate');
        $this->assertDatabaseHas('ppe_types', ['id' => $type->id, 'is_active' => false]);

        $this->actingAs($this->manager())->patch('/health-safety/ppe/types/'.$type->id.'/activate');
        $this->assertDatabaseHas('ppe_types', ['id' => $type->id, 'is_active' => true]);
    }

    /* ───────────── Inventory ───────────── */

    public function test_store_inventory_defaults_available(): void
    {
        $site = Site::factory()->create(['is_active' => true]);
        $type = PpeType::factory()->create();
        $manager = $this->manager();

        $this->actingAs($manager)
            ->post('/health-safety/ppe/inventory', [
                'ppe_type_id' => $type->id, 'site_id' => $site->id, 'serial_number' => 'SN-1', 'quantity' => 1,
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('ppe_inventory', ['serial_number' => 'SN-1', 'status' => 'available', 'created_by' => $manager->id]);
    }

    /* ───────────── Allocation ───────────── */

    public function test_allocate_sets_status_and_honours_ack_at_issue(): void
    {
        $site = Site::factory()->create(['is_active' => true]);
        $item = PpeInventory::factory()->create(['site_id' => $site->id, 'status' => 'available']);
        $worker = User::factory()->create();
        $manager = $this->manager();

        $this->actingAs($manager)
            ->post('/health-safety/ppe/inventory/'.$item->id.'/allocate', ['user_id' => $worker->id, 'acknowledged' => true]);

        $this->assertDatabaseHas('ppe_inventory', ['id' => $item->id, 'status' => 'allocated']);
        $this->assertDatabaseHas('ppe_allocations', [
            'ppe_inventory_id' => $item->id, 'user_id' => $worker->id, 'acknowledged' => true, 'acknowledged_by' => $manager->id,
        ]);
    }

    public function test_acknowledge_endpoint_sets_columns(): void
    {
        $alloc = PpeAllocation::factory()->create(['acknowledged' => false]);
        $manager = $this->manager();

        $this->actingAs($manager)->post('/health-safety/ppe/allocations/'.$alloc->id.'/acknowledge');

        $this->assertDatabaseHas('ppe_allocations', ['id' => $alloc->id, 'acknowledged' => true, 'acknowledged_by' => $manager->id]);
    }

    public function test_return_marks_returned_and_frees_item(): void
    {
        $item = PpeInventory::factory()->create(['status' => 'allocated']);
        $alloc = PpeAllocation::factory()->create(['ppe_inventory_id' => $item->id, 'returned_at' => null]);

        $this->actingAs($this->manager())
            ->post('/health-safety/ppe/allocations/'.$alloc->id.'/return', ['condition' => 'good']);

        $this->assertNotNull($alloc->fresh()->returned_at);
        $this->assertDatabaseHas('ppe_inventory', ['id' => $item->id, 'status' => 'available']);
    }

    /* ───────────── Inspection ───────────── */

    public function test_inspection_records_and_condemn_result_flips_item(): void
    {
        $item = PpeInventory::factory()->create(['status' => 'available']);

        $this->actingAs($this->manager())
            ->post('/health-safety/ppe/inventory/'.$item->id.'/inspections', ['result' => 'condemned', 'findings' => 'Cracked shell']);

        $this->assertDatabaseHas('ppe_inspections', ['ppe_inventory_id' => $item->id, 'result' => 'condemned']);
        $this->assertDatabaseHas('ppe_inventory', ['id' => $item->id, 'status' => 'condemned', 'condition' => 'condemned']);
    }

    /* ───────────── Condemn / Dispose ───────────── */

    public function test_condemn_requires_reason_and_blocks_when_allocated(): void
    {
        $item = PpeInventory::factory()->create(['status' => 'allocated']);
        PpeAllocation::factory()->create(['ppe_inventory_id' => $item->id, 'returned_at' => null]);

        // Active allocation → rejected with a flash error, no status change.
        $this->actingAs($this->manager())
            ->post('/health-safety/ppe/inventory/'.$item->id.'/condemn', ['reason' => 'damaged'])
            ->assertSessionHas('error');
        $this->assertDatabaseHas('ppe_inventory', ['id' => $item->id, 'status' => 'allocated']);

        // Missing reason → 422.
        $free = PpeInventory::factory()->create(['status' => 'available']);
        $this->actingAs($this->manager())
            ->post('/health-safety/ppe/inventory/'.$free->id.'/condemn', [])
            ->assertSessionHasErrors('reason');
    }

    public function test_condemn_then_dispose(): void
    {
        $item = PpeInventory::factory()->create(['status' => 'available']);
        $manager = $this->manager();

        $this->actingAs($manager)->post('/health-safety/ppe/inventory/'.$item->id.'/condemn', ['reason' => 'expired']);
        $this->assertDatabaseHas('ppe_inventory', ['id' => $item->id, 'status' => 'condemned', 'condemned_by' => $manager->id]);

        $this->actingAs($manager)->post('/health-safety/ppe/inventory/'.$item->id.'/dispose', ['disposal_method' => 'General waste']);
        $this->assertDatabaseHas('ppe_inventory', ['id' => $item->id, 'status' => 'disposed', 'disposal_method' => 'General waste']);
    }

    public function test_dispose_blocked_unless_condemned(): void
    {
        $item = PpeInventory::factory()->create(['status' => 'available']);

        $this->actingAs($this->manager())
            ->post('/health-safety/ppe/inventory/'.$item->id.'/dispose', ['disposal_method' => 'General waste'])
            ->assertSessionHas('error');
        $this->assertDatabaseHas('ppe_inventory', ['id' => $item->id, 'status' => 'available']);
    }

    public function test_condemn_rejects_already_out_of_service_item(): void
    {
        $item = PpeInventory::factory()->condemned()->create();

        $this->actingAs($this->manager())
            ->post('/health-safety/ppe/inventory/'.$item->id.'/condemn', ['reason' => 'again'])
            ->assertSessionHas('error');
    }

    public function test_return_does_not_revive_a_condemned_item(): void
    {
        // Item condemned at an inspection while still issued → dangling active allocation.
        $item = PpeInventory::factory()->create(['status' => 'condemned', 'condition' => 'condemned']);
        $alloc = PpeAllocation::factory()->create(['ppe_inventory_id' => $item->id, 'returned_at' => null]);

        $this->actingAs($this->manager())
            ->post('/health-safety/ppe/allocations/'.$alloc->id.'/return', ['condition' => 'good']);

        $this->assertNotNull($alloc->fresh()->returned_at);
        // Returning must NOT resurrect the condemned item.
        $this->assertDatabaseHas('ppe_inventory', ['id' => $item->id, 'status' => 'condemned']);
    }

    /* ───────────── Evidence (premium upload) ───────────── */

    public function test_inventory_attachment_upload_download_delete(): void
    {
        Storage::fake('private');
        $item = PpeInventory::factory()->create();
        $manager = $this->manager();

        $this->actingAs($manager)->post('/health-safety/ppe/inventory/'.$item->id.'/attachments', [
            'file' => UploadedFile::fake()->create('cert.pdf', 40, 'application/pdf'), 'kind' => 'certificate',
        ])->assertRedirect();

        $att = PpeAttachment::where('ppe_inventory_id', $item->id)->firstOrFail();
        // Stored on the PRIVATE disk now — never world-readable under /storage.
        Storage::disk('private')->assertExists($att->path);
        $this->assertSame('private', $att->disk);

        // download — streamed from the private disk with the hardened headers
        // (nosniff + CSP sandbox) from ServesPrivateAttachments.
        $this->actingAs($manager)->get('/health-safety/ppe/inventory/'.$item->id.'/attachments/'.$att->id.'/download')
            ->assertOk()
            ->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertHeader('Content-Security-Policy', "default-src 'none'; sandbox; frame-ancestors 'none'");

        $this->actingAs($manager)->delete('/health-safety/ppe/inventory/'.$item->id.'/attachments/'.$att->id)->assertRedirect();
        $this->assertSoftDeleted('ppe_attachments', ['id' => $att->id]);
        Storage::disk('private')->assertMissing($att->path);
    }

    public function test_attachment_download_guards_ownership(): void
    {
        Storage::fake('private');
        $a = PpeInventory::factory()->create();
        $b = PpeInventory::factory()->create();
        $manager = $this->manager();

        $this->actingAs($manager)->post('/health-safety/ppe/inventory/'.$a->id.'/attachments', [
            'file' => UploadedFile::fake()->create('x.pdf', 10, 'application/pdf'),
        ]);
        $att = PpeAttachment::where('ppe_inventory_id', $a->id)->firstOrFail();

        // Same attachment id but wrong parent → 404 (IDOR guard).
        $this->actingAs($manager)->get('/health-safety/ppe/inventory/'.$b->id.'/attachments/'.$att->id.'/download')->assertNotFound();
    }

    /* ───────────── Authorization matrix ───────────── */

    public function test_view_only_user_can_read_but_not_mutate(): void
    {
        $site = Site::factory()->create(['is_active' => true]);
        $item = PpeInventory::factory()->create(['site_id' => $site->id]);

        $this->actingAs($this->viewer())->get('/health-safety/ppe')->assertOk();

        // Mutations require hazards.manage → 403.
        $this->actingAs($this->viewer())->post('/health-safety/ppe/types', ['name' => 'X', 'category' => 'head'])->assertForbidden();
        $this->actingAs($this->viewer())->post('/health-safety/ppe/inventory/'.$item->id.'/condemn', ['reason' => 'x'])->assertForbidden();
    }

    public function test_no_permission_user_cannot_view(): void
    {
        $this->actingAs($this->noPerms())->get('/health-safety/ppe')->assertForbidden();
    }

    /* ───────────── Worker self-acknowledge (My Day, B3) ───────────── */

    public function test_worker_can_self_acknowledge_own_allocation(): void
    {
        // support_worker has ZERO hazards.* perms but may acknowledge their OWN PPE.
        $worker = $this->noPerms();
        $alloc = PpeAllocation::factory()->create(['user_id' => $worker->id, 'acknowledged' => false, 'returned_at' => null]);

        $this->actingAs($worker)
            ->post('/health-safety/ppe/allocations/'.$alloc->id.'/acknowledge-own')
            ->assertRedirect();

        $this->assertDatabaseHas('ppe_allocations', ['id' => $alloc->id, 'acknowledged' => true, 'acknowledged_by' => $worker->id]);
    }

    public function test_worker_cannot_self_acknowledge_others_allocation(): void
    {
        $worker = $this->noPerms();
        $other = User::factory()->create();
        $alloc = PpeAllocation::factory()->create(['user_id' => $other->id, 'acknowledged' => false, 'returned_at' => null]);

        $this->actingAs($worker)
            ->post('/health-safety/ppe/allocations/'.$alloc->id.'/acknowledge-own')
            ->assertForbidden();

        $this->assertDatabaseHas('ppe_allocations', ['id' => $alloc->id, 'acknowledged' => false]);
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
        $manager = $this->manager();

        $this->artisan('ppe:compliance-reminders')->assertSuccessful();

        Notification::assertSentTo($worker, PpeComplianceDueNotification::class, fn ($n) => $n->audience === 'worker');
        Notification::assertSentTo($manager, PpeComplianceDueNotification::class, fn ($n) => $n->audience === 'manager');
    }
}
