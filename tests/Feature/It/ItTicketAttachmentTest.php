<?php

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Domain\It\Services\ItWorkAccessService;
use App\Models\ItAttachment;
use App\Models\ItTicket;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

function itAttachmentUser(string $role): User
{
    $user = User::factory()->create(['role' => $role, 'approved_at' => now()]);
    $user->roles()->syncWithoutDetaching([
        Role::query()->where('name', $role)->first()->id,
    ]);

    return $user;
}

beforeEach(function () {
    $this->seed(RbacSeeder::class);
    Storage::fake(ItAttachment::DISK);
    $this->hr = itAttachmentUser('hr');
    $this->worker = itAttachmentUser('support_worker');
    $this->site = Site::factory()->create();
    foreach ([$this->hr, $this->worker] as $user) {
        HrEmployeeProfile::factory()->create([
            'user_id' => $user->id,
            'primary_site_id' => $this->site->id,
            'is_active' => true,
            'start_date' => now()->subMonth()->toDateString(),
            'end_date' => null,
        ]);
    }
});

test('raising a ticket stores evidence on the private disk', function () {
    expect($this->worker->canDo('it.manage'))->toBeFalse();
    expect(app(ItWorkAccessService::class)->approvedSiteIds($this->worker))
        ->toContain($this->site->id);

    $this->actingAs($this->worker)
        ->post('/it/tickets', [
            'title' => 'Cracked tablet screen',
            'category' => 'hardware',
            'priority' => 'normal',
            'attachments' => [UploadedFile::fake()->image('crack.jpg', 800, 600)],
        ])
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    $ticket = ItTicket::query()->firstWhere('title', 'Cracked tablet screen');
    $attachment = $ticket->attachments()->first();

    expect($attachment)->not->toBeNull();
    expect($attachment->original_name)->toBe('crack.jpg');
    expect($attachment->attachable_type)->toBe('it_ticket');
    expect((int) $attachment->uploaded_by)->toBe($this->worker->id);
    Storage::disk(ItAttachment::DISK)->assertExists($attachment->path);

    // The workspace payload carries the download chip.
    $this->actingAs($this->worker)
        ->get("/it/tickets/{$ticket->id}")
        ->assertInertia(fn ($page) => $page
            ->has('ticket.attachments', 1)
            ->where('ticket.attachments.0.name', 'crack.jpg'));
});

test('scriptable uploads are refused by the allowlist', function () {
    $this->actingAs($this->worker)
        ->post('/it/tickets', [
            'title' => 'Sneaky payload',
            'category' => 'other',
            'priority' => 'low',
            'attachments' => [UploadedFile::fake()->create('evil.svg', 10, 'image/svg+xml')],
        ])
        ->assertSessionHasErrors('attachments.0');

    expect(ItTicket::query()->where('title', 'Sneaky payload')->exists())->toBeFalse();
});

test('downloads follow the thread audience, internal evidence stays agent-only', function () {
    $ticket = ItTicket::factory()->create([
        'site_id' => $this->site->id,
        'requester_user_id' => $this->worker->id,
    ]);

    // Requester attaches evidence to a public reply.
    $this->actingAs($this->worker)
        ->post("/it/tickets/{$ticket->id}/comments", [
            'body' => 'Photo attached.',
            'attachments' => [UploadedFile::fake()->image('photo.png')],
        ])
        ->assertRedirect();

    // Agent attaches a file to an INTERNAL note.
    $this->actingAs($this->hr)
        ->post("/it/tickets/{$ticket->id}/comments", [
            'body' => 'Supplier quote — internal.',
            'is_internal' => true,
            'attachments' => [UploadedFile::fake()->create('quote.pdf', 40, 'application/pdf')],
        ])
        ->assertRedirect();

    $publicFile = ItAttachment::query()->firstWhere('original_name', 'photo.png');
    $internalFile = ItAttachment::query()->firstWhere('original_name', 'quote.pdf');

    // Owner: own public evidence streams; internal-note evidence is concealed.
    $this->actingAs($this->worker)->get("/it/attachments/{$publicFile->id}")->assertOk();
    $this->actingAs($this->worker)->get("/it/attachments/{$internalFile->id}")->assertNotFound();

    // Agent: both.
    $this->actingAs($this->hr)->get("/it/attachments/{$publicFile->id}")->assertOk();
    $this->actingAs($this->hr)->get("/it/attachments/{$internalFile->id}")->assertOk();

    // A different requester cannot discover evidence on someone else's ticket.
    $stranger = itAttachmentUser('support_worker');
    $this->actingAs($stranger)->get("/it/attachments/{$publicFile->id}")->assertNotFound();

    // Requester payload: the internal note AND its attachment are absent.
    $this->actingAs($this->worker)
        ->get("/it/tickets/{$ticket->id}")
        ->assertInertia(fn ($page) => $page
            ->has('comments', 1)
            ->has('comments.0.attachments', 1)
            ->where('comments.0.attachments.0.name', 'photo.png'));
});
