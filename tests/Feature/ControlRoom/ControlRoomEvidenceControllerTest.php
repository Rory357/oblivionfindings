<?php

namespace Tests\Feature\ControlRoom;

use App\Models\ControlRoom\EvidenceItem;
use App\Models\ControlRoom\EvidencePack;
use App\Models\ControlRoomAlert;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ControlRoomEvidenceControllerTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected ControlRoomAlert $alert;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(\Database\Seeders\RbacSeeder::class);

        $this->admin = User::factory()->create(['role' => 'admin', 'approved_at' => now()]);
        $this->admin->roles()->attach(Role::where('name', 'admin')->first());

        $this->alert = ControlRoomAlert::factory()->open()->create();
    }

    public function test_index_requires_manage_permission(): void
    {
        $stranger = User::factory()->create(['approved_at' => now()]);

        $this->actingAs($stranger)
            ->getJson("/control-room/alerts/{$this->alert->id}/evidence")
            ->assertForbidden();
    }

    public function test_index_returns_packs(): void
    {
        EvidencePack::create([
            'alert_id' => $this->alert->id,
            'title' => 'Initial pack',
            'status' => 'collecting',
            'item_count' => 0,
            'created_by_user_id' => $this->admin->id,
        ]);

        $this->actingAs($this->admin)
            ->getJson("/control-room/alerts/{$this->alert->id}/evidence")
            ->assertOk()
            ->assertJsonCount(1, 'packs');
    }

    public function test_store_pack_creates_collecting_pack(): void
    {
        $this->actingAs($this->admin)
            ->post("/control-room/alerts/{$this->alert->id}/evidence", [
                'title' => 'Investigation Pack',
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('control_room_evidence_packs', [
            'alert_id' => $this->alert->id,
            'title' => 'Investigation Pack',
            'status' => 'collecting',
        ]);
    }

    public function test_store_pack_validates_title(): void
    {
        $this->actingAs($this->admin)
            ->post("/control-room/alerts/{$this->alert->id}/evidence", [])
            ->assertSessionHasErrors('title');
    }

    public function test_store_note_item_appends_to_pack(): void
    {
        $pack = EvidencePack::create([
            'alert_id' => $this->alert->id,
            'title' => 'Pack',
            'status' => 'collecting',
            'item_count' => 0,
            'created_by_user_id' => $this->admin->id,
        ]);

        $this->actingAs($this->admin)
            ->post("/control-room/evidence/{$pack->id}/items", [
                'item_type' => 'note',
                'content' => 'A handwritten observation typed into the system',
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('control_room_evidence_items', [
            'evidence_pack_id' => $pack->id,
            'type' => 'note',
        ]);
        $this->assertSame(1, $pack->fresh()->item_count);
    }

    public function test_workspace_payload_carries_the_note_text(): void
    {
        // Regression: note items serialized only type/title ("Note"), so the
        // text was unreadable in the workspace right after adding it.
        $pack = EvidencePack::create([
            'alert_id' => $this->alert->id,
            'title' => 'Pack',
            'status' => 'collecting',
            'item_count' => 0,
            'created_by_user_id' => $this->admin->id,
        ]);

        $this->actingAs($this->admin)
            ->post("/control-room/evidence/{$pack->id}/items", [
                'item_type' => 'note',
                'content' => 'Call log: reached on the landline, all well.',
            ])
            ->assertRedirect();

        $detail = app(\App\Services\ControlRoom\AlertWorkspaceService::class)
            ->build($this->admin, $this->alert->id);

        $item = $detail['evidence_packs'][0]['items'][0];
        $this->assertSame('note', $item['type']);
        $this->assertSame('Call log: reached on the landline, all well.', $item['description']);
    }

    public function test_store_item_blocked_on_completed_pack(): void
    {
        $pack = EvidencePack::create([
            'alert_id' => $this->alert->id,
            'title' => 'Pack',
            'status' => 'complete',
            'item_count' => 0,
            'created_by_user_id' => $this->admin->id,
        ]);

        $this->actingAs($this->admin)
            ->post("/control-room/evidence/{$pack->id}/items", [
                'item_type' => 'note',
                'content' => 'Should be rejected',
            ])
            ->assertSessionHasErrors('pack');
    }

    public function test_complete_pack_transitions_status(): void
    {
        $pack = EvidencePack::create([
            'alert_id' => $this->alert->id,
            'title' => 'Pack',
            'status' => 'collecting',
            'item_count' => 0,
            'created_by_user_id' => $this->admin->id,
        ]);

        $this->actingAs($this->admin)
            ->post("/control-room/evidence/{$pack->id}/complete")
            ->assertRedirect();

        $this->assertSame('complete', $pack->fresh()->status);
    }

    public function test_complete_pack_blocked_when_not_collecting(): void
    {
        $pack = EvidencePack::create([
            'alert_id' => $this->alert->id,
            'title' => 'Pack',
            'status' => 'complete',
            'item_count' => 0,
            'created_by_user_id' => $this->admin->id,
        ]);

        $this->actingAs($this->admin)
            ->post("/control-room/evidence/{$pack->id}/complete")
            ->assertSessionHasErrors('pack');
    }

    public function test_uploaded_item_can_be_downloaded(): void
    {
        \Illuminate\Support\Facades\Storage::fake('local');

        $pack = EvidencePack::create([
            'alert_id' => $this->alert->id,
            'title' => 'Pack',
            'status' => 'collecting',
            'item_count' => 0,
            'created_by_user_id' => $this->admin->id,
        ]);

        $this->actingAs($this->admin)
            ->post("/control-room/evidence/{$pack->id}/items", [
                'file' => \Illuminate\Http\UploadedFile::fake()->image('door-photo.png'),
            ])
            ->assertRedirect();

        $item = EvidenceItem::query()->where('evidence_pack_id', $pack->id)->firstOrFail();

        $this->actingAs($this->admin)
            ->get("/control-room/evidence/items/{$item->id}/download")
            ->assertOk()
            ->assertDownload('door-photo.png');
    }

    public function test_download_requires_manage_permission_and_a_stored_file(): void
    {
        $pack = EvidencePack::create([
            'alert_id' => $this->alert->id,
            'title' => 'Pack',
            'status' => 'collecting',
            'item_count' => 0,
            'created_by_user_id' => $this->admin->id,
        ]);

        $note = EvidenceItem::create([
            'evidence_pack_id' => $pack->id,
            'type' => 'note',
            'title' => 'Note',
            'description' => 'Body only — no file',
            'captured_at' => now(),
            'captured_by_user_id' => $this->admin->id,
        ]);

        // A note has no stored file to download.
        $this->actingAs($this->admin)
            ->get("/control-room/evidence/items/{$note->id}/download")
            ->assertNotFound();

        $stranger = User::factory()->create(['approved_at' => now()]);
        $this->actingAs($stranger)
            ->get("/control-room/evidence/items/{$note->id}/download")
            ->assertForbidden();
    }

    public function test_workspace_payload_exposes_note_text_and_file_download_url(): void
    {
        $pack = EvidencePack::create([
            'alert_id' => $this->alert->id,
            'title' => 'Pack',
            'status' => 'collecting',
            'item_count' => 2,
            'created_by_user_id' => $this->admin->id,
        ]);

        $note = EvidenceItem::create([
            'evidence_pack_id' => $pack->id,
            'type' => 'note',
            'title' => 'Note',
            'description' => 'Neighbour statement: client seen at 8:30am.',
            'captured_at' => now(),
            'captured_by_user_id' => $this->admin->id,
        ]);

        $file = EvidenceItem::create([
            'evidence_pack_id' => $pack->id,
            'type' => 'photo',
            'title' => 'door-photo.png',
            'storage_path' => 'evidence/x/door-photo.png',
            'mime_type' => 'image/png',
            'captured_at' => now(),
            'captured_by_user_id' => $this->admin->id,
        ]);

        $detail = app(\App\Services\ControlRoom\AlertWorkspaceService::class)
            ->build($this->admin, $this->alert->id);

        $items = collect($detail['evidence_packs'][0]['items']);

        $noteRow = $items->firstWhere('id', $note->id);
        $this->assertSame('Neighbour statement: client seen at 8:30am.', $noteRow['description']);
        $this->assertNull($noteRow['download_url']);

        $fileRow = $items->firstWhere('id', $file->id);
        $this->assertSame("/control-room/evidence/items/{$file->id}/download", $fileRow['download_url']);
    }

    public function test_destroy_item_removes_item_from_collecting_pack(): void
    {
        $pack = EvidencePack::create([
            'alert_id' => $this->alert->id,
            'title' => 'Pack',
            'status' => 'collecting',
            'item_count' => 1,
            'created_by_user_id' => $this->admin->id,
        ]);

        $item = EvidenceItem::create([
            'evidence_pack_id' => $pack->id,
            'type' => 'note',
            'title' => 'Note',
            'description' => 'Body',
            'captured_at' => now(),
            'captured_by_user_id' => $this->admin->id,
        ]);

        $this->actingAs($this->admin)
            ->delete("/control-room/evidence/items/{$item->id}")
            ->assertRedirect();

        $this->assertDatabaseMissing('control_room_evidence_items', ['id' => $item->id]);
    }
}
