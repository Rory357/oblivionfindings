<?php

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Domain\Hr\Notifications\ProbationReviewDueNotification;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;

beforeEach(function () {
    $this->seed(RbacSeeder::class);
});

function probationPrivacyProfile(User $user, ?Site $site, array $overrides = []): HrEmployeeProfile
{
    return HrEmployeeProfile::factory()->create(array_merge([
        'user_id' => $user->id,
        'primary_site_id' => $site?->id,
        'secondary_site_ids' => [],
        'start_date' => now()->subYear(),
        'end_date' => null,
        'is_active' => true,
    ], $overrides));
}

function probationPrivacyManager(?Site $site, array $profileOverrides = [], array $userOverrides = []): User
{
    $missingProfile = (bool) ($profileOverrides['missing_profile'] ?? false);
    unset($profileOverrides['missing_profile']);

    $user = User::factory()->create(array_merge([
        'approved_at' => now(),
        'role' => 'team_lead',
    ], $userOverrides));

    if (! $missingProfile) {
        probationPrivacyProfile($user, $site, $profileOverrides);
    }

    return $user;
}

function probationPrivacyFallback(?Site $site, array $profileOverrides = [], array $userOverrides = []): User
{
    $user = probationPrivacyManager($site, $profileOverrides, array_merge([
        'role' => 'provider_manager',
    ], $userOverrides));
    $role = Role::query()->firstOrCreate(['name' => 'provider_manager']);
    $user->roles()->syncWithoutDetaching([$role->id]);

    return $user;
}

function probationPrivacyWorker(Site $site, ?User $manager = null, array $overrides = []): HrEmployeeProfile
{
    $worker = User::factory()->create([
        'approved_at' => now(),
        'role' => 'support_worker',
    ]);

    return probationPrivacyProfile($worker, $site, array_merge([
        'manager_user_id' => $manager?->id,
        'probation_end_date' => now()->addDays(7),
        'probation_reminder_sent_at' => null,
    ], $overrides));
}

function probationPrivacyGrant(User $user, string $permissionKey): void
{
    $permission = Permission::query()->firstOrCreate(
        ['key' => $permissionKey],
        [
            'description' => $permissionKey,
            'group' => 'hr',
            'module' => 'hr',
        ],
    );
    $user->permissionOverrides()->syncWithoutDetaching([
        $permission->id => ['allowed' => true],
    ]);
}

test('probation reminders skip a remote direct manager and use a current local fallback', function () {
    Notification::fake();

    $workerSite = Site::factory()->create();
    $remoteSite = Site::factory()->create();
    $remoteManager = probationPrivacyManager($remoteSite);
    $localFallback = probationPrivacyFallback($workerSite);
    $profile = probationPrivacyWorker($workerSite, $remoteManager);

    $this->artisan('hr:probation-reminders')->assertExitCode(0);

    Notification::assertNotSentTo($remoteManager, ProbationReviewDueNotification::class);
    Notification::assertSentTo(
        $localFallback,
        ProbationReviewDueNotification::class,
        fn (ProbationReviewDueNotification $notification): bool => $notification->employeeUserId === $profile->user_id
            && $notification->probationEndDate === $profile->probation_end_date->toDateString(),
    );
    expect($profile->fresh()->probation_reminder_sent_at)->not->toBeNull();
});

test('a current direct manager at an approved secondary Site remains preferred over fallback', function () {
    Notification::fake();

    $primarySite = Site::factory()->create();
    $secondarySite = Site::factory()->create();
    $directManager = probationPrivacyManager($secondarySite);
    $fallback = probationPrivacyFallback($primarySite);
    probationPrivacyWorker($primarySite, $directManager, [
        'secondary_site_ids' => [$secondarySite->id],
    ]);

    $this->artisan('hr:probation-reminders')->assertExitCode(0);

    Notification::assertSentTo($directManager, ProbationReviewDueNotification::class);
    Notification::assertNotSentTo($fallback, ProbationReviewDueNotification::class);
});

test('invalid fallback candidates are skipped deterministically until a current local manager is found', function () {
    Notification::fake();

    $workerSite = Site::factory()->create();
    $remoteSite = Site::factory()->create();
    $invalidCandidates = [
        probationPrivacyFallback($workerSite, [], ['approved_at' => null]),
        probationPrivacyFallback($workerSite, ['end_date' => now()->subDay()]),
        probationPrivacyFallback($workerSite, ['is_active' => false]),
        probationPrivacyFallback($workerSite, ['missing_profile' => true]),
        probationPrivacyFallback($remoteSite),
    ];
    $localFallback = probationPrivacyFallback($workerSite);
    probationPrivacyWorker($workerSite);

    $this->artisan('hr:probation-reminders')->assertExitCode(0);

    foreach ($invalidCandidates as $candidate) {
        Notification::assertNotSentTo($candidate, ProbationReviewDueNotification::class);
    }
    Notification::assertSentTo($localFallback, ProbationReviewDueNotification::class);
});

test('the explicit application-wide People Site permission preserves remote fallback compatibility', function () {
    Notification::fake();

    $workerSite = Site::factory()->create();
    $remoteSite = Site::factory()->create();
    $globalFallback = probationPrivacyFallback($remoteSite);
    probationPrivacyGrant($globalFallback, 'hr.employees.viewAllSites');
    probationPrivacyWorker($workerSite);

    $this->artisan('hr:probation-reminders')->assertExitCode(0);

    Notification::assertSentTo($globalFallback, ProbationReviewDueNotification::class);
});

test('no accessible recipient sends nothing and does not consume the reminder dedupe stamp', function () {
    Notification::fake();

    $workerSite = Site::factory()->create();
    $remoteSite = Site::factory()->create();
    $remoteManager = probationPrivacyManager($remoteSite);
    $remoteFallback = probationPrivacyFallback($remoteSite);
    $profile = probationPrivacyWorker($workerSite, $remoteManager);

    $this->artisan('hr:probation-reminders')->assertExitCode(0);

    Notification::assertNotSentTo($remoteManager, ProbationReviewDueNotification::class);
    Notification::assertNotSentTo($remoteFallback, ProbationReviewDueNotification::class);
    expect($profile->fresh()->probation_reminder_sent_at)->toBeNull();
});

test('probation due timing retains the application day across the worker timezone boundary', function () {
    Notification::fake();
    config()->set('app.timezone', 'UTC');
    config()->set('app.worker_timezone', 'Pacific/Auckland');
    $this->travelTo(Carbon::parse('2026-09-01 13:30:00', 'UTC'));

    $site = Site::factory()->create();
    $manager = probationPrivacyManager($site, ['start_date' => '2026-09-02']);
    $profile = probationPrivacyWorker($site, $manager, [
        'start_date' => '2026-09-02',
        'probation_end_date' => '2026-09-16',
    ]);

    $this->artisan('hr:probation-reminders')->assertExitCode(0);

    Notification::assertNotSentTo($manager, ProbationReviewDueNotification::class);
    expect($profile->fresh()->probation_reminder_sent_at)->toBeNull();

    $this->travelTo(Carbon::parse('2026-09-02 00:00:00', 'UTC'));
    $this->artisan('hr:probation-reminders')->assertExitCode(0);

    Notification::assertSentTo($manager, ProbationReviewDueNotification::class);
    expect($profile->fresh()->probation_reminder_sent_at)->not->toBeNull();
});
