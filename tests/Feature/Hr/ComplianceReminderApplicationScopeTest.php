<?php

use App\Domain\Hr\Jobs\DeliverComplianceReminderJob;
use App\Domain\Hr\Jobs\RecoverComplianceReminderDeliveriesJob;
use App\Domain\Hr\Jobs\SendExpiryRemindersJob;
use App\Domain\Hr\Models\HrComplianceReminderDelivery;
use App\Domain\Hr\Models\HrComplianceRenewalSnooze;
use App\Domain\Hr\Models\HrComplianceRequirement;
use App\Domain\Hr\Models\HrDriverEligibility;
use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Domain\Hr\Models\HrStaffComplianceStatus;
use App\Domain\Hr\Notifications\ComplianceExpiryNotification;
use App\Domain\Hr\Notifications\WorkerComplianceExpiryNotification;
use App\Domain\Hr\Services\HrComplianceReminderDeliveryService;
use App\Models\Role;
use App\Models\Site;
use App\Models\StaffBackgroundCheck;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;

function applicationReminderStaff(
    string $name,
    Site $site,
    array $userOverrides = [],
    array $profileOverrides = [],
): User {
    $user = User::factory()->create([
        'name' => $name,
        'role' => 'support_worker',
        'approved_at' => now(),
        ...$userOverrides,
    ]);
    $profile = HrEmployeeProfile::factory()->create([
        'user_id' => $user->id,
        'employee_number' => 'EMP-REMINDER-'.$user->id,
        'work_email' => str($name)->slug().'@work.example.test',
        'position_title' => 'Support Worker',
        'position_role' => 'support_worker',
        'employment_type' => 'full_time',
        'start_date' => now()->subYear()->toDateString(),
        'end_date' => null,
        'is_active' => true,
        'primary_site_id' => $site->id,
        'secondary_site_ids' => [],
        'created_by' => $user->id,
        'updated_by' => $user->id,
        ...$profileOverrides,
    ]);
    $user->setRelation('hrEmployeeProfile', $profile);

    return $user;
}

test('application expiry job honours compliance snoozes and current-staff eligibility', function () {
    Notification::fake();
    config(['hr.expiry_reminder_days' => [30]]);

    $site = Site::factory()->create(['name' => 'Reminder Site']);
    $current = applicationReminderStaff('Current Reminder Worker', $site);
    $ended = applicationReminderStaff(
        'Ended Reminder Worker',
        $site,
        [],
        ['end_date' => now()->subDay()->toDateString()],
    );
    $requirement = HrComplianceRequirement::factory()->create([
        'code' => 'APPLICATION_REMINDER',
        'name' => 'Application reminder requirement',
        'check_type' => 'manual',
    ]);
    $currentStatus = HrStaffComplianceStatus::query()->create([
        'user_id' => $current->id,
        'requirement_id' => $requirement->id,
        'status' => 'expiring_soon',
        'expires_at' => now()->addDays(30)->toDateString(),
    ]);
    HrStaffComplianceStatus::query()->create([
        'user_id' => $ended->id,
        'requirement_id' => $requirement->id,
        'status' => 'expiring_soon',
        'expires_at' => now()->addDays(30)->toDateString(),
    ]);
    $snooze = HrComplianceRenewalSnooze::query()->create([
        'entity_type' => HrComplianceRenewalSnooze::TYPE_COMPLIANCE,
        'entity_id' => $currentStatus->id,
        'snoozed_until' => now()->addWeek(),
        'snoozed_by' => $current->id,
    ]);

    (new SendExpiryRemindersJob)->handle();

    Notification::assertNothingSentTo($current);
    Notification::assertNothingSentTo($ended);

    $snooze->update(['snoozed_until' => now()->subMinute()]);
    (new SendExpiryRemindersJob)->handle();

    Notification::assertSentToTimes($current, ComplianceExpiryNotification::class, 1);
    Notification::assertNothingSentTo($ended);
    expect(HrComplianceRenewalSnooze::query()->whereKey($snooze->id)->exists())->toBeFalse();
});

