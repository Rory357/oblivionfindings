<?php

namespace Tests\Feature\Governance;

use App\Domain\Governance\Jobs\EscalateOverdueActionItems;
use App\Domain\Governance\Models\ActionItem;
use App\Domain\Governance\Models\ActionItemEvidence;
use App\Domain\Governance\Models\Resolution;
use App\Domain\Governance\Notifications\ActionItemRaisedWithBoardNotification;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Tests\Support\GovernanceTestHelpers;
use Tests\TestCase;

/**
 * Audit P0-5: actions that need evidence can be completed by uploading a
 * file — no storage paths — and "Raise with the board" really tells the
 * chair and secretary.
 */
class GovernanceActionEvidenceTest extends TestCase
{
    use GovernanceTestHelpers;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedGovernance();
        Storage::fake('private');
    }

    private function upload(User $user, ActionItem $action, UploadedFile ...$files)
    {
        // Per-request header: a withHeaders() default would leak into later requests.
        return $this->actingAs($user)
            ->post("/governance/actions/{$action->id}/evidence", ['files' => $files], ['Accept' => 'application/json']);
    }

    public function test_the_owner_can_upload_evidence_and_the_page_lists_it_by_name_without_a_storage_path(): void
    {
        $admin = $this->createAdminUser();
        $owner = $this->createUserWithRole('board_member', ['name' => 'Aroha Owner']);
        $this->createBoardMember($owner);
        $action = $this->createActionItem($admin, $owner, ['evidence_required' => true, 'version_number' => 1]);

        $response = $this->upload($owner, $action, UploadedFile::fake()->create('Signed contract.pdf', 120, 'application/pdf'));

        $response->assertOk()
            ->assertJsonPath('evidence.0.original_name', 'Signed contract.pdf')
            ->assertJsonPath('evidence.0.uploaded_by_name', 'Aroha Owner');

        $evidence = ActionItemEvidence::query()->where('action_item_id', $action->id)->sole();
        $this->assertSame('private', $evidence->disk);
        $this->assertSame('application/pdf', $evidence->mime_type);
        $this->assertSame($owner->id, $evidence->uploaded_by);
        $this->assertGreaterThan(0, $evidence->size_bytes);
        Storage::disk('private')->assertExists($evidence->path);
        $this->assertStringNotContainsString($evidence->path, (string) json_encode($response->json(), JSON_UNESCAPED_SLASHES));

        $show = $this->actingAs($owner)->get("/governance/actions/{$action->id}");
        $show->assertOk()->assertInertia(fn ($page) => $page
            ->component('Governance/Actions/Show')
            ->where('action.evidence.0.original_name', 'Signed contract.pdf')
            ->where('action.evidence.0.download_url', "/governance/actions/{$action->id}/evidence/{$evidence->id}/download")
            ->missing('action.evidence_attachments')
            ->missing('action.evidence.0.path')
            ->missing('action.evidence.0.disk'));

        $props = (string) json_encode($show->viewData('page')['props'], JSON_UNESCAPED_SLASHES);
        $this->assertStringNotContainsString($evidence->path, $props);
        $this->assertStringNotContainsString(basename($evidence->path), $props);

        $this->actingAs($owner)
            ->get("/governance/actions/{$action->id}/evidence/{$evidence->id}/download")
            ->assertOk()
            ->assertHeader('Content-Security-Policy', "default-src 'none'; sandbox; frame-ancestors 'none'");
    }

    public function test_uploads_are_refused_for_people_outside_the_actions_audience_or_who_cannot_update_it(): void
    {
        $admin = $this->createAdminUser();
        $action = $this->createActionItem($admin, $admin, ['version_number' => 1]);

        // A board member who can see actions but doesn't own this one.
        $bystander = $this->createUserWithRole('board_member');
        $this->createBoardMember($bystander);
        $this->upload($bystander, $action, UploadedFile::fake()->create('proof.pdf', 10, 'application/pdf'))
            ->assertForbidden();

        // An owner who can't open the action's source (private meeting paper).
        $privateMeeting = $this->createMeeting($admin, ['meeting_type' => 'executive_session']);
        $resolution = $this->createResolution($admin, ['governance_meeting_id' => $privateMeeting->id, 'status' => 'closed', 'outcome' => 'carried']);
        $outsider = $this->createUserWithRole('board_member');
        $this->createBoardMember($outsider);
        $privateAction = $this->createActionItem($admin, $outsider, [
            'source_type' => 'resolution',
            'source_id' => $resolution->id,
            'version_number' => 1,
        ]);
        $this->upload($outsider, $privateAction, UploadedFile::fake()->create('proof.pdf', 10, 'application/pdf'))
            ->assertForbidden();

        $this->assertSame(0, ActionItemEvidence::query()->count());

        // Downloads follow the same audience: another action's file is not found,
        // and someone who can't open the action is refused.
        $owned = $this->upload($admin, $privateAction, UploadedFile::fake()->create('board-only.pdf', 10, 'application/pdf'))->assertOk();
        $evidenceId = $owned->json('evidence.0.id');
        $this->actingAs($outsider)
            ->get("/governance/actions/{$privateAction->id}/evidence/{$evidenceId}/download")
            ->assertForbidden();
        $this->actingAs($admin)
            ->get("/governance/actions/{$action->id}/evidence/{$evidenceId}/download")
            ->assertNotFound();
    }

    public function test_files_that_are_not_documents_or_images_are_rejected_with_a_plain_message(): void
    {
        $admin = $this->createAdminUser();
        $action = $this->createActionItem($admin, $admin, ['version_number' => 1]);

        $this->upload($admin, $action, UploadedFile::fake()->create('page.html', 5, 'text/html'))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['files.0'])
            ->assertJsonFragment(['files.0' => ['Evidence must be a PDF, a Word, Excel or PowerPoint file, an image (JPG, PNG, GIF or WebP), or a CSV or text file. To use an email, save it as a PDF first.']]);

        $this->upload($admin, $action, UploadedFile::fake()->create('huge.pdf', 30000, 'application/pdf'))
            ->assertUnprocessable()
            ->assertJsonFragment(['files.0' => ['Each file must be 20 MB or smaller.']]);

        $this->assertSame(0, ActionItemEvidence::query()->count());
    }

    public function test_completing_an_action_that_needs_evidence_accepts_the_uploaded_files(): void
    {
        $admin = $this->createAdminUser();
        $action = $this->createActionItem($admin, $admin, ['evidence_required' => true, 'version_number' => 1]);
        $other = $this->createActionItem($admin, $admin, ['evidence_required' => true, 'version_number' => 1]);

        // Nothing uploaded yet: still refused, in plain words.
        $this->actingAs($admin)
            ->from("/governance/actions/{$action->id}")
            ->post("/governance/actions/{$action->id}/complete", [
                'completion_notes' => 'Contract signed.',
                'expected_version' => 1,
            ])
            ->assertRedirect("/governance/actions/{$action->id}")
            ->assertSessionHas('error', ActionItem::EVIDENCE_NEEDED_MESSAGE);

        $otherEvidenceId = $this->upload($admin, $other, UploadedFile::fake()->create('other.pdf', 10, 'application/pdf'))->json('evidence.0.id');
        $evidenceId = $this->upload($admin, $action, UploadedFile::fake()->image('signed-page.jpg'))->json('evidence.0.id');

        // Borrowing another action's file is refused and changes nothing.
        $this->actingAs($admin)
            ->from("/governance/actions/{$action->id}")
            ->post("/governance/actions/{$action->id}/complete", [
                'completion_notes' => 'Contract signed.',
                'evidence_ids' => [$evidenceId, $otherEvidenceId],
                'expected_version' => 1,
            ])
            ->assertSessionHas('error');
        $this->assertSame('open', $action->fresh()->status);

        $this->actingAs($admin)
            ->from("/governance/actions/{$action->id}")
            ->post("/governance/actions/{$action->id}/complete", [
                'completion_notes' => 'Contract signed and filed.',
                'evidence_ids' => [$evidenceId],
                'expected_version' => 1,
            ])
            ->assertRedirect()
            ->assertSessionHas('success');

        $fresh = $action->fresh();
        $this->assertSame('complete', $fresh->status);
        $this->assertStringStartsWith('ACT-REC-', (string) $fresh->completion_receipt);

        // Done actions keep their evidence as it was.
        $this->upload($admin, $action, UploadedFile::fake()->create('late.pdf', 10, 'application/pdf'))
            ->assertUnprocessable();
        $this->actingAs($admin)
            ->delete("/governance/actions/{$action->id}/evidence/{$evidenceId}", [], ['Accept' => 'application/json'])
            ->assertUnprocessable();
        $this->assertSame(1, ActionItemEvidence::query()->where('action_item_id', $action->id)->count());
    }

    public function test_only_the_uploader_or_an_action_manager_can_remove_a_file_before_completion(): void
    {
        $admin = $this->createAdminUser();
        $owner = $this->createUserWithRole('board_member');
        $this->createBoardMember($owner);
        $action = $this->createActionItem($admin, $owner, ['version_number' => 1]);

        $adminFileId = $this->upload($admin, $action, UploadedFile::fake()->create('admin.pdf', 10, 'application/pdf'))->json('evidence.0.id');
        $ownerFile = $this->upload($owner, $action, UploadedFile::fake()->create('owner.pdf', 10, 'application/pdf'));
        $ownerFileId = collect($ownerFile->json('evidence'))->firstWhere('original_name', 'owner.pdf')['id'];
        $ownerPath = ActionItemEvidence::query()->findOrFail($ownerFileId)->path;

        $this->actingAs($owner)
            ->delete("/governance/actions/{$action->id}/evidence/{$adminFileId}", [], ['Accept' => 'application/json'])
            ->assertForbidden();

        $this->actingAs($owner)
            ->delete("/governance/actions/{$action->id}/evidence/{$ownerFileId}", [], ['Accept' => 'application/json'])
            ->assertOk();

        $this->assertNull(ActionItemEvidence::query()->find($ownerFileId));
        Storage::disk('private')->assertMissing($ownerPath);
        $this->assertNotNull(ActionItemEvidence::query()->find($adminFileId));
    }

    public function test_raising_an_action_with_the_board_notifies_the_chair_and_secretary(): void
    {
        Notification::fake();

        $admin = $this->createAdminUser(['name' => 'Kiri Admin']);
        $chair = $this->createUserWithRole('board_chair', ['name' => 'Hemi Chair']);
        $this->createBoardMember($chair, ['board_role' => 'chair']);
        $secretary = $this->createUserWithRole('board_secretary', ['name' => 'Mere Secretary']);
        $this->createBoardMember($secretary, ['board_role' => 'secretary']);
        $member = $this->createUserWithRole('board_member');
        $this->createBoardMember($member);

        $action = $this->createActionItem($admin, $admin, [
            'title' => 'Renew the insurance policy',
            'priority' => 'critical',
            'version_number' => 1,
        ]);

        $this->actingAs($admin)
            ->from("/governance/actions/{$action->id}")
            ->post("/governance/actions/{$action->id}/escalate", [
                'escalation_reason' => 'The broker has not replied for a month.',
                'expected_version' => 1,
            ])
            ->assertRedirect("/governance/actions/{$action->id}")
            ->assertSessionHas('success', 'Action raised with the board. Chair and secretary notified.');

        Notification::assertSentTo($chair, ActionItemRaisedWithBoardNotification::class, function ($notification, array $channels) use ($action, $chair) {
            $mail = $notification->toMail($chair);

            return $channels === ['mail', 'database']
                && $notification->actionItem->is($action)
                && str_contains($mail->subject, 'Renew the insurance policy')
                && in_array('Kiri Admin has asked the board to look at this action.', $mail->introLines, true);
        });
        Notification::assertSentTo($secretary, ActionItemRaisedWithBoardNotification::class);
        Notification::assertNotSentTo($member, ActionItemRaisedWithBoardNotification::class);
        Notification::assertNotSentTo($admin, ActionItemRaisedWithBoardNotification::class);

        $fresh = $action->fresh();
        $this->assertSame($admin->id, $fresh->escalated_by);
        $this->assertSame('critical', $fresh->priority, 'Raising an action never lowers its priority.');
        $this->assertFalse($fresh->wasEscalatedAutomatically());
    }

    public function test_the_chair_raising_an_action_only_notifies_the_secretary_and_private_actions_are_not_announced(): void
    {
        Notification::fake();

        $admin = $this->createAdminUser();
        $chair = $this->createUserWithRole('board_chair');
        $this->createBoardMember($chair, ['board_role' => 'chair']);
        $secretary = $this->createUserWithRole('board_secretary');
        $this->createBoardMember($secretary, ['board_role' => 'secretary']);

        $action = $this->createActionItem($admin, $chair, ['version_number' => 1]);

        $this->actingAs($chair)
            ->from("/governance/actions/{$action->id}")
            ->post("/governance/actions/{$action->id}/escalate", [
                'escalation_reason' => 'Needs a board decision on funding.',
                'expected_version' => 1,
            ])
            ->assertSessionHas('success', 'Action raised with the board. The secretary was notified.');

        Notification::assertNotSentTo($chair, ActionItemRaisedWithBoardNotification::class);
        Notification::assertSentTo($secretary, ActionItemRaisedWithBoardNotification::class);

        // An action from a board-only session the secretary can't open.
        Notification::fake();
        $privateMeeting = $this->createMeeting($admin, ['meeting_type' => 'executive_session']);
        $resolution = Resolution::create([
            'title' => 'Confidential matter',
            'exact_motion' => 'That the board resolves a confidential matter',
            'purpose' => 'decision',
            'context' => 'Confidential',
            'options' => [['label' => 'Yes', 'benefits' => 'A', 'drawbacks' => 'B'], ['label' => 'No', 'benefits' => 'C', 'drawbacks' => 'D']],
            'recommendation' => 'Yes',
            'status' => 'closed',
            'outcome' => 'carried',
            'voting_threshold' => 'simple_majority',
            'governance_meeting_id' => $privateMeeting->id,
            'proposed_by' => $admin->id,
            'proposed_at' => now(),
        ]);
        $private = $this->createActionItem($admin, $admin, [
            'source_type' => 'resolution',
            'source_id' => $resolution->id,
            'version_number' => 1,
        ]);

        $access = app(\App\Domain\Governance\Services\GovernanceRecordAccessService::class);
        $this->assertFalse($access->canViewActionItem($secretary, $private));
        $this->assertTrue($access->canViewActionItem($chair, $private));

        $this->actingAs($admin)
            ->from("/governance/actions/{$private->id}")
            ->post("/governance/actions/{$private->id}/escalate", [
                'escalation_reason' => 'Board-only follow-up is stuck.',
                'expected_version' => 1,
            ])
            ->assertSessionHas('success', 'Action raised with the board. The chair was notified.');

        Notification::assertSentTo($chair, ActionItemRaisedWithBoardNotification::class);
        Notification::assertNotSentTo($secretary, ActionItemRaisedWithBoardNotification::class);
    }

    public function test_automatic_escalations_record_no_person_and_say_so(): void
    {
        Notification::fake();

        $admin = $this->createAdminUser();
        $owner = $this->createAdminUser(['name' => 'Tama Owner']);
        $action = $this->createActionItem($admin, $owner, [
            'due_date' => today()->subDays(2)->toDateString(),
            'status' => 'open',
        ]);

        (new EscalateOverdueActionItems)->handle();

        $fresh = $action->fresh();
        $this->assertNotNull($fresh->escalated_at);
        $this->assertNull($fresh->escalated_by);
        $this->assertSame(ActionItem::AUTOMATIC_ESCALATION_REASON, $fresh->escalation_reason);
        $this->assertTrue($fresh->wasEscalatedAutomatically());

        $this->actingAs($admin)
            ->get("/governance/actions/{$action->id}")
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('action.escalated_automatically', true)
                ->where('action.escalated_by', null));
    }
}
