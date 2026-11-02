<?php

namespace Tests\Feature\HealthSafety;

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Models\HsClosureException;
use App\Models\HsCorrectiveAction;
use App\Models\HsEvent;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use App\Services\HealthSafety\HsCorrectiveActionService;
use App\Services\HealthSafety\HsEventClosureService;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Tests\TestCase;

/**
 * G3 (boards approved by Stephan, 1 Nov 2026):
 *  - Q11: a check that says an action didn't work blocks closing until a revised action replacing it is done and
 *    checked as working. It is a hard requirement: no close-anyway category covers it.
 *  - Decision 3: a safety review needs an owner before it can close (hard).
 */
class HsRevisedActionClosureTest extends TestCase
{
    use RefreshDatabase;

    private Site $site;

    private User $officer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RbacSeeder::class);
        $this->site = Site::factory()->create(['is_active' => true]);
        $this->officer = $this->roleUser('health_safety_officer');
    }

    public function test_a_failed_check_is_a_hard_blocker_that_close_anyway_cannot_cover(): void
    {
        $event = $this->cleanEvent();
        $failed = $this->action($event, 'CA-FAIL', HsCorrectiveAction::STATUS_VERIFIED, false);

        $requirement = $this->requirement($event, 'revised_actions');
        $this->assertFalse($requirement['complete']);
        $this->assertSame('hard', $requirement['classification']);
        $this->assertStringContainsString('CA-FAIL', $requirement['label']);
        foreach (HsClosureException::CATEGORY_SCOPES as $category => $keys) {
            $this->assertNotContains('revised_actions', $keys, "{$category} must never cover a failed check.");
        }

        $this->actingAs($this->officer)
            ->post("/health-safety/events/{$event->id}/close", ['closure_summary' => 'Trying to close past a failed check.'])
            ->assertSessionHas('error');
        $this->assertSame(HsEvent::STATUS_OPEN, $event->fresh()->status);
        $this->assertTrue($failed->fresh()->checkedIneffective());
    }

    public function test_the_block_clears_only_when_a_revised_action_is_checked_as_working(): void
    {
        $event = $this->cleanEvent();
        $failed = $this->action($event, 'CA-FAIL', HsCorrectiveAction::STATUS_VERIFIED, false);

        $revised = app(HsCorrectiveActionService::class)->createStandalone($event, [
            'title' => 'Replace the lounge rug with a non-slip rug',
            'assigned_to_user_id' => $this->officer->id,
            'due_date' => now()->addWeek()->toDateString(),
            'priority' => HsCorrectiveAction::PRIORITY_MEDIUM,
            'replaces_action_id' => $failed->id,
        ], $this->officer);
        $this->assertSame($failed->id, $revised->replaces_action_id);
        $this->assertTrue($failed->fresh()->revisions->contains($revised));
        $this->assertDatabaseHas('audit_logs', ['action' => 'healthSafety.correctiveAction.created', 'auditable_id' => $revised->id]);

        // Added but not done and checked: still blocked.
        $this->assertFalse($this->requirement($event, 'revised_actions')['complete']);

        // The revised action's own check also said it didn't work: still blocked.
        $revised->forceFill(['status' => HsCorrectiveAction::STATUS_VERIFIED, 'effectiveness_confirmed' => false, 'verified_at' => now()])->save();
        $this->assertFalse($this->requirement($event, 'revised_actions')['complete']);

        // A second revision, checked as working, resolves the whole chain.
        $second = $this->action($event, 'CA-FIX', HsCorrectiveAction::STATUS_VERIFIED, true, ['replaces_action_id' => $revised->id]);
        $this->assertTrue($this->requirement($event, 'revised_actions')['complete']);
        $this->assertTrue($second->checkedEffective());
    }

    public function test_a_revised_action_must_replace_a_failed_action_on_the_same_review(): void
    {
        $event = $this->cleanEvent();
        $other = $this->cleanEvent();
        $worked = $this->action($event, 'CA-OK', HsCorrectiveAction::STATUS_VERIFIED, true);
        $elsewhere = $this->action($other, 'CA-ELSEWHERE', HsCorrectiveAction::STATUS_VERIFIED, false);
        $notChecked = $this->action($event, 'CA-OPEN', HsCorrectiveAction::STATUS_COMPLETED, null);

        foreach ([$worked, $elsewhere, $notChecked] as $target) {
            try {
                app(HsCorrectiveActionService::class)->createStandalone($event, [
                    'title' => 'Revised action',
                    'assigned_to_user_id' => $this->officer->id,
                    'due_date' => now()->addWeek()->toDateString(),
                    'priority' => HsCorrectiveAction::PRIORITY_MEDIUM,
                    'replaces_action_id' => $target->id,
                ], $this->officer);
                $this->fail("{$target->reference_number} must not be replaceable.");
            } catch (InvalidArgumentException $e) {
                $this->assertStringContainsString('whose check said it didn\'t work', $e->getMessage());
            }
        }
        $this->assertSame(0, HsCorrectiveAction::query()->whereNotNull('replaces_action_id')->count());
    }

    public function test_actions_that_were_checked_as_working_never_block(): void
    {
        $event = $this->cleanEvent();
        $this->action($event, 'CA-OK', HsCorrectiveAction::STATUS_CLOSED, true);

        $this->assertTrue($this->requirement($event, 'revised_actions')['complete']);
    }

    public function test_a_review_without_an_owner_cannot_close(): void
    {
        $event = $this->cleanEvent(['owner_user_id' => null]);

        $requirement = $this->requirement($event, 'has_owner');
        $this->assertFalse($requirement['complete']);
        $this->assertSame('hard', $requirement['classification']);
        foreach (HsClosureException::CATEGORY_SCOPES as $keys) {
            $this->assertNotContains('has_owner', $keys);
        }

        $this->actingAs($this->officer)
            ->post("/health-safety/events/{$event->id}/close", ['closure_summary' => 'No owner yet.'])
            ->assertSessionHas('error');
        $this->assertSame(HsEvent::STATUS_OPEN, $event->fresh()->status);

        $event->forceFill(['owner_user_id' => $this->officer->id])->save();
        $this->assertTrue($this->requirement($event->fresh(), 'has_owner')['complete']);
    }

    /** @return array{key: string, complete: bool, label: string, classification: string} */
    private function requirement(HsEvent $event, string $key): array
    {
        $requirement = collect(app(HsEventClosureService::class)->readiness($event->fresh())->requirements)->firstWhere('key', $key);
        $this->assertNotNull($requirement, "Readiness has no {$key} requirement.");

        return $requirement;
    }

    private function cleanEvent(array $attributes = []): HsEvent
    {
        return HsEvent::factory()->worksafeNotNotifiable($this->officer)->create([
            'site_id' => $this->site->id,
            'owner_user_id' => $this->officer->id,
            'handover_status' => HsEvent::HANDOVER_NOT_REQUIRED,
            'investigation_required' => false,
            ...$attributes,
        ]);
    }

    private function action(HsEvent $event, string $reference, string $status, ?bool $effective, array $extra = []): HsCorrectiveAction
    {
        $creator = User::factory()->create();
        $checked = in_array($status, [HsCorrectiveAction::STATUS_VERIFIED, HsCorrectiveAction::STATUS_CLOSED], true);

        return HsCorrectiveAction::factory()->create([
            'hs_event_id' => $event->id,
            'reference_number' => $reference,
            'status' => $status,
            'priority' => HsCorrectiveAction::PRIORITY_MEDIUM,
            'due_date' => now()->addWeek()->toDateString(),
            'assigned_to_user_id' => $creator->id,
            'created_by' => $creator->id,
            'completed_at' => in_array($status, [HsCorrectiveAction::STATUS_COMPLETED, HsCorrectiveAction::STATUS_VERIFIED, HsCorrectiveAction::STATUS_CLOSED], true) ? now()->subDay() : null,
            'verified_at' => $checked ? now() : null,
            'effectiveness_confirmed' => $effective,
            'closed_at' => $status === HsCorrectiveAction::STATUS_CLOSED ? now() : null,
            ...$extra,
        ]);
    }

    private function roleUser(string $roleName): User
    {
        $user = User::factory()->create(['role' => $roleName, 'approved_at' => now()]);
        $user->roles()->syncWithoutDetaching([Role::query()->where('name', $roleName)->firstOrFail()->id]);
        HrEmployeeProfile::factory()->create([
            'user_id' => $user->id,
            'primary_site_id' => $this->site->id,
            'secondary_site_ids' => [],
            'position_role' => $roleName,
        ]);

        return $user->fresh();
    }
}