test('worker renewal command honours every snooze and stamps a current worker only once', function () {
    Notification::fake();

    $site = Site::factory()->create(['name' => 'Worker Renewal Site']);
    $current = applicationReminderStaff('Current Worker Renewal', $site);
    $ended = applicationReminderStaff(
        'Ended Worker Renewal',
        $site,
        [],
        ['end_date' => now()->subDay()->toDateString()],
    );
    $currentVetting = StaffBackgroundCheck::query()->create([
        'user_id' => $current->id,
        'check_type' => 'police_check',
        'status' => 'clear',
        'expires_at' => now()->addDays(14)->toDateString(),
        'created_by' => $current->id,
    ]);
    $currentDriver = HrDriverEligibility::query()->create([
        'user_id' => $current->id,
        'licence_number' => 'CURRENT-LICENCE',
        'licence_class' => '1',
        'licence_expires_at' => now()->addDays(14)->toDateString(),
        'status' => 'eligible',
        'created_by' => $current->id,
    ]);
    $endedVetting = StaffBackgroundCheck::query()->create([
        'user_id' => $ended->id,
        'check_type' => 'police_check',
        'status' => 'clear',
        'expires_at' => now()->addDays(14)->toDateString(),
        'created_by' => $current->id,
    ]);
    $endedDriver = HrDriverEligibility::query()->create([
        'user_id' => $ended->id,
        'licence_number' => 'ENDED-LICENCE',
        'licence_class' => '1',
        'licence_expires_at' => now()->addDays(14)->toDateString(),
        'status' => 'eligible',
        'created_by' => $current->id,
    ]);
    $vettingSnooze = HrComplianceRenewalSnooze::query()->create([
        'entity_type' => HrComplianceRenewalSnooze::TYPE_VETTING,
        'entity_id' => $currentVetting->id,
        'snoozed_until' => now()->addWeek(),
        'snoozed_by' => $current->id,
    ]);
    $driverSnooze = HrComplianceRenewalSnooze::query()->create([
        'entity_type' => HrComplianceRenewalSnooze::TYPE_DRIVER,
        'entity_id' => $currentDriver->id,
        'snoozed_until' => now()->addWeek(),
        'snoozed_by' => $current->id,
    ]);

    $this->artisan('hr:send-worker-compliance-expiry-reminders')->assertSuccessful();

    Notification::assertNothingSentTo($current);
    expect($currentVetting->fresh()->renewal_reminder_sent_at)->toBeNull()
        ->and($currentDriver->fresh()->licence_expiry_reminder_sent_at)->toBeNull();

    $vettingSnooze->update(['snoozed_until' => now()->subMinute()]);
    $driverSnooze->update(['snoozed_until' => now()->subMinute()]);
    HrComplianceRenewalSnooze::query()->create([
        'entity_type' => HrComplianceRenewalSnooze::TYPE_VETTING,
        'entity_id' => 999999,
        'snoozed_until' => now()->addWeek(),
        'snoozed_by' => $current->id,
    ]);
    HrComplianceRenewalSnooze::query()->create([
        'entity_type' => 'unsupported',
        'entity_id' => 1,
        'snoozed_until' => now()->addWeek(),
        'snoozed_by' => $current->id,
    ]);

    $this->artisan('hr:send-worker-compliance-expiry-reminders')->assertSuccessful();
    $this->artisan('hr:send-worker-compliance-expiry-reminders')->assertSuccessful();

    Notification::assertSentToTimes($current, WorkerComplianceExpiryNotification::class, 2);
    Notification::assertNothingSentTo($ended);
    expect($currentVetting->fresh()->renewal_reminder_sent_at)->not->toBeNull()
        ->and($currentDriver->fresh()->licence_expiry_reminder_sent_at)->not->toBeNull()
        ->and($endedVetting->fresh()->renewal_reminder_sent_at)->toBeNull()
        ->and($endedDriver->fresh()->licence_expiry_reminder_sent_at)->toBeNull()
        ->and(HrComplianceRenewalSnooze::query()->count())->toBe(0);
});

