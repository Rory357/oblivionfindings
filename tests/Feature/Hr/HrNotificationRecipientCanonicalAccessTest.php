<?php

use App\Domain\Hr\Models\HrComplianceRequirement;
use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Domain\Hr\Models\HrExpenseClaim;
use App\Domain\Hr\Models\HrStaffComplianceStatus;
use App\Domain\Hr\Notifications\ComplianceExpiryNotification;
use App\Domain\Hr\Notifications\ExpenseSubmittedNotification;
use App\Domain\Hr\Services\HrNotificationService;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Database\Seeders\SeedHrPermissionsSeeder;
use Illuminate\Support\Facades\Notification;

beforeEach(function (): void {
    $this->seed(RbacSeeder::class);
    $this->seed(SeedHrPermissionsSeeder::class);
    Notification::fake();

    $this->site = Site::factory()->create(['name' => 'Canonical notification Site']);
    $this->otherSite = Site::factory()->create(['name' => 'Hidden notification Site']);
    $this->hrRole = Role::query()->where('name', 'hr')->firstOrFail();
});

function canonicalNotificationStaff(
    string $name,
    Site $site,
    ?Role $role = null,
    array $userOverrides = [],
    array $profileOverrides = [],
): User {
    $user = User::factory()->create([
        'name' => $name,
        'role' => $role?->name ?? 'support_worker',
        'approved_at' => now(),
        ...$userOverrides,
    ]);
    if ($role) {
        $user->roles()->syncWithoutDetaching([$role->id]);
    }

    HrEmployeeProfile::factory()->create([
        'user_id' => $user->id,
        'primary_site_id' => $site->id,
        'secondary_site_ids' => [],
        'position_role' => $role?->name ?? 'support_worker',
        'start_date' => today()->subYear(),
        'end_date' => null,
        'is_active' => true,
        ...$profileOverrides,
    ]);

    return $user;
}

function canonicalNotificationStatus(User $subject): HrStaffComplianceStatus
{
    $requirement = HrComplianceRequirement::factory()->create([
        'code' => 'CANONICAL-NOTIFICATION-'.$subject->id,
        'name' => 'Canonical notification requirement',
        'check_type' => 'manual',
    ]);

    return HrStaffComplianceStatus::query()->create([
        'user_id' => $subject->id,
        'requirement_id' => $requirement->id,
        'status' => 'expiring_soon',
        'expires_at' => today()->addDays(14),
    ]);
}

function canonicalNotificationClaim(User $subject): HrExpenseClaim
{
    return HrExpenseClaim::factory()->create([
        'user_id' => $subject->id,
        'claim_number' => 'EXP-NOTIFY-'.$subject->id,
        'status' => 'submitted',
        'submitted_at' => now(),
    ]);
}

test('manager notifications require current staff exact Site visibility and effective permission', function (): void {
    $subject = canonicalNotificationStaff('Notification subject', $this->site);
    $sameSite = canonicalNotificationStaff('Same Site HR', $this->site, $this->hrRole);
    $wrongSite = canonicalNotificationStaff('Wrong Site HR', $this->otherSite, $this->hrRole);
    $former = canonicalNotificationStaff(
        'Former same Site HR',
        $this->site,
        $this->hrRole,
        [],
        ['is_active' => false, 'end_date' => today()->subDay()],
    );
    $denied = canonicalNotificationStaff('Denied same Site HR', $this->site, $this->hrRole);
    $directlyAllowed = canonicalNotificationStaff('Allowed same Site HR', $this->site);
    $unapproved = canonicalNotificationStaff(
        'Unapproved same Site HR',
        $this->site,
        $this->hrRole,
        ['approved_at' => null],
    );

    $compliancePermission = Permission::query()->where('key', 'hr.compliance.manage')->firstOrFail();
    $expensePermission = Permission::query()->where('key', 'hr.expenses.approve')->firstOrFail();
    $denied->permissionOverrides()->syncWithoutDetaching([
        $compliancePermission->id => ['allowed' => false],
        $expensePermission->id => ['allowed' => false],
    ]);
    $directlyAllowed->permissionOverrides()->syncWithoutDetaching([
        $compliancePermission->id => ['allowed' => true],
        $expensePermission->id => ['allowed' => true],
    ]);

    $service = app(HrNotificationService::class);
    $service->notifyComplianceExpiry(canonicalNotificationStatus($subject));

    Notification::assertSentToTimes($subject, ComplianceExpiryNotification::class, 1);
    Notification::assertSentToTimes($sameSite, ComplianceExpiryNotification::class, 1);
    Notification::assertSentToTimes($directlyAllowed, ComplianceExpiryNotification::class, 1);
    Notification::assertNothingSentTo($wrongSite);
    Notification::assertNothingSentTo($former);
    Notification::assertNothingSentTo($denied);
    Notification::assertNothingSentTo($unapproved);

    Notification::fake();
    $service->notifyExpenseSubmitted(canonicalNotificationClaim($subject));

    Notification::assertSentToTimes($sameSite, ExpenseSubmittedNotification::class, 1);
    Notification::assertSentToTimes($directlyAllowed, ExpenseSubmittedNotification::class, 1);
    Notification::assertNothingSentTo($subject);
    Notification::assertNothingSentTo($wrongSite);
    Notification::assertNothingSentTo($former);
    Notification::assertNothingSentTo($denied);
    Notification::assertNothingSentTo($unapproved);
});

test('former staff cannot receive or trigger compliance and expense notifications', function (): void {
    $former = canonicalNotificationStaff(
        'Former notification subject',
        $this->site,
        null,
        [],
        ['is_active' => false, 'end_date' => today()->subDay()],
    );
    $manager = canonicalNotificationStaff('Current notification manager', $this->site, $this->hrRole);
    $service = app(HrNotificationService::class);
    $claim = canonicalNotificationClaim($former);

    $service->notifyComplianceExpiry(canonicalNotificationStatus($former));
    $service->notifyExpenseSubmitted($claim);
    $service->notifyExpenseApproved($claim);
    $service->notifyExpenseRejected($claim);

    Notification::assertNothingSentTo($former);
    Notification::assertNothingSentTo($manager);
    Notification::assertNothingSent();
});
