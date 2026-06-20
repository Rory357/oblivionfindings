<?php

namespace Tests\Feature\Privacy;

use App\Models\DataSubjectRequest;
use App\Models\PrivacyAttachment;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class PrivacyAttachmentTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;      // privacy.processRequests (+ view)

    protected User $auditor;    // privacy.viewRequests only

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RbacSeeder::class);
        $this->admin = $this->userWithRole('admin');
        $this->auditor = $this->userWithRole('auditor');
    }

    private function userWithRole(string $role): User
    {
        $user = User::factory()->create(['role' => $role, 'approved_at' => now()]);
        $user->roles()->attach(Role::where('name', $role)->first());

        return $user;
    }

    private function dsr(): DataSubjectRequest
    {
        return DataSubjectRequest::factory()->create();
    }

    public function test_upload_stores_attachment_against_the_request(): void
    {
        Storage::fake('public');
        $dsr = $this->dsr();

        $this->actingAs($this->admin)
            ->post("/privacy/attachments?attachable_type=request&attachable_id={$dsr->id}", [
                'file' => UploadedFile::fake()->create('identity.pdf', 100, 'application/pdf'),
                'notes' => 'Driver licence',
                'is_sensitive' => '1',
            ])
            ->assertRedirect();

        $this->assertDatabaseCount('privacy_attachments', 1);
        $attachment = PrivacyAttachment::first();
        $this->assertSame(DataSubjectRequest::class, $attachment->attachable_type);
        $this->assertEquals($dsr->id, $attachment->attachable_id);
        $this->assertTrue($attachment->is_sensitive);
        Storage::disk('public')->assertExists($attachment->path);
    }

    public function test_upload_forbidden_without_domain_write_permission(): void
    {
        Storage::fake('public');
        $dsr = $this->dsr();

        // The auditor can view requests but cannot process them.
        $this->actingAs($this->auditor)
            ->post("/privacy/attachments?attachable_type=request&attachable_id={$dsr->id}", [
                'file' => UploadedFile::fake()->create('x.pdf', 10, 'application/pdf'),
            ])
            ->assertForbidden();

        $this->assertDatabaseCount('privacy_attachments', 0);
    }

    public function test_upload_rejects_attachable_type_outside_the_allow_list(): void
    {
        Storage::fake('public');

        $this->actingAs($this->admin)
            ->post('/privacy/attachments?attachable_type=client&attachable_id=1', [
                'file' => UploadedFile::fake()->create('x.pdf', 10, 'application/pdf'),
            ])
            ->assertSessionHasErrors('attachable_type');

        $this->assertDatabaseCount('privacy_attachments', 0);
    }

    public function test_sensitive_download_requires_write_permission(): void
    {
        Storage::fake('public');
        $dsr = $this->dsr();
        $attachment = $dsr->attachments()->create([
            'uploaded_by' => $this->admin->id,
            'disk' => 'public',
            'original_name' => 'sensitive.pdf',
            'path' => UploadedFile::fake()->create('sensitive.pdf', 10)->store('privacy_attachments', 'public'),
            'mime' => 'application/pdf',
            'size' => 10,
            'is_sensitive' => true,
        ]);

        // View-only user is blocked from a need-to-know file.
        $this->actingAs($this->auditor)
            ->get("/privacy/attachments/{$attachment->id}/download")
            ->assertForbidden();

        // Write-permission user can download it.
        $this->actingAs($this->admin)
            ->get("/privacy/attachments/{$attachment->id}/download")
            ->assertOk();
    }

    public function test_destroy_removes_the_attachment_and_file(): void
    {
        Storage::fake('public');
        $dsr = $this->dsr();
        $path = UploadedFile::fake()->create('doc.pdf', 10)->store('privacy_attachments', 'public');
        $attachment = $dsr->attachments()->create([
            'uploaded_by' => $this->admin->id,
            'disk' => 'public',
            'original_name' => 'doc.pdf',
            'path' => $path,
            'mime' => 'application/pdf',
            'size' => 10,
            'is_sensitive' => false,
        ]);

        $this->actingAs($this->admin)
            ->delete("/privacy/attachments/{$attachment->id}")
            ->assertRedirect();

        $this->assertSoftDeleted('privacy_attachments', ['id' => $attachment->id]);
        Storage::disk('public')->assertMissing($path);
    }
}