test('renewals csv includes safe vetting rows for visible current Site staff only', function () {
    $this->seed(RbacSeeder::class);
    $allowedSite = Site::factory()->create(['name' => 'Renewals Export Site']);
    $hiddenSite = Site::factory()->create(['name' => 'Hidden Renewals Export Site']);
    $viewer = applicationReminderStaff(
        'Renewals Export Viewer',
        $allowedSite,
        ['role' => 'hr'],
        ['position_role' => 'hr'],
    );
    $viewer->roles()->syncWithoutDetaching([
        Role::query()->where('name', 'hr')->firstOrFail()->id,
    ]);
    $visible = applicationReminderStaff('Visible Vetting Renewal', $allowedSite);
    $hidden = applicationReminderStaff('Hidden Vetting Renewal', $hiddenSite);
    $ended = applicationReminderStaff(
        'Ended Vetting Renewal',
        $allowedSite,
        [],
        ['end_date' => now()->subDay()->toDateString()],
    );

    foreach ([$visible, $hidden, $ended] as $staff) {
        StaffBackgroundCheck::query()->create([
            'user_id' => $staff->id,
            'check_type' => 'police_check',
            'provider' => 'PRIVATE PROVIDER '.$staff->id,
            'reference_number' => 'PRIVATE REFERENCE '.$staff->id,
            'disclosures_present' => true,
            'disclosure_details' => 'PRIVATE DISCLOSURE '.$staff->id,
            'status' => 'renewal_due',
            'expires_at' => now()->addMonth()->toDateString(),
            'created_by' => $viewer->id,
        ]);
    }

    $content = $this->actingAs($viewer)
        ->get('/hr/compliance/export?dataset=renewals&format=csv')
        ->assertOk()
        ->streamedContent();

    expect($content)
        ->toContain('Vetting,"Visible Vetting Renewal","Police check"')
        ->not->toContain('Hidden Vetting Renewal')
        ->not->toContain('Ended Vetting Renewal')
        ->not->toContain('PRIVATE PROVIDER')
        ->not->toContain('PRIVATE REFERENCE')
        ->not->toContain('PRIVATE DISCLOSURE');
});

test('reminder sweep contracts carry no legacy partition payload or filter semantics', function () {
    $jobSource = file_get_contents(app_path('Domain/Hr/Jobs/SendExpiryRemindersJob.php'));
    $commandSource = file_get_contents(app_path('Console/Commands/Hr/SendWorkerComplianceExpiryRemindersCommand.php'));
    $partitionWord = 'ten'.'ant';

    expect(strtolower($jobSource))->not->toContain($partitionWord)
        ->and(strtolower($commandSource))->not->toContain($partitionWord)
        ->and($jobSource)->toContain('HrCurrentStaffService')
        ->and($jobSource)->toContain('HrComplianceRenewalSnoozePruner')
        ->and($commandSource)->toContain('HrCurrentStaffService')
        ->and($commandSource)->toContain('HrComplianceRenewalSnoozePruner')
        ->and((new ReflectionClass(SendExpiryRemindersJob::class))->getConstructor())->toBeNull();
});

test('compliance expiry delivery is durably deduplicated and recoverable after queue failure', function () {
    Notification::fake();
    Queue::fake();
    config(['hr.expiry_reminder_days' => [30]]);

    $site = Site::factory()->create(['name' => 'Durable Reminder Site']);
    $worker = applicationReminderStaff('Durable Reminder Worker', $site);
    $requirement = HrComplianceRequirement::factory()->create([
        'code' => 'DURABLE_REMINDER',
        'name' => 'Durable reminder requirement',
        'check_type' => 'manual',
    ]);
    HrStaffComplianceStatus::query()->create([
        'user_id' => $worker->id,
        'requirement_id' => $requirement->id,
        'status' => 'expiring_soon',
        'expires_at' => now()->addDays(30)->toDateString(),
    ]);

    (new SendExpiryRemindersJob)->handle();
    (new SendExpiryRemindersJob)->handle();

    $delivery = HrComplianceReminderDelivery::query()->sole();
    expect($delivery->status)->toBe(HrComplianceReminderDelivery::STATUS_PENDING)
        ->and(HrComplianceReminderDelivery::query()->count())->toBe(1);
    Queue::assertPushed(DeliverComplianceReminderJob::class);

    $delivery->forceFill(['updated_at' => now()->subMinutes(10)])->saveQuietly();
    (new RecoverComplianceReminderDeliveriesJob)->handle(
        app(HrComplianceReminderDeliveryService::class),
    );
    Queue::assertPushed(DeliverComplianceReminderJob::class);

    $job = new DeliverComplianceReminderJob($delivery->id);
    $job->handle(app(HrComplianceReminderDeliveryService::class));
    $job->handle(app(HrComplianceReminderDeliveryService::class));

    Notification::assertSentToTimes($worker, ComplianceExpiryNotification::class, 1);
    expect($delivery->fresh()->status)->toBe(HrComplianceReminderDelivery::STATUS_SENT)
        ->and($delivery->fresh()->attempts)->toBe(1);
});
