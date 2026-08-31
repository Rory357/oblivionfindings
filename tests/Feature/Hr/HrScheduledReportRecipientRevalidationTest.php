<?php

namespace Tests\Feature\Hr;

use App\Domain\Hr\Jobs\RunHrScheduledReportsJob;
use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Domain\Hr\Models\HrReportExport;
use App\Domain\Hr\Models\HrReportSubscription;
use App\Domain\Hr\Notifications\HrScheduledReportReadyNotification;
use App\Domain\Hr\Services\HrReportingService;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class HrScheduledReportRecipientRevalidationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-08-31 10:00:00');
        $this->seed(RbacSeeder::class);
        Storage::fake('private');
        Notification::fake();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_scheduled_export_revalidates_canonical_recipient_ids_permission_and_staff_lifecycle(): void
    {
        $central = $this->hrUser();
        $currentStaff = $this->hrUser();
        $this->attachProfile($currentStaff);
        $revoked = $this->hrUser();
        $this->denyReports($revoked);
        $unapproved = $this->hrUser(['approved_at' => null]);
        $ended = $this->hrUser();
        $this->attachProfile($ended, ['end_date' => today()->subDay()]);
        $inactive = $this->hrUser();
        $this->attachProfile($inactive, ['is_active' => false]);
        $future = $this->hrUser();
        $this->attachProfile($future, ['start_date' => today()->addDay()]);
        $archived = $this->hrUser();
        $this->attachProfile($archived)->delete();
        $coercionTrap = $this->hrUser();
        $ordinary = $this->roleUser('support_worker');

        $subscription = $this->dueSubscription($central, [
            $central->id,
            (string) $central->id,
            $currentStaff->id,
            $revoked->id,
            $unapproved->id,
            $ended->id,
            $inactive->id,
            $future->id,
            $archived->id,
            $ordinary->id,
            '0'.(string) $coercionTrap->id,
            $coercionTrap->id.'.9',
            '1e2',
            '  '.$coercionTrap->id.'  ',
            '999999999999999999999999999999999999',
            -1,
            0,
        ]);

        (new RunHrScheduledReportsJob)->handle(app(HrReportingService::class));

        $export = HrReportExport::query()->where('subscription_id', $subscription->id)->sole();
        Notification::assertSentToTimes($central, HrScheduledReportReadyNotification::class, 1);
        Notification::assertSentToTimes($currentStaff, HrScheduledReportReadyNotification::class, 1);
        foreach ([$revoked, $unapproved, $ended, $inactive, $future, $archived, $coercionTrap, $ordinary] as $excluded) {
            Notification::assertNotSentTo($excluded, HrScheduledReportReadyNotification::class);
        }
        Notification::assertCount(2);

        Notification::assertSentTo($central, HrScheduledReportReadyNotification::class, function (
            HrScheduledReportReadyNotification $notification,
            array $channels,
        ) use ($central, $export): bool {
            $payload = $notification->toArray($central);

            return $channels === ['database', 'mail']
                && $payload['type'] === 'hr_scheduled_report_ready'
                && $payload['report_export_id'] === $export->id
                && $payload['report_type'] === 'headcount'
                && $payload['generated_at'] === now()->toIso8601String()
                && $payload['download_url'] === url("/hr/reports/exports/{$export->id}/download");
        });

        $subscription->refresh();
        expect(Storage::disk('private')->exists($export->storage_path))->toBeTrue()
            ->and($subscription->last_status)->toBe('success')
            ->and($subscription->last_run_at?->equalTo(now()))->toBeTrue()
            ->and($subscription->next_run_at)->not->toBeNull();
    }

    public function test_export_and_success_state_persist_when_every_stored_recipient_is_ineligible(): void
    {
        $creator = $this->hrUser();
        $revoked = $this->hrUser();
        $this->denyReports($revoked);
        $subscription = $this->dueSubscription($creator, [$revoked->id]);

        (new RunHrScheduledReportsJob)->handle(app(HrReportingService::class));

        $subscription->refresh();
        $export = HrReportExport::query()->where('subscription_id', $subscription->id)->sole();
        expect($subscription->last_status)->toBe('success')
            ->and($subscription->last_error)->toBeNull()
            ->and($subscription->next_run_at)->not->toBeNull()
            ->and(Storage::disk('private')->exists($export->storage_path))->toBeTrue();
        Notification::assertNothingSent();
    }

    public function test_staff_lifecycle_uses_the_worker_local_calendar_date_at_the_utc_boundary(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-31 12:30:00', 'UTC'));

        $creator = $this->hrUser();
        $locallyStarted = $this->hrUser();
        $this->attachProfile($locallyStarted, [
            'start_date' => '2026-09-01',
            'end_date' => null,
        ]);
        $locallyEnded = $this->hrUser();
        $this->attachProfile($locallyEnded, [
            'start_date' => '2025-09-01',
            'end_date' => '2026-08-31',
        ]);
        $this->dueSubscription($creator, [$locallyStarted->id, $locallyEnded->id]);

        (new RunHrScheduledReportsJob)->handle(app(HrReportingService::class));

        Notification::assertSentToTimes($locallyStarted, HrScheduledReportReadyNotification::class, 1);
        Notification::assertNotSentTo($locallyEnded, HrScheduledReportReadyNotification::class);
    }

    /** @param array<string, mixed> $overrides */
    private function hrUser(array $overrides = []): User
    {
        return $this->roleUser('hr', $overrides);
    }

    /** @param array<string, mixed> $overrides */
    private function roleUser(string $roleName, array $overrides = []): User
    {
        $user = User::factory()->create(array_merge([
            'role' => $roleName,
            'approved_at' => now(),
        ], $overrides));
        $role = Role::query()->where('name', $roleName)->sole();
        $user->roles()->syncWithoutDetaching([$role->id]);

        return $user;
    }

    /** @param array<string, mixed> $overrides */
    private function attachProfile(User $user, array $overrides = []): HrEmployeeProfile
    {
        return HrEmployeeProfile::factory()->create(array_merge([
            'user_id' => $user->id,
            'is_active' => true,
            'start_date' => today()->subYear(),
            'end_date' => null,
            'created_by' => $user->id,
            'updated_by' => $user->id,
        ], $overrides));
    }

    private function denyReports(User $user): void
    {
        $permission = Permission::query()->where('key', 'hr.reports.view')->sole();
        $user->permissionOverrides()->syncWithoutDetaching([
            $permission->id => ['allowed' => false],
        ]);
    }

    /** @param array<int, mixed> $recipientIds */
    private function dueSubscription(User $creator, array $recipientIds): HrReportSubscription
    {
        return HrReportSubscription::query()->create([
            'report_type' => 'headcount',
            'cadence' => 'daily',
            'run_at' => '08:00:00',
            'timezone' => 'Pacific/Auckland',
            'filters' => [],
            'recipient_user_ids' => $recipientIds,
            'is_active' => true,
            'next_run_at' => now()->subMinute(),
            'created_by' => $creator->id,
        ]);
    }
}
