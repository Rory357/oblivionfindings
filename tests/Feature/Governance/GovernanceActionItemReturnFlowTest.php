<?php

namespace Tests\Feature\Governance;

use App\Domain\Governance\Models\ActionItem;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\Support\GovernanceTestHelpers;
use Tests\TestCase;

/**
 * Contextual action completion (Decisions & actions hub): an action opened
 * from a meeting paper returns the member there after a successful update,
 * without weakening the version, evidence and terminal-state rules.
 */
class GovernanceActionItemReturnFlowTest extends TestCase
{
    use GovernanceTestHelpers;
    use RefreshDatabase;

    private const MEETING_RETURN = '/governance/meetings/12?tab=resolutions&paper=3';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedGovernance();
    }

    private function action(User $admin, array $overrides = []): ActionItem
    {
        return $this->createActionItem($admin, $admin, array_merge([
            'status' => 'open',
            'version_number' => 1,
        ], $overrides));
    }

    public function test_show_offers_only_a_safe_governance_return_path(): void
    {
        $admin = $this->createAdminUser();
        $action = $this->action($admin);

        $this->actingAs($admin)
            ->get("/governance/actions/{$action->id}?return=".urlencode(self::MEETING_RETURN))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Governance/Actions/Show')
                ->where('return_to', self::MEETING_RETURN));

        foreach (['https://evil.example/governance/meetings/1', '//evil.example/governance/x', '/dashboard', '/governance/../admin'] as $unsafe) {
            $this->actingAs($admin)
                ->get("/governance/actions/{$action->id}?return=".urlencode($unsafe))
                ->assertOk()
                ->assertInertia(fn ($page) => $page->where('return_to', null));
        }
    }

    public function test_successful_completion_returns_to_the_meeting_it_was_opened_from(): void
    {
        $admin = $this->createAdminUser();
        $action = $this->action($admin);

        $this->actingAs($admin)
            ->from("/governance/actions/{$action->id}")
            ->post("/governance/actions/{$action->id}/complete", [
                'completion_notes' => 'Contract issued and countersigned.',
                'expected_version' => 1,
                'return_to' => self::MEETING_RETURN,
            ])
            ->assertRedirect(self::MEETING_RETURN)
            ->assertSessionHas('success');

        $this->assertSame('complete', $action->fresh()->status);
    }

    public function test_successful_progress_update_returns_to_the_valid_path(): void
    {
        $admin = $this->createAdminUser();
        $action = $this->action($admin);

        $this->actingAs($admin)
            ->from("/governance/actions/{$action->id}")
            ->post("/governance/actions/{$action->id}/progress", [
                'progress_pct' => 40,
                'progress_notes' => 'Draft circulated.',
                'expected_version' => 1,
                'return_to' => self::MEETING_RETURN,
            ])
            ->assertRedirect(self::MEETING_RETURN);

        $this->assertSame(40, $action->fresh()->progress_pct);
    }

    public function test_external_or_malformed_return_targets_are_ignored(): void
    {
        $admin = $this->createAdminUser();
        $action = $this->action($admin);
        $showUrl = "/governance/actions/{$action->id}";

        $unsafeTargets = [
            'https://evil.example/governance/meetings/1',
            '//evil.example/governance/meetings/1',
            'javascript:alert(1)',
            '/dashboard',
            '/governance/../settings',
            '/governance/%2e%2e/settings',
            '/governance//evil.example',
            "/governance/meetings/1\r\nLocation: https://evil.example",
            '\\\\evil.example\\governance',
        ];

        foreach ($unsafeTargets as $index => $target) {
            $version = (int) $action->fresh()->version_number;

            $response = $this->actingAs($admin)
                ->from($showUrl)
                ->post("{$showUrl}/progress", [
                    'progress_pct' => 5 + $index,
                    'expected_version' => $version,
                    'return_to' => $target,
                ]);

            $response->assertRedirect($showUrl);
            $this->assertStringNotContainsString('evil.example', (string) $response->headers->get('Location'));
            $this->assertSame($version + 1, (int) $action->fresh()->version_number);
        }
    }

    public function test_stale_version_is_still_rejected_and_never_redirects_to_the_return_path(): void
    {
        $admin = $this->createAdminUser();
        $action = $this->action($admin, ['version_number' => 3]);
        $showUrl = "/governance/actions/{$action->id}";

        $this->actingAs($admin)
            ->from($showUrl)
            ->post("{$showUrl}/complete", [
                'completion_notes' => 'Stale browser tab completion.',
                'expected_version' => 2,
                'return_to' => self::MEETING_RETURN,
            ])
            ->assertStatus(409);

        // An Inertia visit gets the conflict in place, on the action.
        $this->actingAs($admin)
            ->from($showUrl)
            ->withHeaders(['X-Inertia' => 'true'])
            ->post("{$showUrl}/progress", [
                'progress_pct' => 90,
                'expected_version' => 2,
                'return_to' => self::MEETING_RETURN,
            ])
            ->assertRedirect($showUrl)
            ->assertSessionHas('error');

        $fresh = $action->fresh();
        $this->assertSame('open', $fresh->status);
        $this->assertSame(3, (int) $fresh->version_number);
        $this->assertNotSame(90, (int) $fresh->progress_pct);
    }

    public function test_completed_action_cannot_be_reopened_by_a_later_progress_update(): void
    {
        $admin = $this->createAdminUser();
        $action = $this->action($admin);
        $action->markComplete($admin->id, 'Signed off at the meeting.', null, 1);
        $completed = $action->fresh();
        $showUrl = "/governance/actions/{$action->id}";

        $this->actingAs($admin)
            ->from($showUrl)
            ->post("{$showUrl}/progress", [
                'progress_pct' => 20,
                'progress_notes' => 'Late progress from an old tab.',
                'expected_version' => (int) $completed->version_number,
                'return_to' => self::MEETING_RETURN,
            ])
            ->assertRedirect($showUrl)
            ->assertSessionHas('error');

        $after = $action->fresh();
        $this->assertSame('complete', $after->status);
        $this->assertSame(100, (int) $after->progress_pct);
        $this->assertSame($completed->completion_receipt, $after->completion_receipt);
        $this->assertSame((int) $completed->version_number, (int) $after->version_number);
    }

    public function test_canonical_evidence_rules_hold_with_a_return_path(): void
    {
        Storage::fake('local');
        Storage::fake('public');

        $admin = $this->createAdminUser();
        $action = $this->action($admin, ['evidence_required' => true]);
        $showUrl = "/governance/actions/{$action->id}";

        // Missing evidence: back to the action, not to the meeting.
        $this->actingAs($admin)
            ->from($showUrl)
            ->post("{$showUrl}/complete", [
                'completion_notes' => 'Done without evidence.',
                'evidence_files' => [],
                'expected_version' => 1,
                'return_to' => self::MEETING_RETURN,
            ])
            ->assertRedirect($showUrl)
            ->assertSessionHas('error');

        // A path that was never uploaded to managed storage is rejected.
        $this->actingAs($admin)
            ->from($showUrl)
            ->post("{$showUrl}/complete", [
                'completion_notes' => 'Done with invented evidence.',
                'evidence_files' => ['governance/evidence/never-uploaded.pdf'],
                'expected_version' => 1,
                'return_to' => self::MEETING_RETURN,
            ])
            ->assertRedirect($showUrl)
            ->assertSessionHas('error');

        // Evidence already attached to another action cannot be borrowed.
        Storage::disk('local')->put('governance/evidence/shared.pdf', 'signed');
        $this->action($admin, [
            'status' => 'complete',
            'evidence_attachments' => ['governance/evidence/shared.pdf'],
        ]);

        $this->actingAs($admin)
            ->from($showUrl)
            ->post("{$showUrl}/complete", [
                'completion_notes' => 'Done with borrowed evidence.',
                'evidence_files' => ['governance/evidence/shared.pdf'],
                'expected_version' => 1,
                'return_to' => self::MEETING_RETURN,
            ])
            ->assertRedirect($showUrl)
            ->assertSessionHas('error');

        $this->assertNotSame('complete', $action->fresh()->status);

        // Real managed evidence completes and returns the member to the meeting.
        Storage::disk('local')->put('governance/evidence/own-certificate.pdf', 'certificate');

        $this->actingAs($admin)
            ->from($showUrl)
            ->post("{$showUrl}/complete", [
                'completion_notes' => 'Commissioned and certified.',
                'evidence_files' => ['governance/evidence/own-certificate.pdf'],
                'expected_version' => 1,
                'return_to' => self::MEETING_RETURN,
            ])
            ->assertRedirect(self::MEETING_RETURN);

        $this->assertSame('complete', $action->fresh()->status);
    }

    public function test_index_filters_reflect_the_query_including_overdue(): void
    {
        $admin = $this->createAdminUser();
        $this->action($admin, ['title' => 'Late insurance renewal', 'due_date' => now()->subDays(3)->toDateString()]);
        $this->action($admin, ['title' => 'Future policy review', 'due_date' => now()->addDays(10)->toDateString()]);

        $this->actingAs($admin)
            ->get('/governance/actions?status=overdue')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Governance/Actions/Index')
                ->where('filters.status', 'overdue')
                ->has('items.data', 1)
                ->where('items.data.0.title', 'Late insurance renewal')
                ->where('summary.overdue', 1));

        // The legacy boolean link shows the same pill state.
        $this->actingAs($admin)
            ->get('/governance/actions?overdue=1')
            ->assertInertia(fn ($page) => $page
                ->where('filters.status', 'overdue')
                ->has('items.data', 1));

        $this->actingAs($admin)
            ->get('/governance/actions?filter=my_work&search=policy')
            ->assertInertia(fn ($page) => $page
                ->where('filters.assigned_to_me', true)
                ->where('filters.search', 'policy')
                ->has('items.data', 1)
                ->where('items.data.0.title', 'Future policy review'));
    }
}
