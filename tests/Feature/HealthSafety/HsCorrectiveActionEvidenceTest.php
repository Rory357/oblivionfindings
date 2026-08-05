<?php

namespace Tests\Feature\HealthSafety;

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Models\HsAttachment;
use App\Models\HsCorrectiveAction;
use App\Models\HsEvent;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use RuntimeException;
use Tests\TestCase;

class HsCorrectiveActionEvidenceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RbacSeeder::class);
    }

    public function test_owner_can_upload_every_allowed_private_evidence_type_with_metadata(): void
    {
        Storage::fake('private');
        [$owner, $event, $action] = $this->actionJourney();

        $files = [
            ['inspection.pdf', 'application/pdf'],
            ['close-up.jpg', 'image/jpeg'],
            ['overview.png', 'image/png'],
            ['repair.webp', 'image/webp'],
            ['contractor-note.doc', 'application/msword'],
            ['completion-pack.docx', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document'],
        ];

        foreach ($files as [$name, $mime]) {
            $this->actingAs($owner)
                ->post($this->storeUrl($event, $action), [
                    'file' => UploadedFile::fake()->create($name, 12, $mime),
                    'description' => '  Completion evidence for '.$name.'  ',
                ])
                ->assertSessionHas('success');
        }

        $this->assertCount(6, $action->attachments);
        foreach ($action->attachments as $attachment) {
            $this->assertSame('private', $attachment->disk);
            $this->assertSame($owner->id, $attachment->uploaded_by);
            $this->assertStringStartsWith(
                "health-safety/corrective-actions/{$action->id}/",
                $attachment->path,
            );
            $this->assertNotNull($attachment->mime_type);
            $this->assertGreaterThan(0, $attachment->size_bytes);
            $this->assertSame(
                'Completion evidence for '.$attachment->original_name,
                $attachment->description,
            );
            Storage::disk('private')->assertExists($attachment->path);
        }
    }

    public function test_scriptable_executable_and_oversized_files_are_rejected(): void
    {
        Storage::fake('private');
        [$owner, $event, $action] = $this->actionJourney();

        $files = [
            UploadedFile::fake()->create('payload.svg', 10, 'image/svg+xml'),
            UploadedFile::fake()->create('payload.html', 10, 'text/html'),
            UploadedFile::fake()->create('payload.exe', 10, 'application/x-msdownload'),
            UploadedFile::fake()->create('too-large.pdf', 10241, 'application/pdf'),
        ];

        foreach ($files as $file) {
            $this->actingAs($owner)
                ->post($this->storeUrl($event, $action), ['file' => $file])
                ->assertSessionHasErrors('file');
        }

        $this->assertSame(0, $action->attachments()->count());
        $this->assertSame(
            [],
            Storage::disk('private')->allFiles(
                "health-safety/corrective-actions/{$action->id}",
            ),
        );
    }

    public function test_owner_and_authorised_hs_staff_can_download_but_unrelated_users_cannot(): void
    {
        Storage::fake('private');
        [$owner, $event, $action, $site] = $this->actionJourney();
        $manager = $this->staffAtSite($site, 'health_safety_officer');
        $unrelated = $this->staffAtSite($site);
        $otherSite = Site::factory()->create(['tenant_id' => 1]);
        $otherSiteManager = $this->staffAtSite($otherSite, 'health_safety_officer');
        $otherTenantSite = Site::factory()->create(['tenant_id' => 2]);
        $otherTenantManager = $this->staffAtSite(
            $otherTenantSite,
            'health_safety_officer',
            2,
        );
        $attachment = $this->attachment($action, $owner, 'completion.pdf');
        Storage::disk('private')->put($attachment->path, '%PDF-1.4 evidence');

        foreach ([$owner, $manager] as $allowed) {
            $this->actingAs($allowed)
                ->get($this->downloadUrl($event, $action, $attachment))
                ->assertOk()
                ->assertHeader('X-Content-Type-Options', 'nosniff');
        }

        $this->actingAs($unrelated)
            ->get($this->downloadUrl($event, $action, $attachment))
            ->assertForbidden();
        $this->actingAs($otherSiteManager)
            ->get($this->downloadUrl($event, $action, $attachment))
            ->assertNotFound();
        $this->actingAs($otherSiteManager)
            ->get("/health-safety/events/{$event->id}")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('detail.corrective_actions.0.evidence.can_upload', false)
                ->has('detail.corrective_actions.0.evidence.attachments', 0)
                ->where('detail.corrective_actions.0.rework.latest_reason', null)
                ->has('detail.corrective_actions.0.history', 0)
            );
        $this->actingAs($otherTenantManager)
            ->get($this->downloadUrl($event, $action, $attachment))
            ->assertNotFound();
    }

    public function test_missing_storage_file_returns_404_without_exposing_the_path(): void
    {
        Storage::fake('private');
        [$owner, $event, $action] = $this->actionJourney();
        $attachment = $this->attachment($action, $owner, 'missing.pdf');

        $this->actingAs($owner)
            ->get($this->downloadUrl($event, $action, $attachment))
            ->assertNotFound()
            ->assertDontSee($attachment->path);
    }

    public function test_owner_can_remove_before_verification_but_not_after_verified_or_closed(): void
    {
        Storage::fake('private');
        [$owner, $event, $action] = $this->actionJourney();
        $attachment = $this->attachment($action, $owner, 'draft.jpg');
        Storage::disk('private')->put($attachment->path, 'image');

        $this->actingAs($owner)
            ->delete($this->deleteUrl($event, $action, $attachment))
            ->assertSessionHas('success');
        Storage::disk('private')->assertMissing($attachment->path);
        $this->assertSoftDeleted('hs_attachments', ['id' => $attachment->id]);

        foreach ([
            HsCorrectiveAction::STATUS_VERIFIED,
            HsCorrectiveAction::STATUS_CLOSED,
        ] as $status) {
            $lockedAction = HsCorrectiveAction::factory()->create([
                'hs_event_id' => $event->id,
                'assigned_to_user_id' => $owner->id,
                'status' => $status,
            ]);
            $lockedAttachment = $this->attachment(
                $lockedAction,
                $owner,
                "{$status}.pdf",
            );
            Storage::disk('private')->put($lockedAttachment->path, 'evidence');

            $this->actingAs($owner)
                ->delete(
                    $this->deleteUrl($event, $lockedAction, $lockedAttachment),
                )
                ->assertSessionHasErrors('evidence');
            Storage::disk('private')->assertExists($lockedAttachment->path);
            $this->assertDatabaseHas('hs_attachments', [
                'id' => $lockedAttachment->id,
                'deleted_at' => null,
            ]);
        }
    }

    public function test_completed_action_cannot_remove_its_last_retained_evidence_file(): void
    {
        Storage::fake('private');
        [$owner, $event, $action, $site] = $this->actionJourney();
        $manager = $this->staffAtSite($site, 'health_safety_officer');
        $action->update([
            'status' => HsCorrectiveAction::STATUS_COMPLETED,
            'completion_notes' => null,
            'completion_evidence_paths' => null,
            'completed_at' => now(),
            'completed_by_user_id' => $owner->id,
        ]);
        $attachment = $this->attachment($action, $owner, 'only-proof.jpg');
        Storage::disk('private')->put($attachment->path, 'image');

        $this->actingAs($owner)
            ->delete($this->deleteUrl($event, $action, $attachment))
            ->assertSessionHasErrors('evidence');
        Storage::disk('private')->assertExists($attachment->path);
        $this->assertDatabaseHas('hs_attachments', [
            'id' => $attachment->id,
            'deleted_at' => null,
        ]);

        $this->actingAs($manager)
            ->get("/health-safety/events/{$event->id}")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where(
                    'detail.corrective_actions.0.evidence.attachments.0.can_remove',
                    false,
                )
            );
    }

    public function test_cross_action_attachment_idor_is_not_found(): void
    {
        Storage::fake('private');
        [$owner, $event, $action] = $this->actionJourney();
        $otherAction = HsCorrectiveAction::factory()->create([
            'hs_event_id' => $event->id,
            'assigned_to_user_id' => $owner->id,
        ]);
        $attachment = $this->attachment($otherAction, $owner, 'other.pdf');
        Storage::disk('private')->put($attachment->path, '%PDF');

        $this->actingAs($owner)
            ->get($this->downloadUrl($event, $action, $attachment))
            ->assertNotFound();
        $this->actingAs($owner)
            ->delete($this->deleteUrl($event, $action, $attachment))
            ->assertNotFound();
    }

    public function test_event_detail_exposes_retained_evidence_without_storage_paths(): void
    {
        Storage::fake('private');
        [$owner, $event, $action, $site] = $this->actionJourney();
        $manager = $this->staffAtSite($site, 'health_safety_officer');
        $attachment = $this->attachment($action, $owner, 'after-photo.jpg');
        Storage::disk('private')->put($attachment->path, 'image');

        $this->actingAs($manager)
            ->get("/health-safety/events/{$event->id}")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('detail.corrective_actions.0.id', $action->id)
                ->where('detail.corrective_actions.0.evidence.can_upload', true)
                ->has('detail.corrective_actions.0.evidence.attachments', 1)
                ->where(
                    'detail.corrective_actions.0.evidence.attachments.0.original_name',
                    'after-photo.jpg',
                )
                ->where(
                    'detail.corrective_actions.0.evidence.attachments.0.download_url',
                    $this->downloadUrl($event, $action, $attachment),
                )
                ->where(
                    'detail.corrective_actions.0.evidence.attachments.0.can_remove',
                    true,
                )
                ->missing(
                    'detail.corrective_actions.0.evidence.attachments.0.path',
                )
            );
    }

    public function test_locked_action_state_blocks_stale_upload_and_removal(): void
    {
        Storage::fake('private');
        [$owner, $event, $uploadAction] = $this->actionJourney();
        $flipUploadStatus = true;
        HsCorrectiveAction::retrieved(function (HsCorrectiveAction $retrieved) use (
            $uploadAction,
            &$flipUploadStatus,
        ): void {
            if ($flipUploadStatus && (int) $retrieved->id === (int) $uploadAction->id) {
                $flipUploadStatus = false;
                DB::table('hs_corrective_actions')
                    ->where('id', $retrieved->id)
                    ->update(['status' => HsCorrectiveAction::STATUS_VERIFIED]);
            }
        });

        $this->actingAs($owner)
            ->post($this->storeUrl($event, $uploadAction), [
                'file' => UploadedFile::fake()->create(
                    'late-upload.pdf',
                    12,
                    'application/pdf',
                ),
            ])
            ->assertSessionHasErrors('evidence');
        $this->assertSame(0, $uploadAction->attachments()->count());
        $this->assertSame(
            [],
            Storage::disk('private')->allFiles(
                "health-safety/corrective-actions/{$uploadAction->id}",
            ),
        );

        $removalAction = HsCorrectiveAction::factory()->create([
            'hs_event_id' => $event->id,
            'organization_id' => 1,
            'assigned_to_user_id' => $owner->id,
        ]);
        $attachment = $this->attachment(
            $removalAction,
            $owner,
            'late-removal.pdf',
        );
        Storage::disk('private')->put($attachment->path, 'evidence');
        $flipRemovalStatus = true;
        HsCorrectiveAction::retrieved(function (HsCorrectiveAction $retrieved) use (
            $removalAction,
            &$flipRemovalStatus,
        ): void {
            if ($flipRemovalStatus && (int) $retrieved->id === (int) $removalAction->id) {
                $flipRemovalStatus = false;
                DB::table('hs_corrective_actions')
                    ->where('id', $retrieved->id)
                    ->update(['status' => HsCorrectiveAction::STATUS_CLOSED]);
            }
        });

        $this->actingAs($owner)
            ->delete($this->deleteUrl($event, $removalAction, $attachment))
            ->assertSessionHasErrors('evidence');
        Storage::disk('private')->assertExists($attachment->path);
        $this->assertDatabaseHas('hs_attachments', [
            'id' => $attachment->id,
            'deleted_at' => null,
        ]);
    }

    public function test_database_failure_deletes_the_newly_stored_file(): void
    {
        Storage::fake('private');
        [$owner, $event, $action] = $this->actionJourney();
        HsAttachment::creating(function (HsAttachment $attachment): void {
            if ($attachment->original_name === 'force-db-failure.pdf') {
                throw new RuntimeException('Forced attachment database failure.');
            }
        });
        $this->withoutExceptionHandling();

        try {
            $this->actingAs($owner)->post($this->storeUrl($event, $action), [
                'file' => UploadedFile::fake()->create(
                    'force-db-failure.pdf',
                    12,
                    'application/pdf',
                ),
            ]);
            $this->fail('The forced attachment failure must escape the controller.');
        } catch (RuntimeException $exception) {
            $this->assertSame(
                'Forced attachment database failure.',
                $exception->getMessage(),
            );
        }

        $this->assertSame(
            [],
            Storage::disk('private')->allFiles(
                "health-safety/corrective-actions/{$action->id}",
            ),
        );
    }

    /**
     * @return array{0: User, 1: HsEvent, 2: HsCorrectiveAction, 3: Site}
     */
    private function actionJourney(): array
    {
        $site = Site::factory()->create(['tenant_id' => 1]);
        $owner = $this->staffAtSite($site);
        $event = HsEvent::factory()->create([
            'organization_id' => 1,
            'site_id' => $site->id,
        ]);
        $action = HsCorrectiveAction::factory()->create([
            'hs_event_id' => $event->id,
            'organization_id' => 1,
            'assigned_to_user_id' => $owner->id,
        ]);

        return [$owner, $event, $action, $site];
    }

    private function staffAtSite(
        Site $site,
        ?string $roleName = null,
        int $organizationId = 1,
    ): User {
        $user = User::factory()->create([
            'organization_id' => $organizationId,
            'approved_at' => now(),
            'role' => $roleName ?? 'support_worker',
        ]);
        if ($roleName && $role = Role::query()->where('name', $roleName)->first()) {
            $user->roles()->attach($role);
        }
        HrEmployeeProfile::factory()->create([
            'tenant_id' => $organizationId,
            'user_id' => $user->id,
            'primary_site_id' => $site->id,
            'secondary_site_ids' => [],
        ]);

        return $user;
    }

    private function attachment(
        HsCorrectiveAction $action,
        User $uploader,
        string $name,
    ): HsAttachment {
        return $action->attachments()->create([
            'uploaded_by' => $uploader->id,
            'original_name' => $name,
            'path' => "health-safety/corrective-actions/{$action->id}/{$name}",
            'disk' => 'private',
            'mime_type' => str_ends_with($name, '.jpg')
                ? 'image/jpeg'
                : 'application/pdf',
            'size_bytes' => 100,
            'description' => 'Evidence',
        ]);
    }

    private function storeUrl(
        HsEvent $event,
        HsCorrectiveAction $action,
    ): string {
        return "/health-safety/events/{$event->id}/corrective-actions/{$action->id}/evidence";
    }

    private function downloadUrl(
        HsEvent $event,
        HsCorrectiveAction $action,
        HsAttachment $attachment,
    ): string {
        return $this->storeUrl($event, $action)."/{$attachment->id}";
    }

    private function deleteUrl(
        HsEvent $event,
        HsCorrectiveAction $action,
        HsAttachment $attachment,
    ): string {
        return $this->downloadUrl($event, $action, $attachment);
    }
}
