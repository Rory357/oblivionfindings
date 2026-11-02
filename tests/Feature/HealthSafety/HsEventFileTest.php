<?php

namespace Tests\Feature\HealthSafety;

use App\Http\Middleware\HandleInertiaRequests;
use App\Models\HsEvent;
use App\Models\HsEventFile;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use LogicException;

/**
 * G3 board M5d (approved by Stephan 1 Nov 2026; decision 22): photos, videos and documents on a safety review's step.
 * Only the allow-listed types and sizes are taken; only people who manage H&S and read the review add or hide them;
 * opening needs the review's normal read and is logged; a file is hidden with a reason, never deleted.
 */
class HsEventFileTest extends RestraintEventCommandTestCase
{
    private const MANAGER = ['hazards.view', 'hazards.manage'];

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(HandleInertiaRequests::class);
        Storage::fake('private');
    }

    private function review(array $attributes = []): HsEvent
    {
        return HsEvent::withoutEvents(fn () => HsEvent::factory()->create([
            'site_id' => $this->site->id, 'event_category' => HsEvent::CATEGORY_HAZARD, 'client_id' => null,
            'status' => HsEvent::STATUS_INVESTIGATING, 'handover_status' => HsEvent::HANDOVER_NOT_REQUIRED, ...$attributes,
        ]));
    }

    public function test_a_manager_adds_a_photo_to_a_step_and_it_shows_on_the_page_and_in_history(): void
    {
        $manager = $this->staff(self::MANAGER);
        $event = $this->review();

        $this->actingAs($manager)->postJson("/health-safety/events/{$event->id}/files", [
            'file' => UploadedFile::fake()->image('rug-edge-1.jpg'), 'step' => 'investigated',
            'description' => 'The rug edge lifting at the lounge doorway.',
        ])->assertCreated();

        $file = HsEventFile::query()->sole();
        $this->assertSame(['investigated', 'photo'], [$file->step, $file->kind]);
        Storage::disk('private')->assertExists($file->path);
        $this->assertDatabaseHas('audit_logs', ['action' => 'healthSafety.event.fileAdded', 'auditable_id' => $event->id]);

        $this->actingAs($manager)->get("/health-safety/events/{$event->id}")->assertOk()
            ->assertInertia(fn ($page) => $page->where('detail.files.0.id', $file->id)->where('detail.files.0.step', 'investigated')
                ->where('detail.can.add_files', true));
        $what = collect($this->actingAs($manager)->getJson("/health-safety/events/{$event->id}/activity")->json('entries'))->pluck('what');
        $this->assertContains('added a photo “rug-edge-1.jpg”', $what->all());
    }

    public function test_only_allowed_types_and_sizes_are_taken(): void
    {
        $manager = $this->staff(self::MANAGER);
        $event = $this->review();
        $url = "/health-safety/events/{$event->id}/files";

        $this->actingAs($manager)->postJson($url, ['file' => UploadedFile::fake()->create('notes.exe', 10), 'step' => 'assigned'])->assertUnprocessable();
        $this->actingAs($manager)->postJson($url, ['file' => UploadedFile::fake()->create('scan.pdf', 21000, 'application/pdf'), 'step' => 'assigned'])
            ->assertUnprocessable()->assertJsonValidationErrors(['file']);
        $this->actingAs($manager)->postJson($url, ['file' => UploadedFile::fake()->create('walk.webm', 2000, 'video/webm'), 'step' => 'assigned', 'duration_seconds' => 190])
            ->assertUnprocessable();
        $this->assertSame(0, HsEventFile::query()->count());
    }

    public function test_people_who_dont_manage_hs_cannot_add_and_closed_reviews_take_none(): void
    {
        $event = $this->review();
        $this->actingAs($this->staff(['hazards.view']))->postJson("/health-safety/events/{$event->id}/files", [
            'file' => UploadedFile::fake()->image('x.jpg'), 'step' => 'assigned',
        ])->assertForbidden();

        $closed = $this->review(['status' => HsEvent::STATUS_CLOSED, 'closed_at' => now()]);
        $this->actingAs($this->staff(self::MANAGER))->postJson("/health-safety/events/{$closed->id}/files", [
            'file' => UploadedFile::fake()->image('x.jpg'), 'step' => 'closed',
        ])->assertUnprocessable();
        $this->assertSame(0, HsEventFile::query()->count());
    }

    public function test_opening_is_logged_and_hiding_keeps_the_file_with_its_reason(): void
    {
        $manager = $this->staff(self::MANAGER);
        $event = $this->review();
        $this->actingAs($manager)->postJson("/health-safety/events/{$event->id}/files", ['file' => UploadedFile::fake()->image('a.jpg'), 'step' => 'assigned']);
        $file = HsEventFile::query()->sole();

        $this->actingAs($this->staff(['hazards.view']))->get("/health-safety/events/{$event->id}/files/{$file->id}")->assertOk();
        $this->assertDatabaseHas('audit_logs', ['action' => 'healthSafety.event.fileOpened', 'auditable_id' => $event->id]);

        $this->actingAs($manager)->post("/health-safety/events/{$event->id}/files/{$file->id}/hide", ['reason' => 'Shows another resident by mistake.'])
            ->assertSessionHas('success');
        $this->assertNotNull($file->fresh()->hidden_at);
        Storage::disk('private')->assertExists($file->path);
        $this->actingAs($manager)->get("/health-safety/events/{$event->id}/files/{$file->id}")->assertNotFound();

        $this->expectException(LogicException::class);
        $file->fresh()->delete();
    }

    public function test_someone_at_another_site_cannot_open_a_file(): void
    {
        $event = $this->review();
        $this->actingAs($this->staff(self::MANAGER))->postJson("/health-safety/events/{$event->id}/files", ['file' => UploadedFile::fake()->image('a.jpg'), 'step' => 'assigned']);
        $file = HsEventFile::query()->sole();
        $elsewhere = $this->staff(self::MANAGER, \App\Models\Site::factory()->create(['is_active' => true]));

        $this->actingAs($elsewhere)->get("/health-safety/events/{$event->id}/files/{$file->id}")->assertNotFound();
    }
}
