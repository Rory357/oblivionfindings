<?php

namespace Tests\Feature\Governance;

use App\Domain\Governance\Jobs\EscalateOverdueActionItems;
use App\Domain\Governance\Models\ActionItem;
use App\Domain\Governance\Notifications\ActionItemEscalatedNotification;
use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Models\Permission;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use Tests\Support\GovernanceTestHelpers;
use Tests\TestCase;

class ActionItemEscalationRecipientAuthorizationTest extends TestCase
{
    use GovernanceTestHelpers;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-08-31 10:00:00');
        $this->seedGovernance();
        Notification::fake();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_escalation_revalidates_recipient_authority_and_lifecycle_without_losing_state_progress(): void
    {
        $creator = $this->createAdminUser();
        $central = $this->createAdminUser();
        $currentStaff = $this->adminWithProfile();
        $revoked = $this->createAdminUser();
        $unapproved = $this->createAdminUser(['approved_at' => null]);
        $ended = $this->adminWithProfile(['end_date' => today()->subDay()]);
        $inactive = $this->adminWithProfile(['is_active' => false]);
        $future = $this->adminWithProfile(['start_date' => today()->addDay()]);
        $archived = $this->adminWithProfile();
        $archived->hrEmployeeProfile()->delete();

        $permission = Permission::query()->where('key', 'governance.actions.view')->sole();
        $revoked->permissionOverrides()->syncWithoutDetaching([
            $permission->id => ['allowed' => false],
        ]);

        $actions = collect([
            'central' => $this->overdueAction($creator, $central, 'ACT-AUTH-CENTRAL', 'low'),
            'current' => $this->overdueAction($creator, $currentStaff, 'ACT-AUTH-CURRENT'),
            'revoked' => $this->overdueAction($creator, $revoked, 'ACT-AUTH-REVOKED'),
            'unapproved' => $this->overdueAction($creator, $unapproved, 'ACT-AUTH-UNAPPROVED'),
            'ended' => $this->overdueAction($creator, $ended, 'ACT-AUTH-ENDED'),
            'inactive' => $this->overdueAction($creator, $inactive, 'ACT-AUTH-INACTIVE'),
            'future' => $this->overdueAction($creator, $future, 'ACT-AUTH-FUTURE'),
            'archived' => $this->overdueAction($creator, $archived, 'ACT-AUTH-ARCHIVED'),
        ]);

        (new EscalateOverdueActionItems)->handle();

        Notification::assertSentToTimes($central, ActionItemEscalatedNotification::class, 1);
        Notification::assertSentToTimes($currentStaff, ActionItemEscalatedNotification::class, 1);
        foreach ([$revoked, $unapproved, $ended, $inactive, $future, $archived] as $excluded) {
            Notification::assertNotSentTo($excluded, ActionItemEscalatedNotification::class);
        }
        Notification::assertCount(2);

        Notification::assertSentTo($central, ActionItemEscalatedNotification::class, function (
            ActionItemEscalatedNotification $notification,
            array $channels,
        ) use ($central, $actions): bool {
            return $channels === ['mail', 'database']
                && $notification->toArray($central) === [
                    'type' => 'action_item_escalated',
                    'action_id' => $actions['central']->id,
                    'reference' => 'ACT-AUTH-CENTRAL',
                ];
        });

        foreach ($actions as $key => $action) {
            $action->refresh();
            expect($action->escalated_at)->not->toBeNull()
                ->and($action->escalated_by)->toBe($action->assigned_to)
                ->and($action->escalation_reason)->toBe('Automatically escalated due to overdue status')
                ->and($action->priority)->toBe($key === 'central' ? 'medium' : 'high');
        }
    }

    public function test_non_overdue_non_open_and_already_escalated_items_remain_outside_the_sweep(): void
    {
        $creator = $this->createAdminUser();
        $recipient = $this->createAdminUser();
        $future = $this->createActionItem($creator, $recipient, [
            'action_reference' => 'ACT-AUTH-FUTURE-DUE',
            'due_date' => today()->addDay(),
        ]);
        $blocked = $this->createActionItem($creator, $recipient, [
            'action_reference' => 'ACT-AUTH-BLOCKED',
            'due_date' => today()->subDay(),
            'status' => 'blocked',
        ]);
        $alreadyEscalated = $this->createActionItem($creator, $recipient, [
            'action_reference' => 'ACT-AUTH-ALREADY',
            'due_date' => today()->subDay(),
            'escalated_at' => now()->subHour(),
            'escalated_by' => $recipient->id,
        ]);

        (new EscalateOverdueActionItems)->handle();

        expect($future->fresh()->escalated_at)->toBeNull()
            ->and($blocked->fresh()->escalated_at)->toBeNull()
            ->and($alreadyEscalated->fresh()->escalated_at?->equalTo(now()->subHour()))->toBeTrue();
        Notification::assertNothingSent();
    }

    /** @param array<string, mixed> $overrides */
    private function adminWithProfile(array $overrides = []): User
    {
        $user = $this->createAdminUser();

        HrEmployeeProfile::factory()->create(array_merge([
            'user_id' => $user->id,
            'is_active' => true,
            'start_date' => today()->subYear(),
            'end_date' => null,
            'created_by' => $user->id,
            'updated_by' => $user->id,
        ], $overrides));

        return $user;
    }

    private function overdueAction(User $creator, User $assignedTo, string $reference, string $priority = 'medium'): ActionItem
    {
        return $this->createActionItem($creator, $assignedTo, [
            'action_reference' => $reference,
            'due_date' => today()->subDay(),
            'status' => 'open',
            'priority' => $priority,
        ]);
    }
}
