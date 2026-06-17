<?php

namespace Tests\Feature\Safeguarding;

use App\Models\Permission;
use App\Models\Role;
use App\Models\SafeguardingConcern;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Safeguarding redesign — Step 7a (W8 evidence + need-to-know G3).
 */
class SafeguardingAttachmentTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RbacSeeder::class);
        Storage::fake('public');
    }

    private function makeSafeguardingUser(array $permissionKeys, bool $withRole = false): User
    {
        $user = User::factory()->create(['approved_at' => now()]);

        if ($withRole) {
            $adminRole = Role::query()->where('name', 'admin')->first();
            if ($adminRole) {
                $user->update(['role' => 'admin']);
                $user->roles()->syncWithoutDetaching([$adminRole->id]);
            }
        }

        foreach ($permissionKeys as $permissionKey) {
            $permission = Permission::query()->firstOrCreate(
                ['key' => $permissionKey],
                ['description' => str_replace('.', ' ', $permissionKey), 'group' => explode('.', $permissionKey)[0]],
            );
            $user->permissionOverrides()->syncWithoutDetaching([$permission->id => ['allowed' => true]]);
        }

        return $user;
    }

    private function attachment(SafeguardingConcern $concern, bool $sensitive, User $uploader)
    {
        return $concern->attachments()->create([
            'uploaded_by' => $uploader->id,
            'disk' => 'public',
            'original_name' => $sensitive ? 'sensitive.jpg' : 'open.jpg',
            'path' => UploadedFile::fake()->image('e.jpg')->store('safeguarding_attachments', 'public'),
            'mime' => 'image/jpeg',
            'size' => 1024,
            'is_sensitive' => $sensitive,
        ]);
    }

    public function test_upload_creates_attachment(): void
    {
        $user = $this->makeSafeguardingUser(['safeguarding.update']);
        $concern = SafeguardingConcern::factory()->create();

        $this->actingAs($user)
            ->post("/safeguarding/{$concern->id}/attachments", [
                'file' => UploadedFile::fake()->create('report.pdf', 120, 'application/pdf'),
                'notes' => 'Door camera log',
                'is_sensitive' => false,
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('safeguarding_attachments', [
            'safeguarding_concern_id' => $concern->id,
            'original_name' => 'report.pdf',
            'notes' => 'Door camera log',
        ]);
        $this->assertSame(1, $concern->attachments()->count());
        Storage::disk('public')->assertExists($concern->attachments()->first()->path);
    }

    public function test_sensitive_evidence_download_gated_by_view_sensitive(): void
    {
        $uploader = $this->makeSafeguardingUser(['safeguarding.update']);
        $concern = SafeguardingConcern::factory()->create();
        $att = $this->attachment($concern, true, $uploader);

        $uncleared = $this->makeSafeguardingUser(['safeguarding.viewAny']);
        $this->actingAs($uncleared)
            ->get("/safeguarding/{$concern->id}/attachments/{$att->id}/download")
            ->assertForbidden();

        $cleared = $this->makeSafeguardingUser(['safeguarding.viewAny', 'safeguarding.viewSensitive']);
        $this->actingAs($cleared)
            ->get("/safeguarding/{$concern->id}/attachments/{$att->id}/download")
            ->assertOk();
    }

    public function test_non_sensitive_download_is_allowed(): void
    {
        $uploader = $this->makeSafeguardingUser(['safeguarding.update']);
        $concern = SafeguardingConcern::factory()->create();
        $att = $this->attachment($concern, false, $uploader);

        $viewer = $this->makeSafeguardingUser(['safeguarding.viewAny']);
        $this->actingAs($viewer)
            ->get("/safeguarding/{$concern->id}/attachments/{$att->id}/download")
            ->assertOk();
    }

    public function test_destroy_removes_attachment(): void
    {
        $user = $this->makeSafeguardingUser(['safeguarding.update']);
        $concern = SafeguardingConcern::factory()->create();
        $att = $this->attachment($concern, false, $user);
        $path = $att->path;

        $this->actingAs($user)
            ->delete("/safeguarding/{$concern->id}/attachments/{$att->id}")
            ->assertRedirect();

        Storage::disk('public')->assertMissing($path);
        $this->assertSoftDeleted('safeguarding_attachments', ['id' => $att->id]);
    }

    public function test_detail_locks_sensitive_attachment_for_uncleared_viewer(): void
    {
        $uploader = $this->makeSafeguardingUser(['safeguarding.update']);
        $concern = SafeguardingConcern::factory()->create(['is_sensitive' => false]);
        $this->attachment($concern, true, $uploader);

        $uncleared = $this->makeSafeguardingUser(['safeguarding.viewAny']);
        $this->actingAs($uncleared)
            ->get("/safeguarding?concern={$concern->id}")
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('detail.attachments.0.locked', true)
                ->where('detail.attachments.0.is_sensitive', true)
            );
    }
}
