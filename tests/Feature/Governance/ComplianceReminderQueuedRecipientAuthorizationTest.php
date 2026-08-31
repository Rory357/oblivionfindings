<?php

namespace Tests\Feature\Governance;

use App\Domain\Governance\Jobs\SendComplianceReminder;
use App\Domain\Governance\Models\ComplianceReminder;
use App\Domain\Governance\Notifications\ComplianceReminderNotification;
use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Models\Permission;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use Tests\Support\GovernanceTestHelpers;
use Tests\TestCase;

class ComplianceReminderQueuedRecipientAuthorizationTest extends TestCase
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

    public function test_queued_delivery_revalidates_permission_policy_and_current_staff_lifecycle(): void
    {
        $central = $this->createAdminUser();
        $currentStaff = $this->adminWithProfile();
        $revoked = $this->createAdminUser();
        $ended = $this->adminWithProfile(['end_date' => today()->subDay()]);
        $inactive = $this->adminWithProfile(['is_active' => false]);
        $future = $this->adminWithProfile(['start_date' => today()->addDay()]);
        $unapproved = $this->createAdminUser(['approved_at' => null]);

        $permission = Permission::query()
            ->where('key', 'governance.compliance.view')
            ->sole();
        $revoked->permissionOverrides()->syncWithoutDetaching([
            $permission->id => ['allowed' => false],
        ]);

        $obligation = $this->createComplianceObligation($central, [
            'obligation_code' => 'QUEUE-AUTH-001',
            'obligation_title' => 'Queued recipient authorization',
            'due_date' => today()->addDays(7),
            'next_due_date' => today()->addDays(7),
        ]);
        $reminder = $this->reminder($obligation->id, [
            $central->id,
            (string) $central->id,
            $currentStaff->id,
            $revoked->id,
            $ended->id,
            $inactive->id,
            $future->id,
            $unapproved->id,
        ]);

        (new SendComplianceReminder($reminder))->handle();

        Notification::assertSentToTimes($central, ComplianceReminderNotification::class, 1);
        Notification::assertSentToTimes($currentStaff, ComplianceReminderNotification::class, 1);
        Notification::assertNotSentTo($revoked, ComplianceReminderNotification::class);
        Notification::assertNotSentTo($ended, ComplianceReminderNotification::class);
        Notification::assertNotSentTo($inactive, ComplianceReminderNotification::class);
        Notification::assertNotSentTo($future, ComplianceReminderNotification::class);
        Notification::assertNotSentTo($unapproved, ComplianceReminderNotification::class);

        Notification::assertSentTo($central, ComplianceReminderNotification::class, function (
            ComplianceReminderNotification $notification,
            array $channels,
        ) use ($central, $obligation): bool {
            return $channels === ['mail', 'database']
                && $notification->toArray($central) === [
                    'type' => 'compliance_reminder',
                    'obligation_id' => $obligation->id,
                    'obligation_title' => 'Queued recipient authorization',
                    'framework' => 'privacy_act',
                    'due_date' => today()->addDays(7)->toDateString(),
                    'is_escalation' => true,
                    'escalation_level' => 2,
                ];
        });
    }

    public function test_malformed_numeric_looking_ids_fail_closed_without_suppressing_valid_recipients(): void
    {
        $owner = $this->createAdminUser();
        $valid = $this->createAdminUser();
        $coercionTrap = $this->createAdminUser();
        $obligation = $this->createComplianceObligation($owner, [
            'obligation_code' => 'QUEUE-AUTH-002',
        ]);
        $reminder = $this->reminder($obligation->id, [
            $valid->id,
            (string) $valid->id,
            '0'.(string) $coercionTrap->id,
            $coercionTrap->id.'.9',
            '1e2',
            '  '.$coercionTrap->id.'  ',
            -1,
            0,
            '999999999999999999999999999999999999',
        ]);

        (new SendComplianceReminder($reminder))->handle();

        Notification::assertSentToTimes($valid, ComplianceReminderNotification::class, 1);
        Notification::assertNotSentTo($coercionTrap, ComplianceReminderNotification::class);
        Notification::assertCount(1);
    }

    public function test_missing_obligation_and_empty_recipient_sets_are_no_ops(): void
    {
        $owner = $this->createAdminUser();
        $obligation = $this->createComplianceObligation($owner, [
            'obligation_code' => 'QUEUE-AUTH-003',
        ]);

        $empty = $this->reminder($obligation->id, []);
        (new SendComplianceReminder($empty))->handle();

        $missing = new ComplianceReminder(['notified_users' => [$owner->id]]);
        $missing->setRelation('obligation', null);
        (new SendComplianceReminder($missing))->handle();

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

    /** @param array<int, mixed> $recipientIds */
    private function reminder(int $obligationId, array $recipientIds): ComplianceReminder
    {
        return ComplianceReminder::create([
            'compliance_obligation_id' => $obligationId,
            'days_before_due' => 7,
            'scheduled_at' => now(),
            'notified_users' => $recipientIds,
            'status' => 'pending',
            'is_escalation' => true,
            'escalation_level' => 2,
        ]);
    }
}
