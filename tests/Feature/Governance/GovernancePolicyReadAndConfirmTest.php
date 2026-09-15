<?php

namespace Tests\Feature\Governance;

use App\Domain\Governance\Models\GovernancePolicy;
use App\Domain\Governance\Models\PolicyAttestation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\GovernanceTestHelpers;
use Tests\TestCase;

/**
 * Policies: "Read and confirm" (audit P0-8), new versions and approval.
 */
class GovernancePolicyReadAndConfirmTest extends TestCase
{
    use GovernanceTestHelpers;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedGovernance();
    }

    private function policy(array $overrides = []): GovernancePolicy
    {
        static $sequence = 0;
        $sequence++;
        $ownerId = User::query()->value('id') ?? User::factory()->create()->id;

        return GovernancePolicy::create(array_merge([
            'owner_id' => $ownerId,
            'created_by' => $ownerId,
            'policy_code' => 'POL-RC'.$sequence,
            'title' => 'Policy '.$sequence,
            'category' => 'governance',
            'content' => 'Policy wording',
            'version_number' => 1,
            'status' => 'approved',
            'requires_attestation' => true,
            'effective_from' => now()->subMonth()->toDateString(),
            'next_review_date' => now()->addYear()->toDateString(),
        ], $overrides));
    }

    private function member(string $name = 'Board member'): User
    {
        $user = $this->createUserWithRole('board_member', ['name' => $name]);
        $this->createBoardMember($user);

        return $user;
    }

    public function test_policies_to_confirm_only_lists_in_effect_policies_that_ask_for_confirmation(): void
    {
        $member = $this->member();
        $inEffect = $this->policy(['title' => 'Conflicts of interest']);
        $this->policy(['title' => 'No confirmation needed', 'requires_attestation' => false]);
        $future = $this->policy(['title' => 'Starts next month', 'effective_from' => now()->addMonth()->toDateString()]);
        $this->policy(['title' => 'Still a draft', 'status' => 'draft']);

        $this->actingAs($member)
            ->get('/governance/policies/attestations')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Governance/Policies/Attestations')
                ->has('toConfirm', 1)
                ->where('toConfirm.0.id', $inEffect->id)
                ->where('toConfirm.0.state', 'to_confirm')
                ->has('upcoming', 1)
                ->where('upcoming.0.id', $future->id)
                ->where('upcoming.0.state', 'not_yet_in_effect')
                ->has('confirmed', 0)
                ->where('summary.to_confirm', 1)
                ->where('summary.upcoming', 1)
                // Members see their own list, not the board-wide counts.
                ->where('toConfirm.0.confirmed_count', null));
    }

    public function test_a_policy_that_comes_into_effect_later_cannot_be_confirmed_yet(): void
    {
        $member = $this->member();
        $future = $this->policy(['effective_from' => now()->addDays(10)->toDateString()]);

        $this->actingAs($member)
            ->from("/governance/policies/{$future->id}")
            ->post("/governance/policies/{$future->id}/attest", ['acknowledged' => true])
            ->assertRedirect("/governance/policies/{$future->id}")
            ->assertSessionHasErrors(['acknowledged' => 'This policy comes into effect on '
                .\App\Domain\Governance\Support\GovernanceLabels::date(now()->addDays(10)->toDateString())
                .'. You can confirm you have read it from then.']);

        $this->assertDatabaseCount('policy_attestations', 0);
    }

    public function test_a_policy_that_does_not_ask_for_confirmation_cannot_be_confirmed(): void
    {
        $member = $this->member();
        $policy = $this->policy(['requires_attestation' => false]);

        $this->actingAs($member)
            ->post("/governance/policies/{$policy->id}/attest", ['acknowledged' => true])
            ->assertSessionHasErrors('acknowledged');

        $this->assertDatabaseCount('policy_attestations', 0);
    }

    public function test_confirming_needs_the_box_ticked_and_records_the_current_version(): void
    {
        $member = $this->member();
        $policy = $this->policy(['version_number' => 3]);

        $this->actingAs($member)
            ->post("/governance/policies/{$policy->id}/attest", [])
            ->assertSessionHasErrors(['acknowledged' => "Tick the box to confirm you've read this version of the policy."]);

        $this->actingAs($member)
            ->post("/governance/policies/{$policy->id}/attest", ['acknowledged' => true])
            ->assertSessionHasNoErrors()
            ->assertSessionHas('success', fn (string $message) => str_starts_with($message, 'You confirmed version 3 on '));

        $this->assertDatabaseHas('policy_attestations', [
            'governance_policy_id' => $policy->id,
            'user_id' => $member->id,
            'acknowledged' => true,
            'policy_version' => 3,
        ]);

        $this->actingAs($member)
            ->get("/governance/policies/{$policy->id}")
            ->assertInertia(fn (Assert $page) => $page
                ->where('confirmation.state', 'confirmed')
                ->where('confirmation.can_confirm', false)
                ->where('confirmation.my_confirmation.version', 3));

        $this->actingAs($member)
            ->get('/governance/policies/attestations')
            ->assertInertia(fn (Assert $page) => $page
                ->has('toConfirm', 0)
                ->has('confirmed', 1));
    }

    public function test_board_counts_only_include_active_board_members_on_the_current_version(): void
    {
        $admin = $this->createAdminUser();
        $first = $this->member('Aroha');
        $second = $this->member('Hemi');
        $former = $this->createUserWithRole('board_member', ['name' => 'Former']);
        $this->createBoardMember($former, ['is_active' => false]);
        $policy = $this->policy(['version_number' => 2]);

        foreach ([$admin, $first, $former] as $user) {
            PolicyAttestation::create([
                'governance_policy_id' => $policy->id,
                'user_id' => $user->id,
                'acknowledged' => true,
                'acknowledged_at' => now()->subDay(),
                'policy_version' => 2,
            ]);
        }
        // A confirmation of an older version doesn't count for version 2.
        PolicyAttestation::create([
            'governance_policy_id' => $policy->id,
            'user_id' => $second->id,
            'acknowledged' => true,
            'acknowledged_at' => now()->subYear(),
            'policy_version' => 1,
        ]);

        $this->actingAs($admin)
            ->get("/governance/policies/{$policy->id}")
            ->assertInertia(fn (Assert $page) => $page
                ->where('confirmation.board_confirmed', 1)
                ->where('confirmation.board_total', 2)
                ->has('confirmations', 2)
                ->where('confirmations.0.name', 'Aroha')
                ->where('confirmations.0.confirmed', true)
                ->where('confirmations.1.name', 'Hemi')
                ->where('confirmations.1.confirmed', false));

        $this->actingAs($admin)
            ->get('/governance/policies')
            ->assertInertia(fn (Assert $page) => $page
                ->where('policies.data.0.confirmation.confirmed', 1)
                ->where('policies.data.0.confirmation.total', 2)
                ->where('summary.waiting_on_members', 1));

        // Ordinary members see the count, never who has or hasn't confirmed.
        $this->actingAs($second)
            ->get("/governance/policies/{$policy->id}")
            ->assertInertia(fn (Assert $page) => $page
                ->where('confirmations', null)
                ->where('confirmation.board_confirmed', 1)
                ->where('confirmation.state', 'to_confirm'));
    }

    public function test_a_confirmation_is_due_again_after_the_policy_frequency(): void
    {
        $member = $this->member();
        $policy = $this->policy(['attestation_frequency' => 'annual']);

        PolicyAttestation::create([
            'governance_policy_id' => $policy->id,
            'user_id' => $member->id,
            'acknowledged' => true,
            'acknowledged_at' => now()->subMonths(13),
            'policy_version' => 1,
        ]);

        $this->actingAs($member)
            ->get('/governance/policies/attestations')
            ->assertInertia(fn (Assert $page) => $page
                ->has('toConfirm', 1)
                ->where('toConfirm.0.state', 'due_again'));

        $this->actingAs($member)
            ->get("/governance/policies/{$policy->id}")
            ->assertInertia(fn (Assert $page) => $page
                ->where('confirmation.state', 'due_again')
                ->where('confirmation.can_confirm', true)
                ->where('confirmation.board_confirmed', 0));

        $this->actingAs($member)
            ->post("/governance/policies/{$policy->id}/attest", ['acknowledged' => true])
            ->assertSessionHasNoErrors();

        $this->actingAs($member)
            ->get("/governance/policies/{$policy->id}")
            ->assertInertia(fn (Assert $page) => $page
                ->where('confirmation.state', 'confirmed')
                ->where(
                    'confirmation.my_confirmation.due_again_on',
                    now(config('app.worker_timezone') ?: 'Pacific/Auckland')->addMonthsNoOverflow(12)->toDateString(),
                ));
    }

    public function test_edit_can_never_make_a_policy_approved(): void
    {
        $admin = $this->createAdminUser();
        $draft = $this->policy(['status' => 'draft']);
        $approved = $this->policy();

        $this->actingAs($admin)
            ->put("/governance/policies/{$draft->id}", ['status' => 'active'])
            ->assertSessionHasErrors('status');
        $this->assertSame('draft', $draft->fresh()->status);

        $this->actingAs($admin)
            ->put("/governance/policies/{$approved->id}", ['status' => 'draft'])
            ->assertSessionHasErrors('status');
        $this->assertSame('approved', $approved->fresh()->status);

        $this->actingAs($admin)
            ->put("/governance/policies/{$approved->id}", ['status' => 'archived'])
            ->assertSessionHasNoErrors();
        $this->assertSame('archived', $approved->fresh()->status);
    }

    public function test_a_new_version_keeps_the_approved_version_in_effect_until_it_is_approved(): void
    {
        $admin = $this->createAdminUser();
        $member = $this->member();
        $current = $this->policy(['content' => 'Version one wording']);

        PolicyAttestation::create([
            'governance_policy_id' => $current->id,
            'user_id' => $member->id,
            'acknowledged' => true,
            'acknowledged_at' => now()->subDay(),
            'policy_version' => 1,
        ]);

        $this->actingAs($admin)
            ->post("/governance/policies/{$current->id}/version", ['content' => 'Version two wording'])
            ->assertSessionHasErrors(['change_summary' => 'Say what changed in this version.']);

        $this->actingAs($admin)
            ->post("/governance/policies/{$current->id}/version", [
                'content' => 'Version two wording',
                'change_summary' => 'Clearer spending limits',
            ])
            ->assertSessionHasNoErrors();

        $next = GovernancePolicy::query()->where('supersedes_policy_id', $current->id)->sole();
        $this->assertSame(2, (int) $next->version_number);
        $this->assertSame('draft', $next->status);
        $this->assertSame($current->policy_code, $next->policy_code);
        $this->assertSame('Clearer spending limits', $next->change_summary);
        $this->assertSame('approved', $current->fresh()->status);

        // Only one draft of the next version at a time.
        $this->actingAs($admin)
            ->post("/governance/policies/{$current->id}/version", [
                'content' => 'Another draft',
                'change_summary' => 'Duplicate',
            ])
            ->assertSessionHasErrors('change_summary');

        $this->actingAs($admin)
            ->post("/governance/policies/{$next->id}/approve")
            ->assertSessionHasNoErrors();

        $this->assertSame('approved', $next->fresh()->status);
        $this->assertSame('superseded', $current->fresh()->status);

        // The member's version 1 confirmation doesn't carry over to version 2.
        $this->actingAs($member)
            ->get("/governance/policies/{$next->id}")
            ->assertInertia(fn (Assert $page) => $page
                ->where('confirmation.state', 'to_confirm')
                ->where('confirmation.board_confirmed', 0));
    }

    public function test_a_policy_due_to_be_confirmed_again_shows_in_my_work(): void
    {
        $member = $this->member('Aroha');
        $annual = $this->policy(['title' => 'Conflicts of interest policy', 'attestation_frequency' => 'annual']);
        $stillCurrent = $this->policy(['title' => 'Privacy policy', 'attestation_frequency' => 'annual']);
        $noRepeat = $this->policy(['title' => 'Code of conduct']);

        // Confirmed 13 months ago — the yearly confirmation is due again.
        PolicyAttestation::create([
            'governance_policy_id' => $annual->id,
            'user_id' => $member->id,
            'acknowledged' => true,
            'acknowledged_at' => now()->subMonths(13),
            'policy_version' => 1,
        ]);
        // Confirmed last month — still current.
        PolicyAttestation::create([
            'governance_policy_id' => $stillCurrent->id,
            'user_id' => $member->id,
            'acknowledged' => true,
            'acknowledged_at' => now()->subMonth(),
            'policy_version' => 1,
        ]);
        // No confirmation frequency — one confirmation lasts for the version.
        PolicyAttestation::create([
            'governance_policy_id' => $noRepeat->id,
            'user_id' => $member->id,
            'acknowledged' => true,
            'acknowledged_at' => now()->subYears(3),
            'policy_version' => 1,
        ]);

        $items = collect($this->actingAs($member)->getJson('/governance/my-work/data?kind=read')->assertOk()->json('items'));
        $policyItems = $items->where('source.type', 'policy')->values();

        $this->assertCount(1, $policyItems);
        $this->assertSame("policy:{$annual->id}:confirm-again", $policyItems[0]['id']);
        $this->assertSame('Conflicts of interest policy', $policyItems[0]['title']);
        $this->assertSame('Read and confirm', $policyItems[0]['required_action']['label']);
        $this->assertSame("/governance/policies/{$annual->id}", $policyItems[0]['required_action']['href']);

        // The same rule drives the policy page.
        $this->actingAs($member)
            ->get("/governance/policies/{$annual->id}")
            ->assertInertia(fn (Assert $page) => $page->where('confirmation.state', 'due_again'));
    }
}
