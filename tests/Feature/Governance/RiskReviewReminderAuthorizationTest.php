<?php

use App\Domain\Governance\Models\RiskRegisterEntry;
use App\Domain\Governance\Notifications\RiskReviewDueNotification;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\GovernancePermissionsSeeder;
use Database\Seeders\RbacSeeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;

beforeEach(function (): void {
    $this->seed(RbacSeeder::class);
    $this->seed(GovernancePermissionsSeeder::class);
    Carbon::setTestNow(Carbon::parse('2026-08-31 07:45:00', 'Pacific/Auckland'));
});

afterEach(function (): void {
    Carbon::setTestNow();
});

function riskReviewOwner(string $role = 'admin', bool $denyView = false): User
{
    $user = User::factory()->create([
        'role' => $role,
        'approved_at' => now(),
    ]);
    $user->roles()->attach(Role::query()->where('name', $role)->firstOrFail());

    if ($denyView) {
        $view = Permission::query()->where('key', 'governance.risks.view')->firstOrFail();
        $user->permissionOverrides()->syncWithoutDetaching([
            $view->id => ['allowed' => false],
        ]);
    }

    return $user->fresh(['roles', 'permissionOverrides']);
}

function riskReviewEntry(?User $owner, array $overrides = []): RiskRegisterEntry
{
    return RiskRegisterEntry::query()->create([
        'risk_reference' => 'R-'.Str::upper(Str::random(8)),
        'category' => 'operational',
        'title' => 'Medication cold-chain interruption',
        'description' => 'Loss of monitored refrigeration could affect medicines.',
        'likelihood_score' => 3,
        'impact_score' => 4,
        'control_effectiveness' => 'moderate',
        'appetite_threshold' => 8,
        'risk_owner_id' => $owner?->id,
        'risk_committee' => 'quality_risk',
        'review_frequency' => 'quarterly',
        'next_review_date' => today()->addWeek(),
        'status' => 'active',
        'identified_at' => today()->subMonth(),
        'identified_by' => $owner?->id,
        ...$overrides,
    ]);
}

test('an authorized owner receives the exact active risk review payload within the existing window', function () {
    Notification::fake();

    $owner = riskReviewOwner();
    $due = riskReviewEntry($owner);
    riskReviewEntry($owner, [
        'title' => 'Future risk outside the reminder window',
        'next_review_date' => today()->addDays(8),
    ]);

    $this->artisan('governance:check-risk-reviews')
        ->expectsOutput('Checking for risks due for review...')
        ->expectsOutput("Notified owner for risk: {$due->risk_reference}")
        ->expectsOutput('Notified 1 of 1 active risks due for review.')
        ->assertSuccessful();

    Notification::assertSentToTimes($owner, RiskReviewDueNotification::class, 1);
    Notification::assertSentTo(
        $owner,
        RiskReviewDueNotification::class,
        fn (RiskReviewDueNotification $notification): bool => $notification->toArray($owner) === [
            'type' => 'risk_review_due',
            'risk_id' => $due->id,
            'risk_reference' => $due->risk_reference,
            'title' => 'Medication cold-chain interruption',
            'due_date' => today()->addWeek()->toDateString(),
        ],
    );
});

test('unprivileged and permission-revoked owners receive no governance risk reminder', function () {
    Notification::fake();

    $unprivileged = riskReviewOwner('support_worker');
    $permissionRevoked = riskReviewOwner('admin', denyView: true);
    riskReviewEntry($unprivileged, ['title' => 'Restricted worker-owned risk']);
    riskReviewEntry($permissionRevoked, ['title' => 'Permission-revoked owner risk']);

    $this->artisan('governance:check-risk-reviews')
        ->expectsOutput('Notified 0 of 2 active risks due for review.')
        ->assertSuccessful();

    Notification::assertNotSentTo($unprivileged, RiskReviewDueNotification::class);
    Notification::assertNotSentTo($permissionRevoked, RiskReviewDueNotification::class);
});

test('non-active and ownerless risk entries do not produce review notifications', function () {
    Notification::fake();

    $owner = riskReviewOwner();
    $identifier = riskReviewOwner();
    riskReviewEntry($owner, ['status' => 'accepted', 'title' => 'Accepted risk']);
    riskReviewEntry($owner, ['status' => 'voided', 'title' => 'Voided risk']);
    riskReviewEntry(null, [
        'title' => 'Ownerless active risk',
        'identified_by' => $identifier->id,
    ]);

    $this->artisan('governance:check-risk-reviews')
        ->expectsOutput('Notified 0 of 1 active risks due for review.')
        ->assertSuccessful();

    Notification::assertNotSentTo($owner, RiskReviewDueNotification::class);
});
